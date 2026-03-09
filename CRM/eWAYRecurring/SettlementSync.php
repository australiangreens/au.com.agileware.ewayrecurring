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
   * Returns all Completed eWAY contributions that have not been reconciled
   * (fee_amount = 0.00) within the lookback window, across all live eWAY processors.
   *
   * The Contribution entity in CiviCRM API v4 exposes the payment processor reference
   * as payment_instrument_id. We JOIN through PaymentProcessor and PaymentProcessorType
   * to scope to live eWAY contributions. Per-processor scoping is then achieved in
   * sync() through trxn_id matching against each processor's settlement API response.
   *
   * @return array Array of contribution records with id, trxn_id, total_amount, receive_date.
   */
  public function getUnreconciledContributions(): array {
    $lookbackDays = (int) Civi::settings()->get('eway_settlement_sync_lookback_days') ?: 5;
    // Note: cutoff uses a full datetime while the eWAY Settlement API uses calendar
    // dates (Y-m-d). Both use the same lookback value, so edge-of-day contributions
    // are consistently included on both sides within the same calendar day.
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));

    return Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'total_amount', 'receive_date')
      ->addJoin('PaymentProcessor AS processor', 'INNER', ['processor.id', '=', 'payment_instrument_id'])
      ->addJoin('PaymentProcessorType AS processor_type', 'INNER', ['processor_type.id', '=', 'processor.payment_processor_type_id'])
      ->addWhere('processor_type.name', '=', 'eWay_Recurring')
      ->addWhere('processor.is_test', '=', FALSE)
      ->addWhere('processor.is_active', '=', TRUE)
      ->addWhere('contribution_status_id', '=', 2)
      ->addWhere('fee_amount', '=', 0)
      ->addWhere('receive_date', '>=', $cutoffDate)
      ->addWhere('trxn_id', 'IS NOT NULL')
      ->addWhere('trxn_id', '!=', '')
      ->execute()
      ->getArrayCopy();
  }

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

  /**
   * Fetches all settlement transactions for a processor over the lookback window,
   * handling pagination automatically.
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
   * NOTE: Verify exact parameter names against https://eway.io/api-v3/#settlement-search
   * before deploying. The parameters below are based on eWAY API conventions.
   *
   * @param array $processor
   * @param int $page 1-indexed page number.
   * @return array Decoded response body.
   * @throws \RuntimeException on eWAY API-level errors (non-empty Errors field).
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

  /**
   * Main entry point. Syncs settlement data for all active live eWAY processors.
   */
  public function sync(): void {
    // Implemented in Task 7.
  }

}
