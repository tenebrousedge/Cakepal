<?php

include APP."/webroot/ppsdk_include_path.inc";
require_once 'PayPal.php';
require_once 'PayPal/Profile/Handler/Array.php';
require_once 'PayPal/Type/PaymentDetailsItemType.php';
require_once 'PayPal/Type/PaymentDetailsType.php';
require_once 'PayPal/Type/BasicAmountType.php';
require_once 'PayPal/Type/SetExpressCheckoutRequestDetailsType.php';
require_once 'PayPal/Type/SetExpressCheckoutRequestType.php';
require_once 'PayPal/Type/SetExpressCheckoutResponseType.php';
require_once 'PayPal/Type/GetExpressCheckoutDetailsRequestType.php';
require_once 'PayPal/Type/GetExpressCheckoutDetailsResponseDetailsType.php';
require_once 'PayPal/Type/GetExpressCheckoutDetailsResponseType.php';
require_once 'PayPal/Type/DoExpressCheckoutPaymentRequestType.php';
require_once 'PayPal/Type/DoExpressCheckoutPaymentRequestDetailsType.php';
require_once 'PayPal/Type/DoExpressCheckoutPaymentResponseType.php';

class CakepalComponent extends Object {

    var $pp_langs = array(
        'AU' => 'Australia',
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'CN' => 'China',
        'DE' => 'Germany',
        'ES' => 'Spain',
        'GB' => 'United Kingdom',
        'FR' => 'France',
        'IT' => 'Italy',
        'NL' => 'Netherlands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RU' => 'Russia',
        'US' => 'United States'
    );

    public function initialize() {
        if (Configure::load('cakepal')) {
            $this->config = Configure::read('Cakepal.sandbox');
        } else {
            die('Could not load configuration file');
        }
    }

    /**
     * @todo make options actually do something
     * @param type $booking_id
     * @param array $items
     * @param array $options 
     * @return mixed $responseObject
     */
    public function expresspay($booking_id, array $items, $total, array $options = null) {
        $LC = 'US';
        $paymentAction = 'Sale';
        $profile = & $this->getProfile();
        // Set up paypal request
        $lang = Configure::read('Config.language');
        $locale = (in_array($lang, $this->pp_langs)) ? $lang : 'US';
        $ReturnURL = Router::url(array('controller' => 'payments', 'action' => 'expresspay', 'token' => 'confirm'), true);
        $CancelURL = Router::url(array('controller' => 'payments', 'action' => 'choosePaymentMethod'), true);
        $ec_request = & PayPal::getType('setExpressCheckoutRequestType');
        //
        $ec_details = & PayPal::getType('SetExpressCheckoutRequestDetailsType');
        $ec_details->setReturnURL($ReturnURL);
        $ec_details->setCancelURL($CancelURL);
        $ec_details->setReqConfirmShipping('0');
        $ec_details->setNoShipping('1');
        $ec_details->setAllowNote('1');
        $ec_details->setLocaleCode($LC);
        $ec_details->setChannelType('Merchant');
        if (file_exists(WWW_ROOT . DS . 'img' . DS . 'cpp_header.jpg')) {
            $ec_details->setcpp_header_image(WWW_ROOT . DS . 'img' . DS . 'cpp_header.jpg');
        }

        $orderTotal = & PayPal::getType('BasicAmountType');
        $orderTotal->setval($total, 'iso-8859-1');
        $orderTotal->setattr('currencyID', 'USD');
        $ec_details->setOrderTotal($orderTotal);

        $paymentDetailsType = & PayPal::getType('PaymentDetailsType');
        $paymentDetailsType->setAllowedPaymentMethod('InstantPaymentOnly');
        $paymentDetailsType->setPaymentAction('Sale');
        $paymentDetailsType->setInvoiceID($booking_id);


        $itemContainer = array();
        foreach ($items as $key => $pp_item) {
            $PaymentDetailsItem = & PayPal::getType('PaymentDetailsItemType');
            $PaymentDetailsItem->Name = $pp_item['Name'];
            $PaymentDetailsItem->Amount = $pp_item['Amount'];
            $PaymentDetailsItem->Quantity = $pp_item['Quantity'];
            $itemContainer['PaymentDetailsItem0' . "$key"] = $PaymentDetailsItem;
        }
        $paymentDetailsType->setPaymentDetailsItem($itemContainer);


        $itemTotal = & PayPal::getType('BasicAmountType');
        $itemTotal->setval($total);
        $itemTotal->setattr('currencyID', 'USD');
        $paymentDetailsType->setItemTotal($itemTotal);

        $ec_request->setSetExpressCheckoutRequestDetails($ec_details);
        // Get caller services object
        $caller = & PayPal::getCallerServices($profile);
        $caller->USE_ARRAYKEY_AS_TAGNAME = true;
        $caller->SUPRESS_OUTTAG_FOR_ARRAY = true;
        $response = $caller->SetExpressCheckout($ec_request);
        return $response;
    }

