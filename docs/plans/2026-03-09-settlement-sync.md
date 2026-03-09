# eWAY Settlement Sync Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a daily cron job that queries the eWAY Settlement Search API and reconciles `fee_amount` and `net_amount` on Completed CiviCRM contributions, eliminating manual finance reconciliation work.

**Architecture:** A new `CRM_eWAYRecurring_SettlementSync` class contains all reconciliation logic with an injectable HTTP client for testability. A new `EwaySettlement.sync` API v3 endpoint (created via civix) provides the cron entry point. A managed scheduled job (Daily frequency, created by civix) drives execution. The job iterates all active live eWAY payment processors, fetches settlement data for the configured lookback window from the eWAY API, and updates contributions whose `fee_amount` is still `0.00`.

**Tech Stack:** PHP 8.0+, CiviCRM API v4 (`Civi\Api4`), GuzzleHttp 7 (available as transitive dep of `eway/eway-rapid-php ^2.0`), PHPUnit (align with extension conventions; see Task 2).

---

## Background: eWAY Settlement Search API

- **Production endpoint:** `https://api.ewaypayments.com/Search/Settlement`
- **Sandbox endpoint:** `https://api.sandbox.ewaypayments.com/Search/Settlement`
- **Auth:** HTTP Basic Auth — API Key as username, API Password as password (same credentials as the payment processor configuration stores in `user_name` and `password` fields).
- **Method:** GET
- **Key request parameters:** Use `StartDate` and `EndDate` for the date range (format `YYYY-MM-DD`). When both are provided, eWAY ignores any `SettlementDate` value.
  - `ReportMode`: `TransactionOnly` (use this exact value)
  - `StartDate`: `YYYY-MM-DD` — start of range
  - `EndDate`: `YYYY-MM-DD` — end of range
  - `Page`: integer, 1-indexed
  - `PageSize`: integer (use 200)
- **Response fields we care about** (from `SettlementTransactions` array):
  - `TransactionID` (integer) — matches `civicrm_contribution.trxn_id`
  - `FeePerTransaction` (integer, in **cents**)
  - `Amount` (integer, in **cents**)
  - `SettlementDateTime` (Microsoft JSON date format, e.g. `\/Date(1422795600000)\/`)
- **Pagination:** Assume paginated. Fetch pages until a page returns fewer than `PageSize` results.
- **Duplicate TransactionID:** If the same TransactionID appears multiple times in the response, the last occurrence wins (overwrites in the lookup map). This is considered acceptable; duplicates are not expected.

## Background: CiviCRM Data Model

- `civicrm_contribution.trxn_id` — stores the eWAY `TransactionID` as a string.
- `civicrm_contribution.fee_amount` — decimal(20,2), defaults to `0.00`. This is our "not yet reconciled" signal.
- `civicrm_contribution.net_amount` — decimal(20,2). Should equal `total_amount - fee_amount` after reconciliation.
- `civicrm_contribution.total_amount` — decimal(20,2), stored in dollars (not cents).
- All eWAY API amounts are in **cents**; divide by 100 for CiviCRM storage.
- Payment processor credentials: `civicrm_payment_processor.user_name` = API Key, `.password` = API Password, `.is_test` = 0 for live.

## Background: Error Handling Patterns

Reference: `CRM/eWAYRecurring/ProcessTrait.php` and `CRM/Core/Payment/eWAYRecurring.php`.

- **eWAY response errors:** Check the `Errors` field in the JSON response (e.g. "data will be available in 60 mins"). If non-empty, log and skip that processor's batch.
- **HTTP errors:** Guzzle throws `GuzzleHttp\Exception\RequestException` on 4xx/5xx. Catch, log with `Civi::log()->warning()`, and continue to next processor (do not fail the entire job).
- **Missing FeePerTransaction:** If a settlement transaction lacks `FeePerTransaction`, skip that transaction (do not reconcile).
- **Logging:** Use `Civi::log()->warning()` or `Civi::log()->error()` for failures. Include processor id and exception message.

---

## Task 1: Add the lookback setting

> **Developer note:** No civix or other tooling required; manual edit only.

**Files:**
- Modify: `settings/eWAYRecurring.setting.php`
- Modify: `CRM/eWAYRecurring/Form/Settings.php`

**Step 1: Add setting to the returned array**

Add this entry after the existing `eway_recurring_keep_sending_receipts` key:

