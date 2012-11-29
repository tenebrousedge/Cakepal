<?php

$config['Cakepal'] = array(
    'sandbox' => array(
        'environment' => 'sandbox', // 'sandbox' or 'live'
        'mode' => 'sandbox',
        'username' => 'sdk-three_api1.sdk.com',
        'password' => '',
        'signature' => '',
        'subject' => '',
        'url' => 'https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=',
        'certificate' => ''
    ),
    'live' => array(
        'environment' => 'live',
        'mode' => 'live',
        'username' => '',
        'password' => '',
        'signature' => '',
        'subject' => '',
        'url' => 'https://www.paypal.com/webscr?cmd=_express-checkout&token=',
        'certificate' => ''
        ));
