<?php
/*
 * Plugin Name: MVola Payment Gateway
 * Description: Payment with MVola for woocommerce plugin
 * Author: Mahery
 * Author URI: https://mahery.redstone.mg
 * Version: 1.0
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action('plugins_loaded', 'initialize_gateway_class');
function initialize_gateway_class(){
    class WC_MV_Gateway extends WC_Payment_Gateway  {
        public function __construct(){
            $this->id = 'mvola';
            $this->icon = '';
            $this->has_fields = true;
            $this->title = __('Payment MVola', 'text-domain');
            $this->method_description = __('Payment gateway for MVola mobile money.', 'text-domain');

            $this->support = array('default_credit_card_form');

            //load backend options fields
            $this->init_form_fields();

            //load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->user_phone = $this->get_option('user_phone');
          	$this->consumer_key = $this->get_option('consumer_key');
          	$this->consumer_secret = $this->get_option('consumer_secret');

            //Action hook to saves the settings
            if(is_admin()){
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            //Action hook to looad custom Javascript
            add_action('wp_enqueue_scripts', array($this,'payment_scripts'));
        }

        public function init_form_fields(){
            $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable','text-domain'),
                        'label' => __('Enable MVola Mobile Gateway', 'text-domain'),
                        'type' => 'checkbox',
                        'description' => __('This enable the MVola Mobile gateway which allow to accept payment through MVola','text-domain'),
                        'default' => 'no',
                        'desc_tip' => true
                    ),
                    'title' => array(
                        'title' => __('Title','text-domain'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.','text-domain'),
                        'default' => __('MVola', 'text-domain'),
                        'desc_tip' => true
                    ),
                    'description' => array(
                        'title' => __('Description','text-domain'),
                        'type' => 'textarea',
                        'description' => __('This controls the description which the user sees during checkout.','text-domain'),
                        'default' => __('Pay with MVola mobile money.', 'text-domain'),
                        'desc_tip' => true
                    ),
                    'user_phone' => array(
                        'title'       => __( 'N° MVola', 'text-domain' ),
                        'type'        => 'text'
                    ),
              		'consumer_key' => array(
                        'title'       => __( 'Consumer Key', 'text-domain' ),
                        'type'        => 'text'
                    ),
              		'consumer_secret' => array(
                        'title'       => __( 'Consumer Secret', 'text-domain' ),
                        'type'        => 'password'
                    )
                );
        }

        public function payment_fields(){
            if($description = $this->get_option('description'))
                echo wpautop( wptexturize( $description ) );

            global $woocommerce;

            ?>
            <div class="form-row form-row-wide">
                <label>N° Mvola <span class="required">*</span></label>
                <input id="mvl_number_num" name="mvl_number_num" type="text" autocomplete="off">
            </div>
            <?php
        }

        public function payment_scripts(){
            // process a token only on cart/checkout pages
            if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
                return;
            }

            // stop enqueue JS if payment gateway is disabled
            if ( 'no' === $this->enabled ) {
                return;
            }

            // stop enqueue JS if API keys are not set
            if (empty( $this->user_phone )) {
                return;
            }
          
          	// stop enqueue JS if API keys are not set
            if (empty( $this->consumer_key )) {
                return;
            }
          
          	// stop enqueue JS if API keys are not set
            if (empty( $this->consumer_secret )) {
                return;
            }

            // stop enqueue JS if test mode is enabled
            if ( ! $this->test_mode ) {
                return;
            }

            // stop enqueue JS if site without SSL
            if ( ! is_ssl() ) {
                return;
            }

            // payment processor JS that allows to get a token
            // wp_enqueue_script( 'mvola_js', 'https://www.example.com/api/get-token.js' );

            // custom JS that works with get-token.js
            wp_register_script( 'woocommerce_pay_mvola', plugins_url( 'mvola-script.js', __FILE__ ), array( 'jquery', 'mvola_js' ) );

            // use public key to get token
            wp_localize_script( 'woocommerce_pay_mvola', 'mvola_params', array(
                'publishKey' => $this->publish_key
            ) );

            wp_enqueue_script( 'woocommerce_pay_mvola' );
        }

        public function validate_fields(){
            if( empty( $_POST[ 'billing_first_name' ]) ) {
                wc_add_notice(  'First name is required!', 'error' );
                return false;
            }
            if( empty( $_POST[ 'billing_email' ]) ) {
                wc_add_notice(  'Email is required!', 'error' );
                return false;
            }
          if( empty( $_POST['mvl_number_num']) ) {
                wc_add_notice(  'MVola number is required!', 'error' );
                return false;
            }
            return true;
        }

        public function process_payment( $order_id ) {

            global $woocommerce;
         
            // get order detailes
            $order = wc_get_order( $order_id );
         
            // Array with arguments for API interaction
            $args = array(
                'headers' => array(
                  	'method' => 'POST',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->consumer_key.':'.$this->consumer_secret)
                ),
              'body' => array('grant_type'=>'client_credentials','scope'=>'EXT_INT_MVOLA_SCOPE')
            );
            
            $getAccessToken = wp_remote_post( 'https://devapi.mvola.mg/token', $args );
         
            if( !is_wp_error( $getAccessToken ) ) {
         
                $body = json_decode( $getAccessToken['body'], true );
         
                // it could be different depending on your payment processor
                if ( $body['access_token'] && $body['scope'] == 'EXT_INT_MVOLA_SCOPE' ) {
         
                  	$session_token = $body['access_token'];
                  	$args_pay = array(
                          'headers' => array(
                              'method' => 'POST',
                              'Content-Type: application/json',
                              'UserLanguage: FR',
                              'UserAccountIdentifier: msisdn;'.$this->user_phone,
                              'Authorization' => 'Bearer ' . $session_token
                          ),
                        'body' => array(
                          'amount'=>floor($order['total']),
                          'currency'=>'Ar',
                          'descriptionText'=>'Payment via MVola af order #'.$order['id'],
                          'requestDate'=>date('yyyy-MM-ddTHH:mm:ss.SSSZ'),
                          'debitParty'=>$_POST['mvl_number_num'],
                          'creditParty'=>$this->user_phone,
                          'metadata'=>'Redstone Madagascar',
                          'requestingOrganisationTransactionReference'=>$order['id'],
                        )
                      );
                  
                  	$response = wp_remote_post( 'https://devapi.mvola.mg//mvola/mm/transactions/type/merchantpay/1.0.0/', $args_pay );
                  
                    echo $response['body'];
                  
                    if(!is_wp_error( $response )){
                        
                    	if(false){
                        	// we received the payment
                            $order->payment_complete();
                            $order->reduce_order_stock();

                            // notes to customer
                            $order->add_order_note( 'Hey, your order is paid! Thank you!', true );
                            $order->add_order_note( 'This private note shows only on order edit page', false );

                            // empty cart
                            $woocommerce->cart->empty_cart();

                            // redirect to the thank you page
                            return array(
                                'result' => 'success',
                                'redirect' => $this->get_return_url( $order )
                            );
                        }else {
                          wc_add_notice(  'Payment error please try again.', 'error' );
                          return;
                      }
                    }else {
                      wc_add_notice(  'Payment connection error.', 'error' );
                      return;
                  }
         
                } else {
                    wc_add_notice(  'Please try again.', 'error' );
                    return;
                }
         
            } else {
                wc_add_notice(  'Connection error.', 'error' );
                return;
            }
         
        }
    }
}

add_filter( 'woocommerce_payment_gateways', 'add_custom_gateway_class' );
function add_custom_gateway_class( $gateways ) {
    $gateways[] = 'WC_MV_Gateway'; // payment gateway class name
    return $gateways;
}