```php
  'eway_settlement_sync_lookback_days' => [
    'group_name' => 'eWay Recurring Settings',
    'group' => 'eWAYRecurring',
    'name' => 'eway_settlement_sync_lookback_days',
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => '5',
    'description' => 'Number of days for settlement sync: used for both (a) querying unreconciled contributions and (b) the eWAY Settlement API date range. Most settlement data is available after ~3 business days; 5 days provides a buffer.',
    'title' => 'Settlement Sync: Lookback Days',
    'help_text' => 'Number of days for settlement sync. Used for both contribution lookback and eWAY API date range.',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 10,
    ],
    'quick_form_type' => 'Element',
  ],
```

**Step 2: Verify it loads**

```bash
cd /home/johntwyman/dev/au.com.agileware.ewayrecurring
cv ev "echo Civi::settings()->get('eway_settlement_sync_lookback_days');"
```

Expected output: `5`

**Step 2b: Add validation in Settings form**

Modify `CRM/eWAYRecurring/Form/Settings.php` — in `validate()`, add:

```php
    if (isset($submittedValues['eway_settlement_sync_lookback_days'])) {
      $val = (int) $submittedValues['eway_settlement_sync_lookback_days'];
      if ($val < 1 || $val > 90) {
        $this->_errors['eway_settlement_sync_lookback_days'] = 'Lookback days must be between 1 and 90';
      }
    }
```

**Step 3: Commit**

```bash
git add settings/eWAYRecurring.setting.php CRM/eWAYRecurring/Form/Settings.php
git commit -m "feat: add eway_settlement_sync_lookback_days setting"
```

---

## Task 2: Create SettlementSync class skeleton and test file

> **Developer note:** No civix required. Create files manually. For tests: align with extension conventions. `MyTest.php` uses `CiviUnitTestCase` + headless; these SettlementSync tests need real CiviCRM entities (Contribution, PaymentProcessor), so use the same pattern. If the extension prefers `CRM_EwayRecurring_TestCase`, use that instead.

**Files:**
- Create: `CRM/eWAYRecurring/SettlementSync.php`
- Create: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Create the class skeleton**

Create `CRM/eWAYRecurring/SettlementSync.php`:

```php
<?php

use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;

/**
 * Reconciles eWAY settlement data against CiviCRM contribution records.
 *
 * Queries the eWAY Settlement Search API for recent settlement transactions
 * and updates fee_amount and net_amount on matching Completed contributions.
 */
class CRM_eWAYRecurring_SettlementSync {

  const SETTLEMENT_URL_PRODUCTION = 'https://api.ewaypayments.com/Search/Settlement';
  const SETTLEMENT_URL_SANDBOX = 'https://api.sandbox.ewaypayments.com/Search/Settlement';
  const PAGE_SIZE = 200;

  // Settlement window = lookback setting (single config drives both contribution query and API date range)

  /**
   * @var \GuzzleHttp\Client
   */
  private \GuzzleHttp\Client $httpClient;

  public function __construct(\GuzzleHttp\Client $httpClient = NULL) {
    $this->httpClient = $httpClient ?? new \GuzzleHttp\Client();
  }

  /**
   * Main entry point. Syncs settlement data for all active live eWAY processors.
   */
  public function sync(): void {
    // Implemented in Task 6.
  }

}
```

**Step 2: Create the test file skeleton**

Create `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`:

```php
<?php

use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for CRM_eWAYRecurring_SettlementSync.
 *
 * @group headless
 */
class CRM_EwayRecurring_SettlementSyncTest extends CiviUnitTestCase {

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->useTransaction(TRUE);
    parent::setUp();
  }

  // Tests are added in subsequent tasks.

}
```

**Step 3: Run the test file to confirm it's loadable**

> **Developer note:** Run tests from a CiviCRM environment (e.g. `cv php:boot` or project phpunit config). Documentation of how to run tests is outside this plan's scope.

```bash
cd /home/johntwyman/dev/au.com.agileware.ewayrecurring
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
```

Expected: No tests found, but no errors (0 failures, 0 errors).

