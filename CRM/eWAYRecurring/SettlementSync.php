<?php

use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;

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

  public function __construct(?\GuzzleHttp\Client $httpClient = NULL) {
    $this->httpClient = $httpClient ?? new \GuzzleHttp\Client();
  }

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

  /**
   * Returns Completed eWAY contributions for a processor that have not been
   * reconciled (fee_amount = 0.00) within the lookback window.
   *
   * @param int $processorId
   * @return array Array of contribution records with id, trxn_id, total_amount,
   *   receive_date, and payment_processor_id.
   */
  public function getUnreconciledContributions(int $processorId): array {
    $lookbackDays = (int) Civi::settings()->get('eway_settlement_sync_lookback_days') ?: 5;
    // Note: cutoff uses a full datetime while the eWAY Settlement API uses calendar
    // dates (Y-m-d). Both use the same lookback value, so edge-of-day contributions
    // are consistently included on both sides within the same calendar day.
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

  /**
   * Main entry point. Syncs settlement data for all active live eWAY processors.
   */
  public function sync(): void {
    // Implemented in Task 7.
  }

}
