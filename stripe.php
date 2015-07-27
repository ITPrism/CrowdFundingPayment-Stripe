<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport("Prism.init");
jimport("Crowdfunding.init");
jimport("EmailTemplates.init");

/**
 * Crowdfunding Stripe Payment Plug-in
 *
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 */
class plgCrowdfundingPaymentStripe extends Crowdfunding\Payment\Plugin
{
    protected $paymentService = "stripe";

    protected $textPrefix = "PLG_CROWDFUNDINGPAYMENT_STRIPE";
    protected $debugType = "STRIPE_PAYMENT_PLUGIN_DEBUG";

    /**
     * @var JApplicationSite
     */
    protected $app;
    
    protected $extraDataKeys = array(
        "object", "id", "created", "livemode", "type", "pending_webhooks", "request", "paid",
        "amount", "currency", "captured", "balance_transaction", "failure_message", "failure_code",
        "data"
    );

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param object                   $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/stripe";

        // Load the script that initialize the select element with banks.
        JHtml::_("jquery.framework");

        // Get access token
        $apiKeys = $this->getKeys();

        $html   = array();
        $html[] = '<div class="well">';
        $html[] = '<h4><img src="' . $pluginURI . '/images/stripe_icon.png" width="32" height="32" alt="Stripe" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';

        if (!$apiKeys["published"] or !$apiKeys["secret"]) {
            $html[] = '<p class="bg-warning p-10-5"><span class="glyphicon glyphicon-warning-sign"></span> ' . JText::_($this->textPrefix . "_ERROR_CONFIGURATION") . '</p>';
            $html[] = '</div>'; // Close the div "well".
            return implode("\n", $html);
        }

        // Get image
        $dataImage = (!$this->params->get("logo")) ? "" : 'data-image="' . $this->params->get("logo") . '"';

        // Get company name.
        if (!$this->params->get("company_name")) {
            $dataName = 'data-name="' . htmlentities($this->app->get("sitename"), ENT_QUOTES, "UTF-8") . '"';
        } else {
            $dataName = 'data-name="' . htmlentities($this->params->get("company_name"), ENT_QUOTES, "UTF-8") . '"';
        }

