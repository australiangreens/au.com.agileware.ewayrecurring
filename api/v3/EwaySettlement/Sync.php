<?php

use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * EwaySettlement.Sync API specification.
 *
 * @param array $spec
 */
function _civicrm_api3_eway_settlement_Sync_spec(&$spec) {
  // No parameters required.
}

/**
 * EwaySettlement.Sync API
 *
 * Queries the eWAY Settlement Search API and reconciles fee_amount and
 * net_amount on Completed contributions that have not yet been reconciled.
 *
 * Invoked by the "eWay Settlement Sync" scheduled job (Daily).
 *
 * @param array $params
 * @return array API result descriptor
 */
function civicrm_api3_eway_settlement_Sync($params) {
  $sync = new CRM_eWAYRecurring_SettlementSync();
  $sync->sync();
  return civicrm_api3_create_success([], $params, 'EwaySettlement', 'Sync');
}
