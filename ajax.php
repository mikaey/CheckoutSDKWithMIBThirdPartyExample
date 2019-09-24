<?php

require_once( 'config.php' );

header( 'Content-Type: application/json' );

////////////////////////////////////////////////////
// Main logic start
////////////////////////////////////////////////////

if( !array_key_exists( 'action', $_POST ) ) {
  errorDie( 'No action specified' );
}

$action = $_POST[ 'action' ];

$valid_actions = [
  'getBAToken',
  'getBAID',
  'createOrder',
  'capturePayment'
];

if( !in_array( $action, $valid_actions ) ) {
  errorDie( 'Invalid action' );
}

$action();

////////////////////////////////////////////////////
// Main logic end
////////////////////////////////////////////////////

function errorDie( $msg ) {
  die( json_encode( [ 'ok' => false, 'error' => $msg ] ) );
}

function generateJWT() {
  $header = [ 'alg' => 'HS256' ];
  $header_json = json_encode( $header );
  $header_b64 = rtrim( base64_encode( $header_json ), '=' );

  $body = [ 'iss' => CLIENT_ID, 'payer_id' => MERCHANT_ID ];
  $body_json = json_encode( $body );
  $body_b64 = rtrim( base64_encode( $body_json ), '=' );

  $signature_input = $header_b64 . '.' . $body_b64;
  $signature_raw = hash_hmac( 'sha256', $signature_input, CLIENT_SECRET, true );
  $signature_b64 = rtrim( base64_encode( $signature_raw ), '=' );

  $jwt = $header_b64 . '.' . $body_b64 . '.' . $signature_b64;

  return $jwt;
}

function getAccessToken() {
  // Note that the access token request is performed without the JWT.
  
  $curl = curl_init( 'https://' . API_HOST . '/v1/oauth2/token' );

  curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
  curl_setopt( $curl, CURLOPT_POST, true );
  curl_setopt( $curl, CURLOPT_POSTFIELDS, 'grant_type=client_credentials' );
  curl_setopt( $curl, CURLOPT_USERPWD, CLIENT_ID . ':' . CLIENT_SECRET );

  $resp = curl_exec( $curl );

  if( false === $resp ) {
    errorDie( 'Communication error while retrieving access token: ' . curl_error( $curl ) );
  }

  $json = json_decode( $resp );
  if( NULL === $json ) {
    errorDie( 'Failed to parse JSON response from PayPal while retrieving access token: ' . json_last_error_msg() );
  }

  if( !property_exists( $json, 'access_token' ) ) {
    errorDie( 'Access token missing from response from PayPal' );
  }

  return $json->access_token;
}

function paypalApiPostCall( $endpoint, $req, $what ) {
  $accessToken = getAccessToken();
  $jwt = generateJWT();

  $curl = curl_init( 'https://' . API_HOST . $endpoint );

  curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
  curl_setopt( $curl, CURLOPT_POST, true );
  curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $req ) );
  curl_setopt( $curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $accessToken",
    "PayPal-Auth-Assertion: $jwt",
    'Content-Type: application/json'
  ] );

  $resp = curl_exec( $curl );

  if( false === $resp ) {
    errorDie( "Communication error occurred while requesting $what: " . curl_error( $curl ) );
  }

  $json = json_decode( $resp );

  if( NULL === $json ) {
    errorDie( "Failed to parse JSON response from PayPal while requesting $what: " . json_last_error_msg() );
  }

  return $json;
}

function findAndReturnProp( $resp, $requiredProp, $propName ) {
  if( !property_exists( $resp, $requiredProp ) ) {
    errorDie( "$propName missing from response (" . json_encode( $resp ) . ")" );
  }

  $json = [
    'ok' => true,
    'id' => $resp->$requiredProp
  ];

  die( json_encode( $json ) );
}

function getBaToken() {
  $req = [
    'payer' => [
      'payment_method' => 'PAYPAL'
    ],
    'plan' => [
      'type' => 'MERCHANT_INITIATED_BILLING',
      'merchant_preferences' => [
        'return_url' => 'https://www.paypal.com/checkoutnow/error',
        'cancel_url' => 'https://www.paypal.com/checkoutnow/error'
      ]
    ]
  ];

  $json = paypalApiPostCall( '/v1/billing-agreements/agreement-tokens', $req, 'billing agreement token' );

  findAndReturnProp( $json, 'token_id', "Token ID" );

}

function getBAID() {
  if( !array_key_exists( 'token', $_POST ) ) {
    errorDie( 'Token missing from request' );
  }
  
  $req = [
    'token_id' => $_POST[ 'token' ]
  ];

  $json = paypalApiPostCall( '/v1/billing-agreements/agreements', $req, 'billing agreement ID' );

  findAndReturnProp( $json, 'id', 'Billing agreement ID' );
}

