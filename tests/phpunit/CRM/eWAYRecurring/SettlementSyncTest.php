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

    return Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', $totalAmount)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $totalAmount - $feeAmount)
      ->addValue('contribution_status_id', 2)
      ->addValue('payment_instrument_id', $processorId)
      ->addValue('trxn_id', $trxnId)
      ->addValue('receive_date', $date)
      ->execute()
      ->first()['id'];
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

}
