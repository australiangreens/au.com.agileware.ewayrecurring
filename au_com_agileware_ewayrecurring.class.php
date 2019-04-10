<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/*
 +--------------------------------------------------------------------+
 | eWay Recurring Payment Processor Extension                         |
 +--------------------------------------------------------------------+
 | Licensed to CiviCRM under the Academic Free License version 3.0    |
 |                                                                    |
 | Originally written & contributed by Dolphin Software P/L - March   |
 | 2008                                                               |
 |                                                                    |
 | This is a combination of the original eWay payment processor, with |
 | customisations to handle recurring payments as well. Originally    |
 | started by Chris Ward at Community Builders in 2012.               |
 |                                                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | This code was initially based on the recent PayJunction module     |
 | contributed by Phase2 Technology, and then plundered bits from     |
 | the AuthorizeNet module contributed by Ideal Solution, and         |
 | referenced the eWAY code in Drupal 5.7's ecommerce-5.x-3.4 and     |
 | ecommerce-5.x-4.x-dev modules.                                     |
 |                                                                    |
 | Plus a bit of our own code of course - Peter Barwell               |
 | contact PB@DolphinSoftware.com.au if required.                     |
 |                                                                    |
 | NOTE: The eWAY gateway only allows a single currency per account   |
 |       (per eWAY CustomerID) ie you can only have one currency per  |
 |       added Payment Processor.                                     |
 |       The only way to add multi-currency is to code it so that a   |
 |       different CustomerID is used per currency.                   |
 |                                                                    |
 +--------------------------------------------------------------------+
*/

/*
 -----------------------------------------------------------------------------------------------
 From the eWay web-site - http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
 -----------------------------------------------------------------------------------------------
   The test Customer ID is 87654321 - this is the only ID that will work on the test gateway.
   The test Credit Card number is 4444333322221111
   - this is the only credit card number that will work on the test gateway.
   The test Total Amount should end in 00 or 08 to get a successful response (e.g. $10.00 or $10.08)
   ie - all other amounts will return a failed response.

 -----------------------------------------------------------------------------------------------
*/

require_once 'CRM/Core/Payment.php';
require_once 'eWAYRecurring.process.inc';

// Include eWay SDK.
require_once extensionPath('vendor/autoload.php');

