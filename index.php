<?php require_once( 'config.php' ); ?><!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Checkout SDK on PayPal Commerce Platform with Channel Initiated Billing</title>
        <script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode( CLIENT_ID ) ?>&vault=true"></script>
    </head>
    <body>
        <h1>Checkout SDK on PayPal Commerce Platform with Channel Initiated Billing</h1>
        <div><span style="font-weight: bold">Checkout status: </span><span id="checkout-status">Waiting for initialization</span></div>
        <div><span style="font-weight: bold">Billing agreement status: </span><span id="ba-status">Not started</span></div>
        <div><span style="font-weight: bold">Reference transaction status: </span><span id="rt-status">Not started</span></div>
        <div id="paypal-button"></div>
        <script>
         function updateStatus(name, text) {
           document.getElementById(name).innerHTML = text;
         }

         updateStatus('checkout-status', 'Rendering PayPal buttons');
         paypal.Buttons({
           // Fired when the buyer clicks the PayPal button
           createBillingAgreement: function(data, actions) {
             // This function needs to return the billing agreement token or a Promise that resolves to the token
             updateStatus('checkout-status', 'Button clicked');
             updateStatus('ba-status', 'Waiting for billing agreement token');

             // Make an Ajax call out to our server to fetch the token
             return fetch('ajax.php', {
               method: 'POST',
               headers: {
                 'Content-Type': 'application/x-www-form-urlencoded'
               },
               body: 'action=getBAToken'
             }).then(function(res) {
               return res.json();
             }).then(function(json) {
               if(json.ok) {
                 updateStatus('ba-status', 'Successfully retrieved billing agreement token ' + json.id);
                 updateStatus('checkout-status', 'Waiting for buyer to complete checkout');
                 return json.id;
               } else {
                 updateStatus('ba-status', 'Error retrieving billing agreement token: ' + json.error);
                 throw new Error('Error retrieving billing agreement token: ' + json.error);
               }
             }).catch(function(err) {
               updateStatus('checkout-status', 'Checkout cancelled due to error: ' + err.message );
             });
           },

           // Fired when the buyer is done on PayPal
           onApprove: function(data, actions) {
             // This function doesn't need to return anything -- it's just indicating that control is being passed back to you
             updateStatus('checkout-status', 'Checkout complete');
             updateStatus('ba-status', 'Waiting for billing agreement ID');
             fetch('ajax.php', {
               method: 'POST',
               headers: {
                 'Content-Type': 'application/x-www-form-urlencoded'
               },
               body: 'action=getBAID&token=' + data.billingToken
             }).then(function(res) {
               return res.json();
             }).then(function(json) {
               if(json.ok) {
                 var baid = json.id;

                 updateStatus('ba-status', 'Successfully retrieved billing agreement ID ' + json.id);
                 updateStatus('rt-status', 'Creating order');

                 // Create the order
                 fetch('ajax.php', {
                   method: 'POST',
                   headers: {
                     'Content-Type': 'application/x-www-form-urlencoded'
                   },
                   body: 'action=createOrder'
                 }).then(function(res) {
                   return res.json();
                 }).then(function(json) {
                   if(json.ok) {
                     var orderId = json.id;
                     updateStatus('rt-status', 'Successfully retrieved order ID ' + orderId + '; capturing payment');

                     // Capture the payment for the order
                     fetch('ajax.php', {
                       method: 'POST',
                       headers: {
                         'Content-Type': 'application/x-www-form-urlencoded'
                       },
                       body: 'action=capturePayment&baid=' + baid + '&orderId=' + orderId
                     }).then(function(res) {
                       return res.json();
                     }).then(function(json) {
                       if(json.ok) {
                         updateStatus('rt-status', 'Payment successful; transaction ID = ' + json.id);
                       } else {
                         updateStatus('rt-status', 'Error while processing transaction: ' + json.error);
                       }
                     }).catch(function(err) {
                       updateStatus('rt-status', 'Communication error occurred while capturing payment: ' + err.message);
                     });
                   } else {
                     updateStatus('rt-status', 'Error while creating order: ' + json.error);
                   }
                 }).catch(function(err) {
                   updateStatus('rt-status', 'Communication error occurred while creating order: ' + err.message);
                 });
               } else {
                 updateStatus('ba-status', 'Error while creating billing agreement: ' + json.error);
               }
             }).catch(function(err) {
               updateStatus('ba-status', 'Communication error while creating billing agreement: ' + err.message);
             });
           }
         }).render('#paypal-button').then(function() {
           updateStatus('checkout-status', 'Waiting for buyer to start checkout');
         }).catch(function(err) {
           updateStatus('checkout-status', 'Error occurred while rendering PayPal buttons: ' + err.message);
         });
                   
        </script>
    </body>
</html>