        // Get project title.
        $dataDescription = JText::sprintf($this->textPrefix . "_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8"));

        // Get amount.
        $dataAmount = abs($item->amount * 100);

        $dataPanelLabel = (!$this->params->get("panel_label")) ? "" : 'data-panel-label="' . $this->params->get("panel_label") . '"';
        $dataLabel      = (!$this->params->get("label")) ? "" : 'data-label="' . $this->params->get("label") . '"';

        // Prepare optional data.
        $optionalData = array($dataLabel, $dataPanelLabel, $dataName, $dataImage);
        $optionalData = array_filter($optionalData);

        $html[] = '<form action="/index.php?com_crowdfunding" method="post">';
        $html[] = '<script
            src="https://checkout.stripe.com/checkout.js" class="stripe-button"
            data-key="' . $apiKeys["published"] . '"
            data-description="' . $dataDescription . '"
            data-amount="' . $dataAmount . '"
            data-currency="' . $item->currencyCode . '"
            data-allow-remember-me="' . $this->params->get("remember_me", "true") . '"
            data-zip-code="' . $this->params->get("zip_code", "false") . '"
            ' . implode("\n", $optionalData) . '
            >
          </script>';
        $html[] = '<input type="hidden" name="pid" value="' . (int)$item->id . '" />';
        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="payment_service" value="stripe" />';
        $html[] = JHtml::_('form.token');
        $html[] = '</form>';

        if ($this->params->get('display_info', 0) and $this->params->get('additional_info')) {
            $html[] = "<p>" . htmlentities($this->params->get('additional_info'), ENT_QUOTES, "UTF-8") . "</p>";
        }

        if ($this->params->get('stripe_test_mode', 1)) {
            $html[] = '<p class="bg-info p-10-5 mt-5"><span class="glyphicon glyphicon-info-sign"></span> ' . JText::_($this->textPrefix . "_WORKS_SANDBOX") . '</p>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Process payment transaction.
     *
     * @param string                   $context
     * @param object                   $item
     * @param Joomla\Registry\Registry $params
     *
     * @return null|array
     */
    public function onPaymentsCheckout($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payments.checkout.stripe", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // Prepare output data.
        $output = array(
            "redirect_url" => "",
            "message"      => ""
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE_CHECKOUT"), $this->debugType, $_POST) : null;

        // Get token
        $token = $this->app->input->get("stripeToken");
        if (!$token) {
            throw new UnexpectedValueException(JText::_("PLG_CROWDFUNDINGPAYMENT_STRIPE_ERROR_INVALID_TRANSACTION_DATA"));
        }

        // Prepare description.
        $description = JText::sprintf($this->textPrefix . "_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8"));

        // Prepare amounts in cents.
        $amount = abs($item->amount * 100);

        // Get API keys
        $apiKeys = $this->getKeys();

        // Get payment session.
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            "session_id"    => $paymentSessionLocal->session_id
        ));

        // Import Stripe library.
        jimport("Prism.Payment.Stripe.Stripe");

        // Set your secret key: remember to change this to your live secret key in production
        // See your keys here https://dashboard.stripe.com/account
        Stripe::setApiKey($apiKeys["secret"]);

        // Get the credit card details submitted by the form
        $token = $this->app->input->post->get('stripeToken');

        // Create the charge on Stripe's servers - this will charge the user's card
        try {

            $charge = Stripe_Charge::create(
                array(
                    "amount"      => $amount, // amount in cents, again
                    "currency"    => $item->currencyCode,
                    "card"        => $token,
                    "description" => $description,
                    "metadata"    => array(
                        "payment_session_id" => $paymentSession->getId()
                    )
                )
            );

            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CHARGE_RESULT"), $this->debugType, $charge) : null;

            // Store the ID to the payment session as Unique Key.
            $paymentSession->setUniqueKey($charge->id);
            $paymentSession->setGateway("Stripe");
            $paymentSession->store();
            
        } catch (Stripe_CardError $e) {

            // Generate output data.
            $output["redirect_url"] = CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug);
            $output["message"]      = $e->getMessage();

            return $output;
        }

        // Get next URL.
        $output["redirect_url"] = CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, "share");

        return $output;
    }

    /**
     * This method processes transaction data that comes from the paymetn gateway.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.stripe", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp("POST", $requestMethod) != 0) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_REQUEST_METHOD"),
                "STRIPE_PAYMENT_PLUGIN_ERROR",
                JText::sprintf($this->textPrefix . "_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );

            return null;
        }

        // Retrieve the request's body and parse it as JSON
        $input = @file_get_contents("php://input");
        $data  = json_decode($input, true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $data) : null;

        $dataObject = array();
        if (isset($data["data"]) and isset($data["data"]["object"])) {
            $dataObject = $data["data"]["object"];
        }

        if (!$dataObject) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_DATA_OBJECT"),
                "STRIPE_PAYMENT_PLUGIN_ERROR",
                $dataObject
            );

            return null;
        }

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => $this->paymentService
        );

        // Get payment session.
        $paymentSessionId = Joomla\Utilities\ArrayHelper::getValue($dataObject["metadata"], "payment_session_id", 0, "int");
        $paymentSession = $this->getPaymentSession(array(
            "id" => $paymentSessionId
        ));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_SESSION"), $this->debugType, $paymentSession->getProperties()) : null;

        // Validate the payment gateway.
        $gatewayName = $paymentSession->getGateway();
        if (!$this->isValidPaymentGateway($gatewayName)) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PAYMENT_GATEWAY"),
                "STRIPE_PAYMENT_PLUGIN_ERROR",
                $paymentSession->getProperties()
            );

            return null;
        }

        // Get currency
        $currencyId = $params->get("project_currency");
        $currency   = Crowdfunding\Currency::getInstance(JFactory::getDbo(), $currencyId);

        // Validate transaction data
        $validData = $this->validateData($dataObject, $currency->getCode(), $paymentSession);
        if (is_null($validData)) {
            return $result;
        }

        // Prepare extra data.
        $validData["extra_data"] = $this->prepareExtraData($data);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

        // Get project
        $projectId = Joomla\Utilities\ArrayHelper::getValue($validData, "project_id");
        $project   = Crowdfunding\Project::getInstance(JFactory::getDbo(), $projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {
            $error = JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT");
            $error .= "\n" . JText::sprintf($this->textPrefix . "_TRANSACTION_DATA", var_export($validData, true));
            JLog::add($error);

            return $result;
        }

        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transactionData = $this->storeTransaction($validData, $project);
        if (is_null($transactionData)) {
            return $result;
        }

        // Update the number of distributed reward.
        $rewardId = Joomla\Utilities\ArrayHelper::getValue($transactionData, "reward_id");
        $reward   = null;
        if (!empty($rewardId)) {
            $reward = $this->updateReward($transactionData);

            // Validate the reward.
            if (!$reward) {
                $transactionData["reward_id"] = 0;
            }
        }

        //  Prepare the data that will be returned

        $result["transaction"] = Joomla\Utilities\ArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result["project"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = Joomla\Utilities\ArrayHelper::toObject($properties);
        }

        // Generate data object, based on the payment session properties.
        $properties                = $paymentSession->getProperties();
        $result["payment_session"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        // Remove payment session.
        $txnStatus = (isset($result["transaction"]->txn_status)) ? $result["transaction"]->txn_status : null;
        $this->closePaymentSession($paymentSession, $txnStatus);

        return $result;

    }

    /**
     * This method is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params      Component parameters
     * @param object $project     Project data
     * @param object $reward      Reward data
     * @param object $paymentSession   Payment session data.
     *
     * @return void
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify." . $this->paymentService, $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params, $reward);
    }

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currencyCode
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @return array
     */
    protected function validateData($data, $currencyCode, $paymentSession)
    {
        $timesamp = Joomla\Utilities\ArrayHelper::getValue($data, "created");
        $date     = new JDate($timesamp);

        // Prepare transaction status.
        $txnState = Joomla\Utilities\ArrayHelper::getValue($data, "paid", false, "bool");
        if ($txnState === true) {
            $txnState = "completed";
        } else {
            $txnState = "pending";
        }

        $amount = Joomla\Utilities\ArrayHelper::getValue($data, "amount");
        $amount = (float)($amount <= 0) ? 0 : $amount / 100;

        // Prepare transaction data.
        $transaction = array(
            "investor_id"      => $paymentSession->getUserId(),
            "project_id"       => $paymentSession->getProjectId(),
            "reward_id"        => ($paymentSession->isAnonymous()) ? 0 : $paymentSession->getRewardId(),
            "txn_id"           => Joomla\Utilities\ArrayHelper::getValue($data, "id"),
            "txn_amount"       => $amount,
            "txn_currency"     => $currencyCode,
            "txn_status"       => $txnState,
            "txn_date"         => $date->toSql(),
            "service_provider" => Joomla\String\String::ucfirst($this->paymentService),
        );

        // Check User Id, Project ID and Transaction ID.
        if (!$transaction["project_id"] or !$transaction["txn_id"]) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                "STRIPE_PAYMENT_PLUGIN_ERROR",
                $transaction
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        $keys        = array(
            "txn_id" => Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        if ($transaction->getId()) {

            // If the current status if completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Encode extra data
        if (!empty($transactionData["extra_data"])) {
            $transactionData["extra_data"] = json_encode($transactionData["extra_data"]);
        } else {
            $transactionData["extra_data"] = null;
        }

        // Store the new transaction data.
        $transaction->bind($transactionData);
        $transaction->store();

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }


        // update project funded amount.
        $amount = Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->storeFunds();

        return $transactionData;
    }

    /**
     * Get the keys from plug-in options.
     *
     * @return array
     */
    protected function getKeys()
    {
        $keys = array();

        if ($this->params->get("stripe_test_mode", 1)) { // Test server published key.
            $keys["published"] = Joomla\String\String::trim($this->params->get("test_published_key"));
            $keys["secret"]    = Joomla\String\String::trim($this->params->get("test_secret_key"));
        } else {// Live server access token.
            $keys["published"] = Joomla\String\String::trim($this->params->get("published_key"));
            $keys["secret"]    = Joomla\String\String::trim($this->params->get("secret_key"));
        }

        return $keys;
    }
}