function createOrder() {
  $req = [
    'intent' => 'CAPTURE',
    'purchase_units' => [
      [
        'amount' => [
          'currency_code' => 'USD',
          'value' => '24.99',
          'breakdown' => [
            'item_total' => [
              'currency_code' => 'USD',
              'value' => '20.00'
            ],
            'shipping' => [
              'currency_code' => 'USD',
              'value' => '2.99'
            ],
            'tax_total' => [
              'currency_code' => 'USD',
              'value' => '2.00'
            ]
          ]
        ],
        'items' => [
          [
            'name' => 'Sprockets',
            'unit_amount' => [
              'currency_code' => 'USD',
              'value' => '5.00'
            ],
            'tax' => [
              'currency_code' => 'USD',
              'value' => '0.50'
            ],
            'quantity' => '2'
          ],
          [
            'name' => 'Widgets',
            'unit_amount' => [
              'currency_code' => 'USD',
              'value' => '2.50'
            ],
            'tax' => [
              'currency_code' => 'USD',
              'value' => '0.25'
            ],
            'quantity' => '4'
          ]
        ]
      ]
    ]
  ];

  $json = paypalApiPostCall( '/v2/checkout/orders', $req, 'order ID' );

  findAndReturnProp( $json, 'id', 'Order ID' );
}

function capturePayment() {
  if( !array_key_exists( 'baid', $_POST ) ) {
    errorDie( 'Billing agreement ID missing from request' );
  }

  if( !array_key_exists( 'orderId', $_POST ) ) {
    errorDie( 'Order ID missing from request' );
  }

  $baid = $_POST[ 'baid' ];
  $orderId = urlencode( $_POST[ 'orderId' ] );

  $req = [
    'payment_source' => [
      'token' => [
        'type' => 'BILLING_AGREEMENT',
        'id' => $baid
      ]
    ]
  ];

  $json = paypalApiPostCall( "/v2/checkout/orders/$orderId/capture", $req, "transaction ID" );

  // This one's a little more complicated; we have to dig through the response to find the transaction ID
  if( !property_exists( $json, 'purchase_units' ) ) {
    errorDie( 'Purchase units missing from response from PayPal' );
  }

  $purchaseUnits = $json->purchase_units;

  if( !is_array( $purchaseUnits ) ) {
    errorDie( 'Response from PayPal was formatted incorrectly (/purchase_units was expected to be an array)' );
  }

  // Since we only sent one purchase unit in, make sure that we only got one purchase unit back
  if( count( $purchaseUnits ) != 1 ) {
    errorDie( 'Too many purchase units in response from PayPal (expected count was 1; actual count was ' . count( $purchaseUnits ) );
  }

  $purchaseUnit = $purchaseUnits[0];

  if( !is_object( $purchaseUnit ) ) {
    errorDie( 'Response from PayPal was formatted incorrectly (/purchase_units[0] was expected to be an object)' );
  }

  if( !property_exists( $purchaseUnit, 'payments' ) ) {
    errorDie( 'Payments missing from response from PayPal' );
  }

  $payments = $purchaseUnit->payments;

  if( !is_object( $payments ) ) {
    errorDie( 'Response from PayPal was formatted incorrectly (/purchase_units[0]/payments was expected to be an object)' );
  }

  if( !property_exists( $payments, 'captures' ) ) {
    errorDie( 'Captures missing from response from PayPal' );
  }

  $captures = $payments->captures;

  if( !is_array( $captures ) ) {
    errorDie( 'Response from PayPal was formatted incorrectly (/purchase_units[0]/payments/captures was expected to be an array)' );
  }

  // Now go through the list of captures and look for one whose status is "COMPLETED"
  $captureIds = [];
  foreach( $captures as $index => $capture ) {
    if( !property_exists( $capture, 'status' ) ) {
      errorDie( "Status missing from capture $index in response from PayPal" );
    }

    $status = $capture->status;

    if( !is_string( $status ) ) {
      errorDie( "Response from PayPal was formatted incorrectly (/purchase_units[0]/payments/captures[$index]/status was expected to be a string)" );
    }

    if( 'COMPLETED' != $status ) {
      continue;
    }

    if( !property_exists( $capture, 'id' ) ) {
      errorDie( "Transaction ID missing from capture $index in response from PayPal" );
    }

    $id = $capture->id;

    if( !is_string( $id ) ) {
      errorDie( "Response from PayPal was formatted incorrectly (/purchase_units[0]/payments/captures/[$index]/id was expected to be a string)" );
    }

    if( !strlen( trim( $id ) ) ) {
      errorDie( "Transaction ID missing from capture $index in response from PayPal" );
    }

    $captureIds[] = $id;
  }

  // Now make sure we have the correct number of successful captures
  if( count( $captureIds ) != 1 ) {
    errorDie( 'Incorrect number of successful captures in response from PayPal (expected count was 1; actual count was ' . count( $captureIds ) );
  }

  die( json_encode( [ 'ok' => true, 'id' => $captureIds[0] ] ) );
}