**Step 4: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: add SettlementSync class skeleton and test file"
```

---

## Task 3: Implement getLiveEwayProcessors()

> **Developer note:** Manual implementation; no tooling required.

**Files:**
- Modify: `CRM/eWAYRecurring/SettlementSync.php`
- Modify: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Write the failing test**

Add to `SettlementSyncTest`:

```php
  /**
   * Helper: create a live eWAY payment processor for tests.
   */
  private function createEwayProcessor(bool $isTest = FALSE): int {
    $typeId = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->addSelect('id')
      ->execute()
      ->first()['id'];

    return PaymentProcessor::create(FALSE)
      ->addValue('payment_processor_type_id', $typeId)
      ->addValue('name', 'Test eWAY Processor')
      ->addValue('title', 'Test eWAY Processor')
      ->addValue('user_name', 'test-api-key')
      ->addValue('password', 'test-api-password')
      ->addValue('is_test', $isTest ? 1 : 0)
      ->addValue('is_active', 1)
      ->addValue('domain_id', 1)
      ->execute()
      ->first()['id'];
  }

  public function testGetLiveEwayProcessorsReturnsOnlyLiveProcessors(): void {
    $liveId = $this->createEwayProcessor(FALSE);
    $testId = $this->createEwayProcessor(TRUE);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getLiveEwayProcessors();

    $ids = array_column($result, 'id');
    $this->assertContains($liveId, $ids, 'Live processor should be returned');
    $this->assertNotContains($testId, $ids, 'Test processor should not be returned');
  }

  public function testGetLiveEwayProcessorsReturnsCredentials(): void {
    $this->createEwayProcessor(FALSE);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getLiveEwayProcessors();

    $this->assertNotEmpty($result);
    $processor = $result[0];
    $this->assertArrayHasKey('user_name', $processor);
    $this->assertArrayHasKey('password', $processor);
    $this->assertArrayHasKey('is_test', $processor);
  }
```

**Step 2: Run test to confirm it fails**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testGetLiveEwayProcessors
```

Expected: FAIL — "Call to undefined method CRM_eWAYRecurring_SettlementSync::getLiveEwayProcessors()"

**Step 3: Implement getLiveEwayProcessors()**

Add to `CRM/eWAYRecurring/SettlementSync.php`:

```php
  /**
   * Returns all active, non-test eWAY payment processors.
   *
   * @return array Array of processor records with id, user_name, password, is_test.
   */
  public function getLiveEwayProcessors(): array {
    return PaymentProcessor::get(FALSE)
      ->addSelect('id', 'user_name', 'password', 'is_test')
      ->addWhere('payment_processor_type_id:name', '=', 'eWay_Recurring')
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->getArrayCopy();
  }
```

**Step 4: Run test to confirm it passes**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testGetLiveEwayProcessors
```

Expected: PASS

**Step 5: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: implement SettlementSync::getLiveEwayProcessors()"
```

---

## Task 4: Implement getUnreconciledContributions()

> **Developer note:** Manual implementation; no tooling required.

**Files:**
- Modify: `CRM/eWAYRecurring/SettlementSync.php`
- Modify: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Write the failing tests**

Add to `SettlementSyncTest`:

```php
  /**
   * Helper: create a Completed contribution with optional fee_amount.
   */
  private function createCompletedEwayContribution(
    int $processorId,
    string $trxnId,
    float $totalAmount = 100.00,
    float $feeAmount = 0.00,
    string $receiveDate = 'now'
  ): int {
    $contactId = $this->individualCreate();
    $date = date('Y-m-d H:i:s', strtotime($receiveDate));

    return Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', $totalAmount)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $totalAmount - $feeAmount)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('payment_processor_id', $processorId)
      ->addValue('trxn_id', $trxnId)
      ->addValue('receive_date', $date)
      ->execute()
      ->first()['id'];
  }

  public function testGetUnreconciledContributionsReturnsUnreconciledOnly(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $unreconciledId = $this->createCompletedEwayContribution($processorId, 'TXN001', 100.00, 0.00);
    $reconciledId = $this->createCompletedEwayContribution($processorId, 'TXN002', 100.00, 0.55);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($processorId);

    $ids = array_column($result, 'id');
    $this->assertContains($unreconciledId, $ids, 'Unreconciled contribution should be returned');
    $this->assertNotContains($reconciledId, $ids, 'Already reconciled contribution should not be returned');
  }

  public function testGetUnreconciledContributionsRespectsLookbackWindow(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $recentId = $this->createCompletedEwayContribution($processorId, 'TXN003', 100.00, 0.00, '-3 days');
    $oldId = $this->createCompletedEwayContribution($processorId, 'TXN004', 100.00, 0.00, '-10 days');

    $sync = new CRM_eWAYRecurring_SettlementSync();
    // Default lookback is 5 days.
    $result = $sync->getUnreconciledContributions($processorId);

    $ids = array_column($result, 'id');
    $this->assertContains($recentId, $ids, 'Recent contribution should be returned');
    $this->assertNotContains($oldId, $ids, 'Old contribution should not be returned');
  }

  public function testGetUnreconciledContributionsSkipsOtherProcessors(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $otherProcessorId = $this->createEwayProcessor(FALSE);
    $myContributionId = $this->createCompletedEwayContribution($processorId, 'TXN005');
    $otherContributionId = $this->createCompletedEwayContribution($otherProcessorId, 'TXN006');

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($processorId);

    $ids = array_column($result, 'id');
    $this->assertContains($myContributionId, $ids);
    $this->assertNotContains($otherContributionId, $ids);
  }
```

