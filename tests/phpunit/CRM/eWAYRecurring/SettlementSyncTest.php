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

}