class au_com_agileware_ewayrecurring extends CRM_Core_Payment
{
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
  */
  function __construct( $mode, &$paymentProcessor )
  {
    $this->_mode             = $mode;             // live or test
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('eWay Recurring');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton( $mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE )
  {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
        self::$_singleton[$processorName] = new au_com_agileware_ewayrecurring( $mode, $paymentProcessor );
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Create eWay client using credentials from payment processor.
   *
   * @return \Eway\Rapid\Contract\Client
   */
  function getEWayClient() {
    $eWayApiKey = $this->_paymentProcessor['user_name'];   // eWAY Api Key
    $eWayApiPassword = $this->_paymentProcessor['password']; // eWAY Api Password
    $eWayEndPoint = ($this->_paymentProcessor['is_test']) ? \Eway\Rapid\Client::MODE_SANDBOX : \Eway\Rapid\Client::MODE_PRODUCTION;

    $eWayClient = \Eway\Rapid::createClient($eWayApiKey, $eWayApiPassword, $eWayEndPoint);

    return $eWayClient;
  }

  /**
   * Validate contribution on successful response.
   *
   * @param $eWayAccessCode
   * @param $contributionID
   */
  function validateContribution($eWayAccessCode, $contribution, $qfKey, $paymentProcessor) {
    $this->_is_test = $contribution['is_test'];

    $response = CRM_eWAYRecurring_eWAYRecurringUtils::validateContribution($eWayAccessCode, $contribution, $paymentProcessor);
    CRM_eWAYRecurring_eWAYRecurringUtils::completeEWayTransaction($eWayAccessCode);

    $hasTransactionFailed = $response['hasTransactionFailed'];
    $transactionResponseError = $response['transactionResponseError'];
    $contributionID = $response['contributionId'];
    $transactionID = $response['transactionId'];

    if ($hasTransactionFailed) {
      civicrm_api3('Contribution', 'create', array(
        'id'                      => $contributionID,
        'contribution_status_id'  => _contribution_status_id('Failed'),
        'trxn_id'                 => $transactionID,
      ));

      if ($transactionResponseError != '') {
        CRM_Core_Session::setStatus($transactionResponseError, ts('Error'), 'error');
      }
      $failUrl = $this->getReturnFailUrl($qfKey);
      CRM_Utils_System::redirect($failUrl);
    }

  }

  /**
   * Remove Credit card fields from the form.
   *
   * @return array
   */
  function getPaymentFormFields() {
    return array();
  }

  /**
   * Set customer's country based on given params
   *
   * @param $params
   */
  private function setCustomerCountry(&$params) {
    if (!isset($params['country']) || empty($params['country'])) {
      $countryId = 0;
      if (isset($params['country_id']) && !empty($params['country_id'])) {
        $countryId = $params['country_id'];
      }
      if ($countryId == 0) {
        try {
          $billingLocationTypeId = civicrm_api3('LocationType', 'getsingle', [
            'return' => ["id"],
            'name' => "Billing",
          ]);
          $billingLocationTypeId = $billingLocationTypeId['id'];
        }
        catch (CiviCRM_API3_Exception $e) {
        }
        $billingCountryIdKey = "billing_country_id-" . $billingLocationTypeId;
        if (isset($params[$billingCountryIdKey]) && !empty($params[$billingCountryIdKey])) {
          $countryId = $params[$billingCountryIdKey];
        }
      }
      $isoCountryCode = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_Address', 'country_id', $countryId);
      $params['country'] = $isoCountryCode;
    }
  }

  /**
   * Form customer details array from given params.
   *
   * @param $params array
   * @return array
   */
  function getEWayClientDetailsArray($params) {
    $this->setCustomerCountry($params);
    if (empty($params['country'])) {
      return self::errorExit(9007, 'Not able to retrieve customer\'s country.' );
    }
    $eWayCustomer = array(
      'Reference' => (isset($params['contactID'])) ? 'Civi-' . $params['contactID'] : '', // Referencing eWay customer with CiviCRM id if we have.
      'FirstName' => $params['first_name'],
      'LastName' => $params['last_name'],
      'Street1' => $params['street_address'],
      'City' => $params['city'],
      'State' => $params['state_province'],
      'PostalCode' => $params['postal_code'],
      'Country' => $params['country'],
      'Email' => (isset($params['email']) ? $params['email'] : ''), // Email is not accessible for updateSubscriptionBillingInfo method.
    );

    if(isset($params['subscriptionId']) && !empty($params['subscriptionId'])) {
        $eWayCustomer['TokenCustomerID'] = $params['subscriptionId']; //Include cutomer token for updateSubscriptionBillingInfo.
    }

    if(strlen($eWayCustomer['Country']) > 2) {
        // Replace country value if given country is the name of the country instead of Country code.
        $isoCountryCode = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_Address', 'country_id', $params['country_id']);
        $eWayCustomer['Country'] = $isoCountryCode;
    }

    return $eWayCustomer;
  }

  /**
   * Check if eWayResponse has any errors. Return array of errors if transaction was unsuccessful.
   *
   * @param Eway\Rapid\Model\Response\AbstractResponse $eWAYResponse
   * @return array
   */
  function getEWayResponseErrors($eWAYResponse) {
    $transactionErrors = array();

    $eway_errors = $eWAYResponse->getErrors();
    foreach ($eway_errors as $error) {
      $errorMessage = \Eway\Rapid::getMessage($error);
      $transactionErrors[] = $errorMessage;
    }

    return $transactionErrors;
  }

    /**
     * This function sends request and receives response from
     * eWAY payment process
     */
    function doDirectPayment( &$params )
    {
        if ( ! defined( 'CURLOPT_SSLCERT' ) ) {
            CRM_Core_Error::fatal( ts( 'eWAY - Gateway requires curl with SSL support' ) );
        }

        $eWayClient = $this->getEWayClient();

        //-------------------------------------------------------------
        // Prepare some composite data from _paymentProcessor fields, data that is shared across one off and recurring payments.
        //-------------------------------------------------------------

        $amountInCents = round(((float) preg_replace('/[\s,]/', '', $params['amount'])) * 100);
        $eWayCustomer = $this->getEWayClientDetailsArray($params);

        //----------------------------------------------------------------------------------------------------
        // Throw error if there are some errors while creating eWAY Client.
        // This could be due to incorrect Api Username or Api Password.
        //----------------------------------------------------------------------------------------------------

        if(is_null($eWayClient) || count($eWayClient->getErrors())) {
            return self::errorExit( 9001, "Error: Unable to create eWAY Client object.");
        }

        //----------------------------------------------------------------------------------------------------
        // Now set the payment details - see https://eway.io/api-v3/#direct-connection
        //----------------------------------------------------------------------------------------------------


        //----------------------------------------------------------------------------------------------------
        // We use CiviCRM's param's 'invoiceID' as the unique transaction token to feed to eWAY
        // Trouble is that eWAY only accepts 16 chars for the token, while CiviCRM's invoiceID is an 32.
        // As its made from a "$invoiceID = md5(uniqid(rand(), true));" then using the first 12 chars
        // should be alright
        //----------------------------------------------------------------------------------------------------

        $uniqueTrnxNum = substr($params['invoiceID'], 0, 12);
        $invoiceDescription = $params['description'];
        if ($invoiceDescription == '') {
          $invoiceDescription = 'Invoice ID: ' . $params['invoiceID'];
        }

        $eWayTransaction = array(
          'Customer' => $eWayCustomer,
          'RedirectUrl' => $this->getSuccessfulPaymentReturnUrl($params),
          'CancelUrl' => $this->getCancelPaymentReturnUrl($params),
          'TransactionType' => \Eway\Rapid\Enum\TransactionType::PURCHASE,
          'Payment' => [
            'TotalAmount' => $amountInCents,
            'InvoiceNumber' => $uniqueTrnxNum,
            'InvoiceDescription' => substr(trim($invoiceDescription), 0, 64),
            'InvoiceReference' => $params['invoiceID'],
          ],
          'CustomerIP' => (isset($params['ip_address'])) ? $params['ip_address'] : '',
          'Capture' => TRUE,
          'SaveCustomer' => TRUE,
          'Options' => [
            'ContributionID' => $params['contributionID'],
          ],
          'CustomerReadOnly' => TRUE,
        );


        // Was the recurring payment check box checked?
        if (CRM_Utils_Array::value('is_recur', $params, false)) {

            //----------------------------------------------------------------------------------------------------
            // Force the contribution to Pending.
            //----------------------------------------------------------------------------------------------------

            CRM_Core_DAO::setFieldValue(
              'CRM_Contribute_DAO_Contribution',
              $params['contributionID'],
              'contribution_status_id',
              _contribution_status_id('Pending')
            );
        }

        //----------------------------------------------------------------------------------------------------
        // Allow further manipulation of the arguments via custom hooks ..
        //----------------------------------------------------------------------------------------------------

        CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWayTransaction );

        //----------------------------------------------------------------------------------------------------
        // Check to see if we have a duplicate before we send request.
        //----------------------------------------------------------------------------------------------------

        if (method_exists($this, 'checkDupe') ?
          $this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params)) :
          $this->_checkDupe($params['invoiceID'])
        ) {
          return self::errorExit(9003, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from eWAY.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.' );
        }

        $eWAYResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::RESPONSIVE_SHARED, $eWayTransaction);

