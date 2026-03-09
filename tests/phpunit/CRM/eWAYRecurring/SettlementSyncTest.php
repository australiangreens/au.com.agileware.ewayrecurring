<?php
declare(strict_types = 1);

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for CRM_eWAYRecurring_SettlementSync.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SettlementSyncTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Create an eWAY payment processor for tests.
   *
   * Uses a static counter to ensure unique names within the test run
   * (payment_processor has a domain_id + name uniqueness constraint).
   */
  private function createEwayProcessor(bool $isTest = FALSE): int {
    static $counter = 0;
    $counter++;

    $typeId = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->addSelect('id')
      ->execute()
      ->first()['id'];

    return PaymentProcessor::create(FALSE)
      ->addValue('payment_processor_type_id', $typeId)
      ->addValue('name', 'Test eWAY Processor ' . $counter)
      ->addValue('title', 'Test eWAY Processor ' . $counter)
      ->addValue('user_name', 'test-api-key')
      ->addValue('password', 'test-api-password')
      ->addValue('is_test', $isTest ? 1 : 0)
      ->addValue('is_active', 1)
      ->addValue('domain_id', \CRM_Core_Config::domainID())
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a Completed eWAY contribution for tests.
   */
  private function createCompletedEwayContribution(
    int $processorId,
    string $trxnId,
    float $totalAmount = 100.00,
    float $feeAmount = 0.00,
    string $receiveDate = 'now'
  ): int {
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Contributor')
      ->execute()
      ->first()['id'];

    $date = date('Y-m-d H:i:s', strtotime($receiveDate));

    $contributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', $totalAmount)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $totalAmount - $feeAmount)
      ->addValue('contribution_status_id', 2)
      ->addValue('trxn_id', $trxnId)
      ->addValue('receive_date', $date)
      ->execute()
      ->first()['id'];

    // Explicitly create a FinancialTrxn + EntityFinancialTrxn with payment_processor_id
    // set so the EntityFinancialTrxn bridge join in getUnreconciledContributions() works.
    // The headless API does not auto-create these records for direct Contribution::create() calls.
    $this->linkPaymentProcessorToContribution($contributionId, $processorId, $totalAmount, $date);

    return $contributionId;
  }

  /**
   * Create a FinancialTrxn with payment_processor_id set and link it to a contribution
   * via EntityFinancialTrxn, simulating what the eWAY payment processor creates.
   *
   * Uses API v3 because entity_table is not exposed in the EntityFinancialTrxn API v4 entity.
   */
  private function linkPaymentProcessorToContribution(int $contributionId, int $processorId, float $amount, string $date): void {
    $toAccountId = (int) civicrm_api3('FinancialAccount', 'getvalue', [
      'return' => 'id',
      'is_active' => 1,
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);

    // Passing entity_id + entity_table to FinancialTrxn.create causes CiviCRM
    // to automatically create the EntityFinancialTrxn link record.
    civicrm_api3('FinancialTrxn', 'create', [
      'payment_processor_id' => $processorId,
      'to_financial_account_id' => $toAccountId,
      'trxn_date' => $date,
      'total_amount' => $amount,
      'net_amount' => $amount,
      'fee_amount' => 0,
      'status_id' => 1,
      'payment_instrument_id' => 1,
      'entity_id' => $contributionId,
      'entity_table' => 'civicrm_contribution',
    ]);
  }

  /**
   * Build a SettlementSync with a mocked HTTP client.
   *
   * @param Response[] $responses Guzzle responses to return in sequence.
   */
  private function syncWithMockedHttp(array $responses): CRM_eWAYRecurring_SettlementSync {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new \GuzzleHttp\Client(['handler' => $handlerStack]);
    return new CRM_eWAYRecurring_SettlementSync($client);
  }

  /**
   * Build a Guzzle response representing a settlement search result page.
   */
  private function makeSettlementResponse(array $transactions): Response {
    $body = json_encode([
      'SettlementTransactions' => $transactions,
      'Errors' => '',
    ]);
    return new Response(200, ['Content-Type' => 'application/json'], $body);
  }

  // ---------------------------------------------------------------------------
  // Task 3: getLiveEwayProcessors()
  // ---------------------------------------------------------------------------

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
    $createdId = $this->createEwayProcessor(FALSE);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getLiveEwayProcessors();

    // Find the processor we just created (there may be others in the DB).
    $processor = NULL;
    foreach ($result as $p) {
      if ($p['id'] === $createdId) {
        $processor = $p;
        break;
      }
    }

    $this->assertNotNull($processor, 'Created processor should be in results');
    $this->assertArrayHasKey('user_name', $processor);
    $this->assertArrayHasKey('password', $processor);
    $this->assertArrayHasKey('is_test', $processor);
  }

  // ---------------------------------------------------------------------------
  // Task 4: getUnreconciledContributions()
  // ---------------------------------------------------------------------------

  public function testGetUnreconciledContributionsReturnsUnreconciledOnly(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $unreconciledId = $this->createCompletedEwayContribution($processorId, 'TXN001', 100.00, 0.00);
    $reconciledId = $this->createCompletedEwayContribution($processorId, 'TXN002', 100.00, 0.55);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($unreconciledId, $ids, 'Unreconciled contribution should be returned');
    $this->assertNotContains($reconciledId, $ids, 'Already reconciled contribution should not be returned');
  }

  public function testGetUnreconciledContributionsRespectsLookbackWindow(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $recentId = $this->createCompletedEwayContribution($processorId, 'TXN003', 100.00, 0.00, '-3 days');
    $oldId = $this->createCompletedEwayContribution($processorId, 'TXN004', 100.00, 0.00, '-10 days');

    // Explicitly set lookback to 5 days so the test is not sensitive to the default.
    \Civi::settings()->set('eway_settlement_sync_lookback_days', 5);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($recentId, $ids, 'Recent contribution should be returned');
    $this->assertNotContains($oldId, $ids, 'Old contribution should not be returned');
  }

  public function testGetUnreconciledContributionsExcludesNonEwayContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $ewayContributionId = $this->createCompletedEwayContribution($processorId, 'TXN005');

    // Create a contribution with no payment processor (e.g. cash/cheque).
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Cash')
      ->addValue('last_name', 'Donor')
      ->execute()
      ->first()['id'];
    $cashContributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 100.00)
      ->addValue('fee_amount', 0.00)
      ->addValue('net_amount', 100.00)
      ->addValue('contribution_status_id', 2)
      ->addValue('trxn_id', 'TXN006')
      ->addValue('receive_date', date('Y-m-d H:i:s'))
      ->execute()
      ->first()['id'];

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($ewayContributionId, $ids, 'eWAY contribution should be returned');
    $this->assertNotContains($cashContributionId, $ids, 'Non-eWAY contribution should not be returned');
  }

  public function testGetUnreconciledContributionsDeduplicatesMultipleFinancialTrxn(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN007');

    // Add a second FinancialTrxn + EntityFinancialTrxn for the same contribution,
    // simulating e.g. a fee transaction alongside the main payment transaction.
    $this->linkPaymentProcessorToContribution($contributionId, $processorId, 0.50, date('Y-m-d H:i:s'));

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $occurrences = array_count_values($ids);
    $this->assertContains($contributionId, $ids, 'Contribution should be returned');
    $this->assertEquals(1, $occurrences[$contributionId], 'Contribution should appear exactly once despite multiple EntityFinancialTrxn rows');
  }

  // ---------------------------------------------------------------------------
  // Task 5: reconcileContribution()
  // ---------------------------------------------------------------------------

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

  // ---------------------------------------------------------------------------
  // Task 6: fetchAllSettlementTransactions()
  // ---------------------------------------------------------------------------

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

  // ---------------------------------------------------------------------------
  // Task 7: sync()
  // ---------------------------------------------------------------------------

  public function testSyncReconcileMatchingContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, '11111', 100.00);

    $settlementTransactions = [
      ['TransactionID' => 11111, 'FeePerTransaction' => 55, 'Amount' => 10000],
      ['TransactionID' => 99999, 'FeePerTransaction' => 30, 'Amount' => 5000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),
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

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $contribution['fee_amount']);
  }

}