**Step 2: Run tests to confirm they fail**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testGetUnreconciledContributions
```

Expected: FAIL — method does not exist.

**Step 3: Implement getUnreconciledContributions()**

Add to `CRM/eWAYRecurring/SettlementSync.php`:

```php
  /**
   * Returns Completed eWAY contributions for a processor that have not been
   * reconciled (fee_amount = 0.00) within the lookback window.
   *
   * @param int $processorId
   * @return array Array of contribution records.
   */
  public function getUnreconciledContributions(int $processorId): array {
    $lookbackDays = (int) Civi::settings()->get('eway_settlement_sync_lookback_days') ?: 5;
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));

    return Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'total_amount', 'receive_date', 'payment_processor_id')
      ->addWhere('payment_processor_id', '=', $processorId)
      ->addWhere('contribution_status_id:name', '=', 'Completed')
      ->addWhere('fee_amount', '=', 0)
      ->addWhere('receive_date', '>=', $cutoffDate)
      ->addWhere('trxn_id', 'IS NOT NULL')
      ->addWhere('trxn_id', '!=', '')
      ->execute()
      ->getArrayCopy();
  }
```

**Step 4: Run tests to confirm they pass**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testGetUnreconciledContributions
```

Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: implement SettlementSync::getUnreconciledContributions()"
```

---

## Task 5: Implement reconcileContribution()

> **Developer note:** Manual implementation; no tooling required.

**Files:**
- Modify: `CRM/eWAYRecurring/SettlementSync.php`
- Modify: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Write the failing tests**

Add to `SettlementSyncTest`:

```php
  public function testReconcileContributionSetsFeeAndNetAmount(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN010', 100.00);

    $settlementData = [
      'TransactionID' => 12345,
      'FeePerTransaction' => 55,  // 55 cents in eWAY API
      'Amount' => 10000,
    ];

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('id', 'total_amount')
      ->execute()
      ->first();

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->reconcileContribution($contribution, $settlementData);

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount', 'total_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $updated['fee_amount'], 'fee_amount should be set to FeePerTransaction in dollars');
    $this->assertEquals(99.45, $updated['net_amount'], 'net_amount should be total_amount minus fee_amount');
    $this->assertEquals(100.00, $updated['total_amount'], 'total_amount should not be changed');
  }

  public function testReconcileContributionRoundsToTwoDp(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN011', 50.00);

    // 33 cents — deliberately not a clean decimal
    $settlementData = ['TransactionID' => 12346, 'FeePerTransaction' => 33, 'Amount' => 5000];
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('id', 'total_amount')
      ->execute()
      ->first();

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->reconcileContribution($contribution, $settlementData);

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.33, $updated['fee_amount']);
    $this->assertEquals(49.67, $updated['net_amount']);
  }
```

**Step 2: Run tests to confirm they fail**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testReconcileContribution
```

Expected: FAIL

**Step 3: Implement reconcileContribution()**

Add to `CRM/eWAYRecurring/SettlementSync.php`:

```php
  /**
   * Updates fee_amount and net_amount on a contribution from eWAY settlement data.
   *
   * @param array $contribution Contribution record with id and total_amount.
   * @param array $settlementData Settlement transaction from eWAY API.
   *   Must include FeePerTransaction (integer, in cents). Caller should skip if missing.
   */
  public function reconcileContribution(array $contribution, array $settlementData): void {
    $feeAmount = round($settlementData['FeePerTransaction'] / 100, 2);
    $netAmount = round((float) $contribution['total_amount'] - $feeAmount, 2);

    Contribution::update(FALSE)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $netAmount)
      ->addWhere('id', '=', $contribution['id'])
      ->execute();
  }
```

**Step 4: Run tests to confirm they pass**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testReconcileContribution
```

Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: implement SettlementSync::reconcileContribution()"
```

---

## Task 6: Implement fetchAllSettlementTransactions() with mocked HTTP

> **Developer note:** Manual implementation; no tooling required.

**Files:**
- Modify: `CRM/eWAYRecurring/SettlementSync.php`
- Modify: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Write the failing tests**

Note: these tests use Guzzle's `MockHandler` to simulate API responses without hitting the real eWAY API.

Add to `SettlementSyncTest`:

```php
  /**
   * Build a SettlementSync with a mocked HTTP client.
   *
   * @param array $responses Array of GuzzleHttp\Psr7\Response objects.
   */
  private function syncWithMockedHttp(array $responses): CRM_eWAYRecurring_SettlementSync {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new \GuzzleHttp\Client(['handler' => $handlerStack]);
    return new CRM_eWAYRecurring_SettlementSync($client);
  }

  private function makeSettlementResponse(array $transactions): Response {
    $body = json_encode([
      'SettlementTransactions' => $transactions,
      'Errors' => '',
    ]);
    return new Response(200, ['Content-Type' => 'application/json'], $body);
  }

  public function testFetchAllSettlementTransactionsSinglePage(): void {
    $transactions = [
      ['TransactionID' => 111, 'FeePerTransaction' => 50, 'Amount' => 1000],
      ['TransactionID' => 222, 'FeePerTransaction' => 75, 'Amount' => 2000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($transactions),
      $this->makeSettlementResponse([]),  // empty page signals end of pagination
    ]);

    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];
    $result = $sync->fetchAllSettlementTransactions($processor);

    $this->assertCount(2, $result);
    $this->assertEquals(111, $result[0]['TransactionID']);
    $this->assertEquals(222, $result[1]['TransactionID']);
  }

  public function testFetchAllSettlementTransactionsMultiplePages(): void {
    // Simulate a full page (200 items) followed by a partial page (1 item).
    $fullPage = array_fill(0, CRM_eWAYRecurring_SettlementSync::PAGE_SIZE, ['TransactionID' => 1, 'FeePerTransaction' => 50, 'Amount' => 1000]);
    $lastPage = [['TransactionID' => 999, 'FeePerTransaction' => 30, 'Amount' => 500]];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($fullPage),
      $this->makeSettlementResponse($lastPage),
    ]);

    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];
    $result = $sync->fetchAllSettlementTransactions($processor);

    $this->assertCount(CRM_eWAYRecurring_SettlementSync::PAGE_SIZE + 1, $result);
  }

  public function testFetchAllSettlementTransactionsUsesSandboxUrl(): void {
    // Confirm that a test processor routes to the sandbox URL.
    // We capture the request URL via Guzzle history middleware.
    $container = [];
    $history = \GuzzleHttp\Middleware::history($container);
    $mock = new MockHandler([$this->makeSettlementResponse([]), $this->makeSettlementResponse([])]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $client = new \GuzzleHttp\Client(['handler' => $handlerStack]);

    $sync = new CRM_eWAYRecurring_SettlementSync($client);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => TRUE];
    $sync->fetchAllSettlementTransactions($processor);

    $requestUrl = (string) $container[0]['request']->getUri();
    $this->assertStringStartsWith(CRM_eWAYRecurring_SettlementSync::SETTLEMENT_URL_SANDBOX, $requestUrl);
  }
```