        //----------------------------------------------------------------------------------------------------
        // If null data returned - tell 'em and bail out
        //----------------------------------------------------------------------------------------------------

        if ( is_null($eWAYResponse) ) {
          return self::errorExit( 9006, "Error: Connection to payment gateway failed - no data returned.");
        }

        //----------------------------------------------------------------------------------------------------
        // See if we got an OK result - if not tell 'em and bail out
        //----------------------------------------------------------------------------------------------------
        $transactionErrors = $this->getEWayResponseErrors($eWAYResponse);
        if(count($transactionErrors)) {
          return self::errorExit( 9008, implode("<br>", $transactionErrors));
        }

        $paymentProcessor = $this->getPaymentProcessor();

        civicrm_api3('EwayContributionTransactions', 'create', array(
          'access_code'     => $eWAYResponse->AccessCode,
          'contribution_id' => $params['contributionID'],
          'payment_processor_id' => $paymentProcessor['id'],
        ));

        CRM_Utils_System::redirect($eWAYResponse->SharedPaymentUrl);

        return $params;
    }

  /**
   * Check if submitting the payment from contribution page.
   * @param $params
   * @return bool
   */
    function isFromContributionPage($params) {
      return (isset($params['contributionPageID']) && !empty($params['contributionPageID']));
    }

  /**
   * Get cancel payment return URL.
   *
   * @param $params
   * @return string
   */
    function getCancelPaymentReturnUrl($params) {
      if ($this->isFromContributionPage($params)) {
        return $this->getCancelUrl($params['qfKey'], '');
      }

      return CRM_Utils_System::url('civicrm/contact/view/contribution', array(
        'action'      => 'add',
        'cid'         => $params['contactID'],
        'context'     => 'contribution',
        'mode'        => 'live',
      ), TRUE, NULL, FALSE);
    }

  /**
   * Get successful payment return URL.
   *
   * @param $params
   * @return string
   */

    function getSuccessfulPaymentReturnUrl($params) {
      if ($this->isFromContributionPage($params)) {
        return $this->getReturnSuccessUrl($params['qfKey']);
      }

      return CRM_Utils_System::url('civicrm/ewayrecurring/verifypayment', array(
        'contributionInvoiceID' => $params['invoiceID'],
        'qfKey'                 => $params['qfKey'],
        'paymentProcessorID'    => ($this->getPaymentProcessor())['id'],
      ), TRUE, NULL, FALSE);
    }

    /**
     * Checks to see if invoice_id already exists in db
     * @param  int     $invoiceId   The ID to check
     * @return bool                 True if ID exists, else false
     */
    function _checkDupe( $invoiceId )
    {
        require_once 'CRM/Contribute/DAO/Contribution.php';
        $contribution = new CRM_Contribute_DAO_Contribution( );
        $contribution->invoice_id = $invoiceId;
        return $contribution->find( );
    }

    /**
     * This function checks the eWAY response status - returning a boolean false if status != 'true'
     *
     * @param $response
     * @return bool
     */
    function isError( &$response)
    {
        $errors = $response->getErrors();

        if ( count($errors) ) {
            return true;
        }
        return false;
    }

    /**
     * Produces error message and returns from class
     *
     * @param null $errorCode
     * @param null $errorMessage
     * @return object
     */
    function &errorExit ( $errorCode = null, $errorMessage = null )
    {
        $e =& CRM_Core_Error::singleton( );

        if ( $errorCode ) {
            $e->push( $errorCode, 0, null, $errorMessage );
        } else {
            $e->push( 9000, 0, null, 'Unknown System Error.' );
        }
        return $e;
    }

    /**
     * This public function checks to see if we have the right processor config values set
     *
     * NOTE: Called by Events and Contribute to check config params are set prior to trying
     *       register any credit card details
     *
     * @param string $mode the mode we are operating in (live or test) - not used but could be
     * to check that the 'test' mode CustomerID was equal to '87654321' and that the URL was
     * set to https://www.eway.com.au/gateway_cvn/xmltest/TestPage.asp
     *
     * returns string $errorMsg if any errors found - null if OK
     *
     * @return null|string
     */
    function checkConfig( )
    {
        $errorMsg = array();

        if ( empty( $this->_paymentProcessor['user_name'] ) ) {
            $errorMsg[] = ts( 'eWAY API Key is not set for this payment processor' );
        }

        if ( empty( $this->_paymentProcessor['password'] ) ) {
            $errorMsg[] = ts( 'eWAY API Password is not set for this payment processor' );
        }

        // TODO: Check that recurring config values have been set

        if ( ! empty( $errorMsg ) ) {
            return implode( '<p>', $errorMsg );
        } else {
            return null;
        }
    }

    /**
     * Function handles eWAY Recurring Payments cron job.
     *
     * @return bool
     */
    function handlePaymentCron() {
      return process_recurring_payments($this->_paymentProcessor, $this);
    }

    /**
     * Function to update the subscription amount of recurring payments.
     *
     * @param string $message
     * @param array $params
     * @return bool
     */
    function changeSubscriptionAmount(&$message = '', $params = array()) {
      // Process Schedule updates here.
      if($params['next_scheduled_date']){
          $submitted_nsd = strtotime($params['next_scheduled_date'] . ' ' . $params['next_scheduled_date_time']);
          CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
				     $params['id'],
				     'next_sched_contribution_date',
				     date('YmdHis', $submitted_nsd) );
      }
      return TRUE;
    }

    /**
     * Function to cancel the recurring payment subscription.
     *
     * @param string $message
     * @param array $params
     * @return bool
     */
    function cancelSubscription(&$message = '', $params = array()) {
      // TODO: Implement this - request token deletion from eWAY?
      return TRUE;
    }

    /**
     * Function to update billing subscription details of the contact and it updates
     * customer details in eWay using UpdateCustomer method.
     *
     * @param string $message
     * @param array $params
     * @return \Eway\Rapid\Model\Response\CreateCustomerResponse|object
     */
    function updateSubscriptionBillingInfo(&$message = '', $params = array()) {
      //----------------------------------------------------------------------------------------------------
      // Something happens to the PseudoConstant cache so it stores the country label in place of its ISO 3166 code.
      // Flush to cache to work around this.
      //----------------------------------------------------------------------------------------------------

      CRM_Core_PseudoConstant::flush();

      //----------------------------------------------------------------------------------------------------
      // Build the customer info for eWAY
      //----------------------------------------------------------------------------------------------------

      $eWayCustomer = $this->getEWayClientDetailsArray($params);

      try {
        //----------------------------------------------------------------------------------------------------
        // Get the payment.  Why isn't this provided to the function.
        //----------------------------------------------------------------------------------------------------

        $contribution = civicrm_api3('ContributionRecur', 'getsingle', array(
                        'payment_processor_id' => $this->_paymentProcessor['id'],
                        'processor_id' => $params['subscriptionId']
                      ));

        //----------------------------------------------------------------------------------------------------
        // We shouldn't be allowed to update the details for completed or cancelled payments
        //----------------------------------------------------------------------------------------------------

        switch($contribution['contribution_status_id']) {
          case _contribution_status_id('Completed'):
            throw new Exception(ts('Attempted to update billing details for a completed contribution.'));
            break;
          case _contribution_status_id('Cancelled'):
            throw new Exception(ts('Attempted to update billing details for a cancelled contribution.'));
            break;
          default:
            break;
        }

        //----------------------------------------------------------------------------------------------------
        // Hook to allow customer info to be changed before submitting it
        //----------------------------------------------------------------------------------------------------
        CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWayCustomer);

        $eWayClient = $this->getEWayClient();

        //----------------------------------------------------------------------------------------------------
        // Create eWay Customer.
        //----------------------------------------------------------------------------------------------------
        $eWayCustomerResponse = $eWayClient->updateCustomer(\Eway\Rapid\Enum\ApiMethod::DIRECT, $eWayCustomer);

        //----------------------------------------------------------------------------------------------------
        // See if we got an OK result - if not tell 'em and bail out
        //----------------------------------------------------------------------------------------------------
        $transactionErrors = $this->getEWayResponseErrors($eWayCustomerResponse);
        if(count($transactionErrors)) {
          return self::errorExit( 9008, implode("<br>", $transactionErrors));
        }

        //----------------------------------------------------------------------------------------------------
        // Updating the billing details should fixed failed contributions
        //----------------------------------------------------------------------------------------------------

        if(_contribution_status_id('Failed') == $contribution['contribution_status_id']) {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution['id'],
          'contribution_status_id',
          _contribution_status_id('In Progress') );
        }

        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution['id'],
          'failure_count',
          0 );

        return $eWayCustomerResponse;
    }
    catch(Exception $e) {
      return self::errorExit(9010, $e->getMessage());
    }
  }

}
