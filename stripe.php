<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Joomla\Utilities\ArrayHelper;
use Prism\Payment\Result as PaymentResult;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');
jimport('Prism.libs.Stripe.init');

JObserverMapper::addObserverClassToClass(Crowdfunding\Observer\Transaction\TransactionObserver::class, Crowdfunding\Transaction\TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

/**
 * Crowdfunding Stripe Payment Plug-in
 *
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 */
class plgCrowdfundingPaymentStripe extends Crowdfunding\Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'Stripe';
        $this->serviceAlias    = 'stripe';

        $this->extraDataKeys   = array(
            'object', 'id', 'created', 'livemode', 'type', 'pending_webhooks', 'request', 'paid',
            'amount', 'currency', 'captured', 'balance_transaction', 'failure_message', 'failure_code',
            'data'
        );

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @return null|string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/stripe';

        // Load the script that initialize the select element with banks.
        JHtml::_('jquery.framework');

        // Get access token
        $apiKeys = $this->getKeys();

        $html   = array();
        $html[] = '<div class="well">';
        $html[] = '<h4><img src="' . $pluginURI . '/images/stripe_icon.png" width="32" height="32" alt="Stripe" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';

        if (!$apiKeys['published'] or !$apiKeys['secret']) {
            $html[] = '<p class="bg-warning p-10-5"><span class="fa fa-warning"></span> ' . JText::_($this->textPrefix . '_ERROR_CONFIGURATION') . '</p>';
            $html[] = '</div>'; // Close the div "well".
            return implode("\n", $html);
        }

        // Get image
        $dataImage = (!$this->params->get('logo')) ? '' : 'data-image="' . $this->params->get('logo') . '"';

        // Get company name.
        if (!$this->params->get('company_name')) {
            $dataName = 'data-name="' . htmlentities($this->app->get('sitename'), ENT_QUOTES, 'UTF-8') . '"';
        } else {
            $dataName = 'data-name="' . htmlentities($this->params->get('company_name'), ENT_QUOTES, 'UTF-8') . '"';
        }

        // Get project title.
        $dataDescription = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));

        // Get amount.
        $dataAmount = (int)abs($item->amount * 100);

        $dataPanelLabel = (!$this->params->get('panel_label')) ? '' : 'data-panel-label="' . $this->params->get('panel_label') . '"';
        $dataLabel      = (!$this->params->get('label')) ? '' : 'data-label="' . $this->params->get('label') . '"';

        // Prepare optional data.
        $optionalData = array($dataLabel, $dataPanelLabel, $dataName, $dataImage);
        $optionalData = array_filter($optionalData);

        $html[] = '<form action="/index.php?com_crowdfunding" method="post">';
        $html[] = '<script
            src="https://checkout.stripe.com/checkout.js" class="stripe-button"
            data-key="' . $apiKeys['published'] . '"
            data-description="' . $dataDescription . '"
            data-amount="' . $dataAmount . '"
            data-currency="' . $item->currencyCode . '"
            data-allow-remember-me="' . $this->params->get('remember_me', 'true') . '"
            data-zip-code="' . $this->params->get('zip_code', 'false') . '"
            ' . implode("\n", $optionalData) . '
            >
          </script>';
        $html[] = '<input type="hidden" name="pid" value="' . (int)$item->id . '" />';
        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="payment_service" value="stripe" />';
        $html[] = JHtml::_('form.token');
        $html[] = '</form>';

        if ($this->params->get('display_info', 0) and $this->params->get('additional_info')) {
            $html[] = '<p>' . htmlentities($this->params->get('additional_info'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($this->params->get('stripe_test_mode', 1)) {
            $html[] = '<p class="bg-info p-10-5 mt-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_WORKS_SANDBOX') . '</p>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Process payment transaction.
     *
     * @param string                   $context
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params
     *
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     *
     * @return null|PaymentResult
     */
    public function onPaymentsCheckout($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payments.checkout.stripe', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Prepare output data.
        $paymentResponse = new PaymentResult;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE_CHECKOUT'), $this->debugType, $_POST) : null;

        // Get token
        $token = $this->app->input->get('stripeToken');
        if (!$token) {
            throw new UnexpectedValueException(JText::_('PLG_CROWDFUNDINGPAYMENT_STRIPE_ERROR_INVALID_TRANSACTION_DATA'));
        }

        // Prepare description.
        $description = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));

        // Prepare amounts in cents.
        $amount = (int)abs($item->amount * 100);

        // Get API keys
        $apiKeys = $this->getKeys();

        // Get payment session.
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Set your secret key: remember to change this to your live secret key in production
        // See your keys here https://dashboard.stripe.com/account
        Stripe\Stripe::setApiKey($apiKeys['secret']);

        // Get the credit card details submitted by the form
        $token = $this->app->input->post->get('stripeToken');

        // Create the charge on Stripe's servers - this will charge the user's card
        try {
            $charge = Stripe\Charge::create(
                array(
                    'amount'      => $amount, // amount in cents, again
                    'currency'    => $item->currencyCode,
                    'card'        => $token,
                    'description' => $description,
                    'metadata'    => array(
                        'payment_session_id' => $paymentSessionRemote->getId()
                    )
                )
            );

            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CHARGE_RESULT'), $this->debugType, $charge) : null;

            // Store the ID to the payment session as Unique Key.
            $paymentSessionRemote->setUniqueKey($charge->id);
            $paymentSessionRemote->setGateway($this->serviceAlias);
            $paymentSessionRemote->store();
        } catch (Stripe\Error\Card $e) {
            // Generate output data.
            $paymentResponse->redirectUrl = CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug);
            $paymentResponse->message     = $e->getMessage();

            return $paymentResponse;
        }

        // Get next URL.
        $paymentResponse->redirectUrl = CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, 'share');

        // Disable After Events.
        $paymentResponse->triggerEvents = array();

        return $paymentResponse;
    }

    /**
     * This method processes transaction data that comes from the paymetn gateway.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.stripe', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'), $this->errorType, JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod));
            return null;
        }

        // Retrieve the request's body and parse it as JSON
        $input = @file_get_contents('php://input');
        $data  = json_decode($input, true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $data) : null;

        $dataObject = array();
        if (isset($data['data']) and array_key_exists('object', $data['data'])) {
            $dataObject = $data['data']['object'];
        }

        if (!$dataObject) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_DATA_OBJECT'), $this->errorType, $dataObject);
            return null;
        }

        // Prepare the array that have to be returned by this method.
        $paymentResult          = new PaymentResult;

        // Get payment session.
        $paymentSessionId       = ArrayHelper::getValue($dataObject['metadata'], 'payment_session_id', 0, 'int');
        $paymentSessionRemote   = $this->getPaymentSession(array(
            'id' => $paymentSessionId
        ));

        // Check for valid payment session.
        if (!$paymentSessionRemote->getId()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_PAYMENT_SESSION'), $this->errorType, $paymentSessionRemote->getProperties());
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        // Validate the payment gateway.
        $gatewayName = $paymentSessionRemote->getGateway();
        if (!$this->isValidPaymentGateway($gatewayName)) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_GATEWAY'), $this->errorType, $paymentSessionRemote->getProperties());
            return null;
        }

        // Get currency
        $containerHelper  = new Crowdfunding\Container\Helper();
        $currency         = $containerHelper->fetchCurrency($this->container, $params);

        // Validate transaction data
        $validData = $this->validateData($dataObject, $currency->getCode(), $paymentSessionRemote);
        if ($validData === null) {
            return null;
        }

        // Prepare extra data.
        $validData['extra_data'] = $this->prepareExtraData($data);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

        // Set the receiver ID.
        $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
        $validData['receiver_id'] = $project->getUserId();

        // Get reward object.
        $reward = null;
        if ($validData['reward_id']) {
            $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
        }

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transaction = $this->storeTransaction($validData);
        if ($transaction === null) {
            return null;
        }

        //  Prepare the data that will be returned

        // Generate object of data, based on the transaction properties.
        $paymentResult->transaction = $transaction;

        // Generate object of data based on the project properties.
        $paymentResult->project = $project;

        // Generate object of data based on the reward properties.
        if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
            $paymentResult->reward = $reward;
        }

        // Generate data object, based on the payment session properties.
        $paymentResult->paymentSession = $paymentSessionRemote;

        // Removing intention.
        $this->removeIntention($paymentSessionRemote, $transaction);

        return $paymentResult;
    }

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currencyCode
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return array
     */
    protected function validateData($data, $currencyCode, $paymentSession)
    {
        $timestamp = ArrayHelper::getValue($data, 'created');
        $date      = new JDate($timestamp);

        // Prepare transaction status.
        $txnStateResult = ArrayHelper::getValue($data, 'paid', false, 'bool');

        $txnState = 'pending';
        if ($txnStateResult === true) {
            $txnState = 'completed';
        }

        $amount = ArrayHelper::getValue($data, 'amount');
        $amount = (float)($amount <= 0) ? 0 : $amount / 100;

        // Prepare transaction data.
        $transactionData = array(
            'investor_id'      => $paymentSession->getUserId(),
            'project_id'       => $paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->getRewardId(),
            'txn_id'           => ArrayHelper::getValue($data, 'id'),
            'txn_amount'       => $amount,
            'txn_currency'     => $currencyCode,
            'txn_status'       => $txnState,
            'txn_date'         => $date->toSql(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        // Check User Id, Project ID and Transaction ID.
        if (!$transactionData['project_id'] or !$transactionData['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->errorType, $transactionData);
            return null;
        }

        // Check if project record exists in database.
        $projectRecord = new Crowdfunding\Validator\Project\Record(JFactory::getDbo(), $transactionData['project_id']);
        if (!$projectRecord->isValid()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->errorType, $transactionData);
            return null;
        }

        // Check if reward record exists in database.
        if ($transactionData['reward_id'] > 0) {
            $rewardRecord = new Crowdfunding\Validator\Reward\Record(JFactory::getDbo(), $transactionData['reward_id'], array('state' => Prism\Constants::PUBLISHED));
            if (!$rewardRecord->isValid()) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REWARD'), $this->errorType, $transactionData);
                return null;
            }
        }

        return $transactionData;
    }

    /**
     * Save transaction
     *
     * @param array $transactionData
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @return Transaction|null
     */
    protected function storeTransaction($transactionData)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        /** @var Crowdfunding\Transaction\Transaction $transaction */
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction.
        // If the current status is completed, stop the process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Encode extra data
        if (!empty($transactionData['extra_data'])) {
            $transactionData['extra_data'] = json_encode($transactionData['extra_data']);
        } else {
            $transactionData['extra_data'] = null;
        }

        // IMPORTANT: It must be before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        return $transaction;
    }

    /**
     * Get the keys from plug-in options.
     *
     * @return array
     */
    protected function getKeys()
    {
        $keys = array();

        if ($this->params->get('stripe_test_mode', 1)) { // Test server published key.
            $keys['published'] = trim($this->params->get('test_published_key'));
            $keys['secret']    = trim($this->params->get('test_secret_key'));
        } else {// Live server access token.
            $keys['published'] = trim($this->params->get('published_key'));
            $keys['secret']    = trim($this->params->get('secret_key'));
        }

        return $keys;
    }
}
