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
   * Returns the valid options for the eway_settlement_sync_mode setting.
   * Used as the pseudoconstant callback for the (non-UI) setting, so API
   * and drush consumers get a documented option list.
   *
   * @return array<string, string> Value => label pairs.
   */
  public static function getModeOptions(): array {
    return [
      'live' => ts('Live payments only'),
      'test' => ts('Test payments only'),
      'both' => ts('Live and test payments'),
    ];
  }

  /**
   * Returns all active eWAY payment processors matching the given sync mode.
   *
   * @param string $mode One of 'live', 'test', or 'both'.
   * @return array Array of processor records with id, user_name, password, is_test.
   */
  public function getEwayProcessors(string $mode): array {
    $query = PaymentProcessor::get(FALSE)
      ->addSelect('id', 'user_name', 'password', 'is_test')
      ->addWhere('payment_processor_type_id:name', '=', 'eWay_Recurring')
      ->addWhere('is_active', '=', TRUE);

    if ($mode === 'live') {
      $query->addWhere('is_test', '=', FALSE);
    }
    elseif ($mode === 'test') {
      $query->addWhere('is_test', '=', TRUE);
    }
    // 'both': we have to explicitly search for both values
    // of is_test because
    // Civi\Api4\Service\Spec\Provider\GetActionDefaultsProvider
    // automatically adds `is_test = 0` to queries that don't
    // explicitly use `is_test` in their Where clauses
    else {
      $query->addWhere('is_test', 'IN', [TRUE, FALSE]);
    }

    return $query->execute()->getArrayCopy();
  }

  /**
   * Returns all Completed eWAY contributions that have not been reconciled
   * (fee_amount = 0) within the lookback window, filtered by sync mode.
   *
   * The join path follows the CiviCRM financial data model:
   *   Contribution → EntityFinancialTrxn (bridge) → FinancialTrxn → PaymentProcessor → PaymentProcessorType
   *
   * setDistinct(TRUE) prevents duplicate rows when a contribution has multiple
   * linked financial transactions.
   *
   * When $contributionId is given the query is scoped to that single
   * contribution: the explicit id is the scope and the sync-mode is_test
   * filter is skipped, but the window guard (receive_date >= today - window)
   * and the safety guards (Completed, eWAY, fee_amount = 0, non-empty trxn_id)
   * all still apply. A contribution older than the window returns no rows.
   *
   * Each row carries 'processor.id' — the payment processor that took the
   * contribution — so the caller can match it only against that processor's
   * settlement data.
   *
   * @param string $mode One of 'live', 'test', or 'both'.
   * @param int|null $contributionId Optional. Restrict to a single contribution.
   * @return array Array of contribution records with id, trxn_id, total_amount, receive_date.
   */
  public function getUnreconciledContributions(string $mode, ?int $contributionId = NULL): array {
    $query = Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'total_amount', 'receive_date', 'processor.id')
      ->addJoin('FinancialTrxn AS ft', 'INNER', 'EntityFinancialTrxn')
      ->addJoin('PaymentProcessor AS processor', 'INNER', ['processor.id', '=', 'ft.payment_processor_id'])
      ->addJoin('PaymentProcessorType AS processor_type', 'INNER', ['processor_type.id', '=', 'processor.payment_processor_type_id'])
      ->addWhere('processor_type.name', '=', 'eWay_Recurring')
      ->addWhere('processor.is_active', '=', TRUE)
      ->addWhere('contribution_status_id', '=', 1)
      ->addWhere('fee_amount', '=', 0)
      ->addWhere('trxn_id', 'IS NOT NULL')
      ->addWhere('trxn_id', 'IS NOT EMPTY')
      ->addGroupBy('id')
      ->addOrderBy('id', 'ASC');

    $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $this->getWindowDays() . ' days'));
    // Note: cutoff uses a full datetime while the eWAY Settlement API uses
    // calendar dates (Y-m-d). Both derive from the same window value, so
    // edge-of-day contributions are consistently included on both sides.
    $query->addWhere('receive_date', '>=', $cutoffDate);

    if ($contributionId !== NULL) {
      // The explicit id is the scope; the window guard above still applies so a
      // scoped run can never reconcile something older than the unscoped run
      // would. The sync-mode is_test filter is intentionally not applied here.
      $query->addWhere('id', '=', $contributionId);
    }
    else {
      if ($mode === 'live') {
        $query->addWhere('processor.is_test', '=', FALSE);
      }
      elseif ($mode === 'test') {
        $query->addWhere('processor.is_test', '=', TRUE);
      }
      // 'both': no is_test filter
    }

    return $query->execute()->getArrayCopy();
  }

  /**
   * Settlement sync window size in days, back from today. Bounds both the
   * unreconciled-contribution candidacy query and the per-day settlement
   * report iteration. Falls back to 5 if the setting is unset or zero.
   */
  private function getWindowDays(): int {
    return (int) Civi::settings()->get('eway_settlement_window_days') ?: 5;
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

    $result = Contribution::update(FALSE)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $netAmount)
      ->addWhere('id', '=', $contribution['id'])
      // Re-assert the unreconciled predicate: if a manual reconciliation wrote
      // fee_amount between candidate selection and now, this update matches
      // nothing and the existing data is preserved.
      ->addWhere('fee_amount', '=', 0)
      ->execute();

    if (count($result) === 0) {
      Civi::log()->debug(
        'eWAY Settlement Sync: contribution {id} already reconciled elsewhere; skipped',
        ['id' => $contribution['id']]
      );
    }
  }

  /**
   * Fetches all settlement transactions for a processor for a single calendar
   * day, following pagination. The day is queried as StartDate == EndDate so
   * the request is byte-for-byte identical on every run and eWAY's
   * server-side report cache is reused.
   *
   * @param array $processor Processor record with user_name, password, is_test.
   * @param string $day Calendar day, 'Y-m-d'.
   * @return array Flat array of settlement transaction records.
   * @throws \CRM_eWAYRecurring_SettlementNotReadyException if eWAY has not
   *   built the report for this date range yet.
   * @throws \RuntimeException on any other eWAY API-level error.
   */
  public function fetchSettlementDay(array $processor, string $day): array {
    $all = [];
    $page = 1;

    do {
      $response = $this->fetchSettlementPage($processor, $day, $page);
      $transactions = $response['SettlementTransactions'] ?? [];
      $all = array_merge($all, $transactions);
      $page++;
    } while (count($transactions) >= self::PAGE_SIZE);

    return $all;
  }

  /**
   * Fetches a single page of settlement data for one calendar day.
   *
   * NOTE: exact parameter names / ReportMode are not yet confirmed against
   * https://eway.io/api-v3/#settlement-search — see the follow-up spike in the
   * design doc. Per-day iteration is correct under either the transaction-date
   * or settlement-date interpretation.
   *
   * @param array $processor
   * @param string $day Calendar day, 'Y-m-d', used for both StartDate and EndDate.
   * @param int $page 1-indexed page number.
   * @return array Decoded response body.
   * @throws \CRM_eWAYRecurring_SettlementNotReadyException on eWAY's async-build response.
   * @throws \RuntimeException on any other non-empty Errors field.
   */
  private function fetchSettlementPage(array $processor, string $day, int $page): array {
    $baseUrl = $processor['is_test']
      ? self::SETTLEMENT_URL_SANDBOX
      : self::SETTLEMENT_URL_PRODUCTION;

    $response = $this->httpClient->get($baseUrl, [
      'auth' => [$processor['user_name'], $processor['password']],
      'query' => [
        'ReportMode' => 'TransactionOnly',
        'StartDate' => $day,
        'EndDate' => $day,
        'Page' => $page,
        'PageSize' => self::PAGE_SIZE,
      ],
    ]);

    $body = json_decode($response->getBody()->getContents(), TRUE) ?? [];
    if (!empty($body['Errors'])) {
      // eWAY builds each (StartDate, EndDate) report asynchronously; the first
      // request for a range returns this marker and data follows ~60 min later.
      // Pre-enablement / genuinely-unavailable ranges surface either this
      // marker or an empty result; both are safe to skip. Any other error text
      // is treated as a hard failure.
      if (stripos((string) $body['Errors'], 'data will be available') !== FALSE) {
        throw new CRM_eWAYRecurring_SettlementNotReadyException(
          'eWAY settlement report not ready for ' . $day . ': ' . $body['Errors']
        );
      }
      throw new \RuntimeException('eWAY Settlement API error: ' . $body['Errors']);
    }
    return $body;
  }

  /**
   * Main entry point. Fetches all unreconciled eWAY contributions once, then
   * for each processor queries the settlement API and reconciles matches.
   * Which processor types are included is controlled by the non-UI
   * eway_settlement_sync_mode setting ('live', 'test', or 'both'),
   * which defaults to 'live'.
   *
   * Processor isolation is achieved through trxn_id matching: each processor's
   * settlement API only returns that processor's transactions, so contributions
   * are only updated when their trxn_id appears in the correct processor's data.
   *
   * @param int|null $contributionId Optional. Restrict the run to a single
   *   contribution (manual / QA use). When set, contribution selection ignores
   *   the lookback window and sync-mode filters; the eWAY Settlement API date
   *   range still uses eway_settlement_window_days, so that setting must
   *   be wide enough to cover the target contribution's age.
   */
  public function sync(?int $contributionId = NULL): void {
    $mode = Civi::settings()->get('eway_settlement_sync_mode') ?: 'live';

    $processors = $this->getEwayProcessors($mode);
    if (empty($processors)) {
      return;
    }

    $contributions = $this->getUnreconciledContributions($mode, $contributionId);
    if (empty($contributions)) {
      return;
    }

    // Build lookup map: string trxn_id => contribution record.
    $contributionMap = [];
    foreach ($contributions as $contribution) {
      $contributionMap[(string) $contribution['trxn_id']] = $contribution;
    }

    foreach ($processors as $processor) {
      try {
        // TEMP (Task 6 replaces this whole method with per-day window iteration):
        $settlementTransactions = $this->fetchSettlementDay($processor, date('Y-m-d'));

        foreach ($settlementTransactions as $txn) {
          $trxnId = (string) $txn['TransactionID'];
          if (isset($contributionMap[$trxnId]) && isset($txn['FeePerTransaction'])) {
            $this->reconcileContribution($contributionMap[$trxnId], $txn);
            // Remove from map so a contribution is not processed twice
            // if it somehow appears in multiple processors' settlement data.
            unset($contributionMap[$trxnId]);
          }
        }
      }
      catch (\Exception $e) {
        Civi::log()->warning('eWAY Settlement Sync failed for processor {id}: {msg}', [
          'id' => $processor['id'],
          'msg' => $e->getMessage(),
          'exception' => $e,
        ]);
      }
    }
  }

}