**Step 2: Run tests to confirm they fail**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testFetchAll
```

Expected: FAIL

**Step 3: Implement fetchAllSettlementTransactions() and fetchSettlementPage()**

Add to `CRM/eWAYRecurring/SettlementSync.php`:

```php
  /**
   * Fetches all settlement transactions for a processor over the lookback window
   * (same as eway_settlement_sync_lookback_days), handling pagination automatically.
   *
   * @param array $processor Processor record with user_name, password, is_test.
   * @return array Flat array of settlement transaction records.
   */
  public function fetchAllSettlementTransactions(array $processor): array {
    $all = [];
    $page = 1;

    do {
      $response = $this->fetchSettlementPage($processor, $page);
      $transactions = $response['SettlementTransactions'] ?? [];
      $all = array_merge($all, $transactions);
      $page++;
    } while (count($transactions) >= self::PAGE_SIZE);

    return $all;
  }

  /**
   * Fetches a single page of settlement data from the eWAY API.
   *
   * @param array $processor
   * @param int $page 1-indexed page number.
   * @return array Decoded response body.
   */
  private function fetchSettlementPage(array $processor, int $page): array {
    $baseUrl = $processor['is_test']
      ? self::SETTLEMENT_URL_SANDBOX
      : self::SETTLEMENT_URL_PRODUCTION;

    $lookbackDays = (int) Civi::settings()->get('eway_settlement_sync_lookback_days') ?: 5;
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime("-{$lookbackDays} days"));
    $response = $this->httpClient->get($baseUrl, [
      'auth' => [$processor['user_name'], $processor['password']],
      'query' => [
        'ReportMode' => 'TransactionOnly',
        'StartDate' => $startDate,
        'EndDate' => $endDate,
        'Page' => $page,
        'PageSize' => self::PAGE_SIZE,
      ],
    ]);

    $body = json_decode($response->getBody()->getContents(), TRUE) ?? [];
    if (!empty($body['Errors'])) {
      throw new \RuntimeException('eWAY Settlement API error: ' . $body['Errors']);
    }
    return $body;
  }
```

**Error handling:** Guzzle throws `RequestException` on HTTP errors; the `Errors` field in the JSON indicates API-level issues. Both bubble up to `sync()`, which catches and logs per-processor, then continues to the next processor.

**Step 4: Run tests to confirm they pass**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testFetchAll
```

Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: implement SettlementSync::fetchAllSettlementTransactions()"
```

---

## Task 7: Implement sync() — the main orchestration method

> **Developer note:** Manual implementation; no tooling required.

**Files:**
- Modify: `CRM/eWAYRecurring/SettlementSync.php`
- Modify: `tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php`

**Step 1: Write the failing integration test**

This test wires everything together using a mocked HTTP client and a real CiviCRM database.

Add to `SettlementSyncTest`:

```php
  public function testSyncReconcileMatchingContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);

    // Contribution with trxn_id 11111, not yet reconciled.
    $contributionId = $this->createCompletedEwayContribution($processorId, '11111', 100.00);

    $settlementTransactions = [
      ['TransactionID' => 11111, 'FeePerTransaction' => 55, 'Amount' => 10000],
      ['TransactionID' => 99999, 'FeePerTransaction' => 30, 'Amount' => 5000],  // no matching contribution
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),  // pagination terminator
    ]);

    $sync->sync();

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $updated['fee_amount']);
    $this->assertEquals(99.45, $updated['net_amount']);
  }

  public function testSyncSkipsAlreadyReconciledContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, '22222', 100.00, 0.55);

    $settlementTransactions = [
      ['TransactionID' => 22222, 'FeePerTransaction' => 99, 'Amount' => 10000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),
    ]);

    $sync->sync();

    // fee_amount should remain at original 0.55, not overwritten with 0.99.
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $contribution['fee_amount']);
  }
```

**Step 2: Run tests to confirm they fail**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testSync
```

Expected: FAIL

**Step 3: Implement sync()**

Replace the stub `sync()` method in `CRM/eWAYRecurring/SettlementSync.php`:

```php
  /**
   * Main entry point. For each live eWAY processor, fetches settlement data
   * and reconciles matching unreconciled contributions.
   */
  public function sync(): void {
    $processors = $this->getLiveEwayProcessors();

    foreach ($processors as $processor) {
      try {
        $contributions = $this->getUnreconciledContributions($processor['id']);
        if (empty($contributions)) {
          continue;
        }

        $settlementTransactions = $this->fetchAllSettlementTransactions($processor);
        if (empty($settlementTransactions)) {
          continue;
        }

        // Build lookup map: string TransactionID => settlement data.
        $settlementMap = [];
        foreach ($settlementTransactions as $txn) {
          $settlementMap[(string) $txn['TransactionID']] = $txn;
        }

        foreach ($contributions as $contribution) {
          $trxnId = (string) $contribution['trxn_id'];
          if (isset($settlementMap[$trxnId])) {
            $txn = $settlementMap[$trxnId];
            if (isset($txn['FeePerTransaction'])) {
              $this->reconcileContribution($contribution, $txn);
            }
          }
        }
      }
      catch (\Exception $e) {
        Civi::log()->warning('eWAY Settlement Sync failed for processor {id}: {msg}', [
          'id' => $processor['id'],
          'msg' => $e->getMessage(),
          'exception' => $e,
        ]);
        // Continue to next processor
      }
    }
  }
```