    /**
     *
     * @param string $token 
     * @return mixed $data // stuff we need to display
     */
    function getExpressDetails($token) {
        // entirely stolen from the paypal sdk
        $ec_request = & PayPal::getType('GetExpressCheckoutDetailsRequestType');
        $ec_request->setToken($token);

        $caller = & PayPal::getCallerServices($profile);

        // Execute SOAP request
        $response = $caller->GetExpressCheckoutDetails($ec_request);
        $resp_details = $response->getGetExpressCheckoutDetailsResponseDetails();
        $payer_info = $resp_details->getPayerInfo();
        $paymentDetails = $resp_details->getPaymentDetails(); //->getOrderTotal();
        $OrderTotal = $paymentDetails->getOrderTotal();
        $payer_id = $payer_info->getPayerID();
        $invoice_id = $paymentDetails->getInvoiceID();
        $data = compact('OrderTotal', 'invoice_id', 'payer_id');
        return $data;
    }

    /**
     *
     * @param string $token
     * @param string $payerID
     * @param string $paymentAmount
     * @param string $paymentType 
     * @return string $tran_ID
     */
    function doExpressPayment($token, $payerID, $paymentAmount, $paymentType = 'Sale') {
        $ec_details = & PayPal::getType('DoExpressCheckoutPaymentRequestDetailsType');

        $ec_details->setToken($token);
        $ec_details->setPayerID($payerID);
        $ec_details->setPaymentAction($paymentType);

        $amt_type = & PayPal::getType('BasicAmountType');
        $amt_type->setattr('currencyID', 'USD');
        $amt_type->setval($paymentAmount, 'iso-8859-1');

        $payment_details = & PayPal::getType('PaymentDetailsType');
        $payment_details->setOrderTotal($amt_type);

        $ec_details->setPaymentDetails($payment_details);

        $ec_request = & PayPal::getType('DoExpressCheckoutPaymentRequestType');
        $ec_request->setDoExpressCheckoutPaymentRequestDetails($ec_details);

        $caller = & PayPal::getCallerServices($profile);

// Execute SOAP request
        $response = $caller->DoExpressCheckoutPayment($ec_request);
        $details = $response->getDoExpressCheckoutPaymentResponseDetails();
        $payment_info = $details->getPaymentInfo();
        $tran_ID = $payment_info->getTransactionID();
        return $tran_ID;
    }

    /**
     *
     * @return APIProfile 
     */
    function getProfile() {
        require_once 'PayPal.php';
        require_once 'PayPal/Profile/API.php';
        require_once 'PayPal/Profile/Handler.php';
        require_once 'PayPal/Profile/Handler/Array.php';
        $handler = & ProfileHandler_Array::getInstance(array(
                    'username' => $this->config['username'],
                    'certificateFile' => null,
                    'subject' => null,
                    'environment' => $this->config['environment']));
        $pid = ProfileHandler::generateID();

        $profile = new APIProfile($pid, $handler);
        $profile->setAPIPassword($this->config['password']);
        $profile->setAPIUsername($this->config['username']);
        $profile->setEnvironment($this->config['environment']);
        $profile->setSignature($this->config['signature']);
        return $profile;
    }

}

?>
