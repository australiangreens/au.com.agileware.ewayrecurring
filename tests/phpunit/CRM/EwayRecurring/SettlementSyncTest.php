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
    parent::setUp();
    $this->useTransaction(TRUE);
  }

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

}