**Step 4: Run tests to confirm they pass**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php --filter testSync
```

Expected: PASS (2 tests)

**Step 5: Run full test suite to confirm no regressions**

```bash
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
```

Expected: All tests PASS

**Step 6: Commit**

```bash
git add CRM/eWAYRecurring/SettlementSync.php tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php
git commit -m "feat: implement SettlementSync::sync() orchestration method"
```

---

## Task 8: Create API v3 endpoint and scheduled job via civix

> **Developer interaction required:** Run `civix generate:api` from the extension directory. This requires a CiviCRM environment (civicrm.settings.php must be locatable). Run from a CiviCRM site root or set `CIVICRM_SETTINGS` if needed.

**Step 1: Generate the API and scheduled job**

From the extension root (with CiviCRM bootstrapped):

```bash
cd /path/to/au.com.agileware.ewayrecurring
civix generate:api --schedule Daily EwaySettlement sync
```

This creates:
- `api/v3/EwaySettlement.php` — API implementation
- A managed Job entity (typically in `managed/` or via hook_civicrm_managed) for the Daily schedule

**Step 2: Customize the generated API file**

Replace the generated stub in `api/v3/EwaySettlement.php` with the actual implementation:

```php
<?php

/**
 * EwaySettlement.sync API
 *
 * Queries the eWAY Settlement Search API and reconciles fee_amount and
 * net_amount on Completed contributions that have not yet been reconciled.
 *
 * This is invoked by the "eWay Settlement Sync" scheduled job.
 *
 * @param array $params
 * @return array API result descriptor.
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 */
function civicrm_api3_eway_settlement_sync(array $params): array {
  try {
    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->sync();
    return civicrm_api3_create_success([], $params, 'EwaySettlement', 'sync');
  }
  catch (Exception $e) {
    Civi::log()->error('eWAY Settlement Sync failed: ' . $e->getMessage(), [
      'exception' => $e,
    ]);
    return civicrm_api3_create_error($e->getMessage());
  }
}

/**
 * EwaySettlement.sync API spec.
 *
 * @param array $spec
 */
function _civicrm_api3_eway_settlement_sync_spec(array &$spec): void {
  // No parameters required.
}
```

**Step 3: Verify the API and job**

```bash
cv api3 EwaySettlement.sync
```

Expected: `{"is_error":0,"version":3,...}` (may log errors if no live processors configured, but should not crash).

```bash
cv api3 Job.get name="eWay Settlement Sync"
```

Expected: Returns the job record with `run_frequency: Daily`. If not found, run `cv api3 System.flush` and retry.

**Step 4: Commit**

```bash
git add api/v3/EwaySettlement.php managed/
git commit -m "feat: add EwaySettlement.sync API and Daily scheduled job (via civix)"
```

> **Note:** If civix places the Job in `eWAYRecurring.php` hook_civicrm_managed instead of a `.mgd.php` file, add that file to the commit instead of `managed/`.

---

## Task 9: Final verification

**Step 1: Run the complete test suite**

```bash
cd /home/johntwyman/dev/au.com.agileware.ewayrecurring
phpunit tests/phpunit/CRM/EwayRecurring/SettlementSyncTest.php -v
```

Expected: All tests PASS, no errors.

**Step 2: Check git log**

```bash
git log --oneline feature/settlement-sync ^upstream/master
```

Expected: 8 clean commits (Tasks 1–8).

**Step 3: Push and raise PR**

```bash
git push origin feature/settlement-sync
```

Then open a PR against the upstream `master` branch at https://github.com/agileware/au.com.agileware.ewayrecurring.

PR description should note:
- What the feature does and why (finance reconciliation automation)
- The dependency on the eWAY Settlement Search API
- That test-mode processors are excluded
- The configurable lookback window setting
