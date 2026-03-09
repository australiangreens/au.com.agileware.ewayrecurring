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

  // Tests are added in subsequent tasks.

}
