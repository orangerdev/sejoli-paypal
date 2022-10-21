<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use SejoliSA\Admin\Product as AdminProduct;
use SejoliSA\JSON\Product;
use SejoliSA\Model\Affiliate;
use Illuminate\Database\Capsule\Manager as Capsule;

final class SejoliPaypal extends \SejoliSA\Payment{

    /**
     * Prevent double method calling
     * @since   1.0.0
     * @access  protected
     * @var     boolean
     */
    protected $is_called = false;

    /**
     * Table name
     * @since 1.0.0
     * @var string
     */
    protected $table = 'sejolisa_paypal_transaction';

    /**
     * Construction
     */
    public function __construct() {
        
        global $wpdb;

        $this->id          = 'paypal';
        $this->name        = __( 'Paypal', 'sejoli-paypal' );
        $this->title       = __( 'Paypal', 'sejoli-paypal' );
        $this->description = __( 'Transaksi via Paypal Payment Gateway.', 'sejoli-paypal' );
        $this->table       = $wpdb->prefix . $this->table;

        add_action( 'admin_init', [$this, 'register_trx_table'], 1 );
        add_action( 'parse_request', [$this, 'check_parse_request'], 1 );
        add_filter( 'sejoli/payment/payment-options', [$this, 'add_payment_options'], 100 );
        add_action( 'sejoli/thank-you/render', [$this, 'check_for_redirect'], 100 );

    }

    /**
     * Register transaction table
     * Hooked via action admin_init, priority 1
     * @since   1.0.0
     * @return  void
     */
    public function register_trx_table() {

        if( !Capsule::schema()->hasTable( $this->table ) ):

            Capsule::schema()->create( $this->table, function( $table ) {
                $table->increments('ID');
                $table->datetime('created_at');
                $table->datetime('last_update')->nullable();
                $table->integer('order_id');
                $table->string('status');
                $table->string('ref')->nullable();
                $table->text('payload')->nullable();
            });

        endif;

    }

    /**
     * Get duitku order data
     * @since   1.0.0
     * @param   int $order_id
     * @return  false|object
     */
    protected function check_data_table( int $order_id ) {

        return Capsule::table( $this->table )
            ->where(array(
                'order_id' => $order_id
            ))
            ->first();

    }

    /**
     * Add transaction data
     * @since   1.0.0
     * @param   integer $order_id Order ID
     * @return  void
     */
    protected function add_to_table( int $order_id ) {

        Capsule::table( $this->table )
            ->insert([
                'created_at' => current_time( 'mysql' ),
                'order_id'   => $order_id,
                'status'     => 'pending'
            ]);
    
    }

    /**
     * Update data status
     * @since   1.0.0
     * @param   integer $order_id [description]
     * @param   string $status [description]
     * @return  void
     */
    protected function update_status( $order_id, $status ) {
  
        Capsule::table( $this->table )
            ->where(array(
                'order_id' => $order_id
            ))
            ->update(array(
                'status'      => $status,
                'last_update' => current_time( 'mysql' )
            ));

    }

    /**
     * Update data detail payload
     * @since   1.0.0
     * @param   integer $order_id [description]
     * @param   array $detail [description]
     * @return  void
     */
    protected function update_detail( $order_id, $detail ) {
    
        Capsule::table( $this->table )
            ->where(array(
                'order_id' => $order_id
            ))
            ->update(array(
                'payload' => serialize( $detail ),
            ));

    }

    /**
     * Set custom query vars
     * Hooked via filter query_vars, priority 100
     * @since   1.0.0
     * @access  public
     * @param   array $vars
     * @return  array
     */
    public function set_query_vars( $vars ) {

        $vars[] = 'paypal-method';

        return $vars;
    
    }

    /**
     * Completed an order
     * @since   1.0.0
     * @param   int $order_id
     * @return  void
     */
    protected function complete_order( int $order_id ) {

        $response = sejolisa_get_order( array( 'ID' => $order_id ) );

        if( false !== $response['valid'] ) :

            $order   = $response['orders'];
            $product = $order['product'];

            // if product is need of shipment
            if( false !== $product->shipping['active'] ) :
                
                $status = 'in-progress';
            
            else :
            
                $status = 'completed';
            
            endif;

            // call parent method class
            $this->update_order_status( $order['ID'] );

            $args['status'] = $status;

            do_action( 'sejoli/log/write', 'paypal-update-order', $args );
    
        else :
   
            do_action( 'sejoli/log/write', 'paypal-wrong-order', [] );
   
        endif;

    }

    /**
     * Check parse query and if paypal-method exists and do the proses
     * Hooked via action parse_query, priority 999
     * @since   1.0.0
     * @access  public
     * @return  void
     */
    public function check_parse_request() {

        global $wp_query;
    
        if( is_admin() || $this->is_called ) :
      
            return;
      
        endif;
      
        if( isset( $_GET['paypal-ipn'] ) && false !== boolval( $_GET['paypal-ipn'] ) ) :
            
            if ( carbon_get_theme_option( 'paypal_mode' ) == 'sandbox' ) {
                
                $key = carbon_get_theme_option( 'paypal_secret_sandbox' );
            
            } else {

                $key = carbon_get_theme_option( 'paypal_secret_live' );
            
            }

            $data      = json_decode( sanitize_text_field( stripslashes($_POST['data'] ) ), true );
            $encoded   = json_encode( $data );
            $checkHash = hash_hmac( 'sha256', $encoded, $key );
            
            if ( hash_equals( $checkHash, sanitize_text_field( $_POST['hash'] ) ) ) {
            
                // Update order status
                if ($data['action'] == 1) {
            
                    $prefix   = carbon_get_theme_option( 'paypal_inv_prefix' );
                    $order_id = substr( $data['invoice_id'], strlen( $prefix ), strlen( $data['invoice_id'] ) - strlen( $prefix ) + 1 );
                
                    $this->complete_order( $order_id );
     
                } elseif ( $data['action'] == 7 ) {
                    
                    global $wpdb;
                    
                    $prefix   = carbon_get_theme_option( 'paypal_inv_prefix' );
                    $order_id = substr( $data['invoice_id'], strlen( $prefix ), strlen( $data['invoice_id'] ) - strlen( $prefix ) + 1 );
                    $commData = Capsule::table( $wpdb->prefix . 'sejolisa_affiliates' )
                                ->where(array(
                                    'order_id'  => $order_id
                                ))
                                ->update(['paid_status' => 1]);
                }

                do_action( 'sejoli/log/write', 'ipn-paypal-success', array( 'payload', $_POST ) ); 
                header( 'content-type: application/json' );
                die( json_encode( ['success' => 1] ) );

            } else {

                $_POST['calcHash'] = $checkHash;
                $_POST['key']      = $key;
                $_POST['encoded']  = $encoded;
                $_POST['dataP']    = $data;
                
                do_action( 'sejoli/log/write', 'ipn-paypal-failed', array( 'payload', $_POST ) );
                header( 'content-type: application/json' );
                die( json_encode( ['success' => 0, 'msg' => 'invalid data'] ) );

            }
                
        endif;

        $this->is_called = true; // PREVENT DOUBLE CALLED

    }

    /**
     * Set option in Sejoli payment options, we use CARBONFIELDS for plugin options
     * Called from parent method
     * @since   1.0.0
     * @return  array
     */
    public function get_setup_fields() {

        return array(

            // Read https://docs.carbonfields.net/#/ for further information on using carbon fields

            Field::make('separator', 'sep_paypal_tranaction_setting', __('Pengaturan Paypal', 'sejoli-paypal')),

            Field::make('checkbox', 'paypal_active', __('Aktifkan pembayaran melalui paypal', 'sejoli-paypal')),
            
            Field::make('select', 'paypal_mode', __('Payment Mode', 'sejoli-paypal'))
            ->set_options(array(
                'sandbox' => 'Sandbox',
                'live'    => 'live'
            )),

            Field::make('text', 'paypal_client_id_sandbox', __('Client ID Sandbox', 'sejoli-paypal'))
            ->set_required(true)
            ->set_conditional_logic(array(
                array(
                    'field' => 'paypal_active',
                    'value' => true
                ),array(
                    'field' => 'paypal_mode',
                    'value' => 'sandbox'
                )
            )),

            Field::make('text', 'paypal_client_secret_sandbox', __('Client Secret Sandbox', 'sejoli-paypal'))
            ->set_required(true)
            ->set_conditional_logic(array(
                array(
                    'field' => 'paypal_active',
                    'value' => true
                ),array(
                    'field' => 'paypal_mode',
                    'value' => 'sandbox'
                )
            )),

            Field::make('text', 'paypal_client_id_live', __('Client ID Live', 'sejoli-paypal'))
            ->set_required(true)
            ->set_conditional_logic(array(
                array(
                    'field' => 'paypal_active',
                    'value' => true
                ),array(
                    'field' => 'paypal_mode',
                    'value' => 'live'
                )
            )),

            Field::make('text', 'paypal_client_secret_live', __('Client Secret Live', 'sejoli-paypal'))
            ->set_required(true)
            ->set_conditional_logic(array(
                array(
                    'field' => 'paypal_active',
                    'value' => true
                ),array(
                    'field' => 'paypal_mode',
                    'value' => 'live'
                )
            )),

            Field::make('text', 'paypal_inv_prefix', __('Invoice Prefix', 'sejoli-paypal'))
            ->set_required(true)
            ->set_default_value('sjl1')
            ->set_help_text('Maksimal 6 Karakter')
            ->set_conditional_logic(array(
                array(
                    'field' => 'paypal_active',
                    'value' => true
                )
            )),
        );

    }

    /**
     * Display paypal payment options in checkout page
     * Hooked via filter sejoli/payment/payment-options, priority 100
     * @since   1.0.0
     * @param   array $options
     * @return  array
     */
    public function add_payment_options( array $options ) {

        $active = boolval( carbon_get_theme_option('paypal_active') );

        if( true === $active ) :

            // EXAMPLE!!
            // Listing available payment channels from your payment gateways
            $methods = array(
                'paypal'
            );

            foreach($methods as $method_id) :

                // MUST PUT ::: after payment ID
                $key = 'paypal:::' . $method_id;

                switch( $method_id ) :

                    case 'paypal' :

                        $options[$key] = [
                            'label' => __( 'Transaksi via Paypal', 'sejoli-paypal' ),
                            'image' => plugin_dir_url( __FILE__ ) . 'img/paypal.png'
                        ];

                        break;

                endswitch;

            endforeach;

        endif;

        return $options;

    }

    /**
     * Set order price if there is any fee need to be added
     * @since   1.0.0ddddddd
     * @param   float $price
     * @param   array $order_data
     * @return  float
     */
    public function set_price( float $price, array $order_data ) {

        if( 0.0 !== $price ) :

            $this->order_price = $price;

            return floatval( $this->order_price );

        endif;

        return $price;

    }

    /**
     * Set order meta data
     * @since   1.0.0
     * @param   array $meta_data
     * @param   array $order_data
     * @param   array $payment_subtype
     * @return  array
     */
    public function set_meta_data( array $meta_data, array $order_data, $payment_subtype ) {

        $meta_data['paypal'] = [
            'trans_id'   => '',
            'unique_key' => substr( md5( rand(0,1000 ) ), 0, 16 ),
            'method'     => $payment_subtype
        ];

        return $meta_data;

    }

    /**
     * Currency Converter IDR to USD
     * @since   1.0.0
     * @return  float
     */
    public function currency_convert( $amount ) {
 
        $url  = 'https://api.exchangerate-api.com/v4/latest/USD';
        $json = file_get_contents($url);
        $exp  = json_decode($json);

        $idr     = $exp->rates->IDR;
        $convert = $amount / $idr;

        return round( $convert, 2 );
    
    }

    /**
     * Prepare Paypal Data
     * @since   1.0.0
     * @return  array
     */
    public function prepare_paypal_data( $order ) {

        $redirect_link     = '';
        $request_to_paypal = false;
        $data_order        = $this->check_data_table( $order['ID'] );
        $request_to_paypal = true;

        if ( $request_to_paypal === true ) {
            
            $mode = carbon_get_theme_option( 'paypal_mode' );
            
            if ( $mode === 'live' ) {

                $apiUri        = 'https://api-m.paypal.com/v1';
                $client_id     = carbon_get_theme_option( 'paypal_client_id_live' );
                $client_secret = carbon_get_theme_option( 'paypal_client_secret_live' );
                $token         = get_option( 'paypal_live_token' );
                $tokenExpiry   = get_option( 'paypal_live_token_expiration' );
                $baseAppUrl    = 'https://api-m.paypal.com';
     
            } else {

                $apiUri        = 'https://api-m.sandbox.paypal.com/v1';
                $client_id     = carbon_get_theme_option( 'paypal_client_id_sandbox' );
                $client_secret = carbon_get_theme_option( 'paypal_client_secret_sandbox' );
                $token         = get_option( 'paypal_sandbox_token' );
                $tokenExpiry   = get_option( 'paypal_sandbox_token_expiration' );
                $baseAppUrl    = 'https://api-m.sandbox.paypal.com';
            
            }

            if ( !empty( $token ) && !empty( $tokenExpiry ) && $tokenExpiry > time() ) {
                // use last token
            } else {
                $token = $this->paypal_renew_token( $apiUri, $client_id, $client_secret );
            }
                            
            $affComm = [];
            if (isset($order['affiliate']) && !empty($order['affiliate'])) {
     
                global $wpdb;
     
                $aff      = $order['affiliate']->data;
                $commData = Capsule::table( $wpdb->prefix . 'sejolisa_affiliates' )
                            ->where(array(
                                'order_id'  => $order['ID']
                            ))
                            ->first();

                if ( isset( $commData->ID ) && !empty( $commData->ID ) ) {
 
                    $affComm[] = [
                        'email'               => $aff->user_email,
                        'name'                => $aff->display_name,
                        'share_amount'        => $commData->commission,
                        'rebill_share_amount' => 0,
                        'merchant_id'         => $client_id,
                    ];
 
                }
 
            }
            
            // check order type to determince recurring rules
            $recurringAmount = 0;
            $period          = '';
            $rebillTimes     = '0';

            if ( strpos( $order['type'], 'subscription' ) !== FALSE ) {

                $recurringAmount = $order['product']->subscription['regular']['price'];
                $period          = $order['product']->subscription['regular']['duration'] . strtoupper( substr( $order['product']->subscription['regular']['period'], 0, 1 ) );
                $rebillTimes     = 99999;

            } 

            $redirect_urls = home_url( "checkout/thank-you/?order_id=".$order['ID']."/" );
            $url_success   = home_url( 'member-area' );
            
            if ( !empty( $token ) ) {
        
                $payID    = isset( $_GET['paymentId'] ) ? $_GET['paymentId'] : '';
                $payToken = isset( $_GET['token'] ) ? $_GET['token'] : '';
                $payerID  = isset( $_GET['PayerID'] ) ? $_GET['PayerID'] : '';

                if ( $payID && $payToken && $payerID ) {
   
                    $data_order = $this->check_data_table( $order['ID'] ); 
                    $detail     = unserialize( $data_order->payload );

                    $executeDataArray = array (
                        'payer_id' => $payerID,
                    );

                    $executeData = json_encode( $executeDataArray );

                    $executeTransaction = $this->executeTransaction( $detail['executePaymentUrl'], $token, $executeData );

                    $args = array(
                        'ID'     => $order['ID'],
                        'status' => 'payment-confirm'
                    );

                    if ( $executeTransaction['state'] === 'approved' ) {
                        $this->update_status( $order['ID'], 'paid' );
                        sejolisa_update_order_status( $args );

                        wp_redirect( $redirect_urls );
                        exit;

                    }
                    
                } else {

                    if ( isset( $order['meta_data']['shipping_data'] ) ) {

                        $grand_total               = $this->currency_convert( $order['grand_total'] );
                        $subtotal                  = $this->currency_convert( $order['grand_total'] ) - $this->currency_convert($order['meta_data']['shipping_data']['cost']);
                        $product_price             = $this->currency_convert( $order['grand_total'] ) - $this->currency_convert($order['meta_data']['shipping_data']['cost']); 
                        $receiver_destination_id   = $order['meta_data']['shipping_data']['district_id'];
                        $receiver_destination_city = sejolise_get_subdistrict_detail( $receiver_destination_id );
                        $receiver_city             = $receiver_destination_city['type'].' '.$receiver_destination_city['city'];
                        $receiver_province         = $receiver_destination_city['province'];
                        $shipping_cost             = $this->currency_convert($order['meta_data']['shipping_data']['cost']);
                        $recipient_name            = $order['meta_data']['shipping_data']['receiver'];
                        $recipient_address         = $order['address'];
                        $recipient_phone           = $order['meta_data']['shipping_data']['phone'];

                        $postDataArray = array (
                            'intent' => 'sale',
                            'payer'  => array (
                                'payment_method' => 'paypal',
                            ),
                            'transactions' => array (
                                0 => array (
                                    'amount' => array (
                                        'total'    => ''.$grand_total.'',
                                        'currency' => 'USD',
                                        'details'  => array (
                                            'subtotal'          => ''.$subtotal.'',
                                            'tax'               => '0.00',
                                            'shipping'          => ''.$shipping_cost.'',
                                            'handling_fee'      => '0.00',
                                            'shipping_discount' => '0.00',
                                            'insurance'         => '0.00',
                                        ),
                                    ),
                                    'description'     => __('Payment Transaction Succeded.', 'sejoli-paypal'),
                                    'custom'          => 'payment-'.carbon_get_theme_option('paypal_inv_prefix').$order['ID'],
                                    'invoice_number'  => carbon_get_theme_option('paypal_inv_prefix').$order['ID'],
                                    'payment_options' => array (
                                        'allowed_payment_method' => 'INSTANT_FUNDING_SOURCE',
                                    ),
                                    'soft_descriptor' => 'ECHI5786786',
                                    'item_list' => array (
                                        'items' => array (
                                            0 => array (
                                                'name'        => ''.$order['product']->post_title.'',
                                                'description' => ''.$order['product']->post_excerpt.'',
                                                'quantity'    => ''.$order['quantity'].'',
                                                'price'       => ''.$product_price.'',
                                                'tax'         => '0.00',
                                                'sku'         => ''.$order['product']->post_name.'',
                                                'currency'    => 'USD',
                                            ),
                                        ),
                                        'shipping_address' => array (
                                            'recipient_name' => $recipient_name,
                                            'line1'          => preg_replace("/<br\W*?\/>/", ", ", $recipient_address),
                                            'line2'          => '',
                                            'city'           => $receiver_city,
                                            'country_code'   => 'ID',
                                            'postal_code'    => '',
                                            'phone'          => $recipient_phone,
                                            'state'          => $receiver_province,
                                        ),
                                    ),
                                ),
                            ),
                            'note_to_payer' => __('Contact us for any questions on your order.', 'sejoli-paypal'),
                            'redirect_urls' => array (
                                'return_url' => $redirect_urls,
                                'cancel_url' => $redirect_urls,
                            ),
                        );
                                            
                    } else {
                        
                        if ( isset( $order['product']->subscription ) ){
                            $grand_total   = $this->currency_convert( $order['grand_total'] );
                            $product_price = $grand_total; //$this->currency_convert( $order['product']->price ) + $this->currency_convert( $order['product']->subscription['signup']['fee'] );
                        } else {
                            $grand_total   = $this->currency_convert( $order['grand_total'] );
                            $product_price = $grand_total;
                        }
                        
                        $receiver_destination_id   = $order['user']->data->meta->destination;
                        $receiver_destination_city = sejolise_get_subdistrict_detail( $receiver_destination_id );
                        $receiver_city             = $receiver_destination_city['type'].' '.$receiver_destination_city['city'];
                        $receiver_province         = $receiver_destination_city['province'];
                        $shipping_cost             = 0.00;
                        $recipient_name            = $order['user']->data->display_name;
                        $recipient_address         = $order['user']->data->meta->address;
                        $recipient_phone           = $order['user']->data->meta->phone;
                        $subtotal                  = $product_price; 
                    
                        $postDataArray = array (
                            'intent' => 'sale',
                            'payer'  => array (
                                'payment_method' => 'paypal',
                            ),
                            'transactions' => array (
                                0 => array (
                                    'amount' => array (
                                        'total'    => ''.$grand_total.'',
                                        'currency' => 'USD',
                                        'details'  => array (
                                            'subtotal'          => ''.$subtotal.'',
                                            'tax'               => '0.00',
                                            'shipping'          => ''.$shipping_cost.'',
                                            'handling_fee'      => '0.00',
                                            'shipping_discount' => '0.00',
                                            'insurance'         => '0.00',
                                        ),
                                    ),
                                    'description'     => __('Payment Transaction Succeded.', 'sejoli-paypal'),
                                    'custom'          => 'payment-'.carbon_get_theme_option('paypal_inv_prefix').$order['ID'],
                                    'invoice_number'  => carbon_get_theme_option('paypal_inv_prefix').$order['ID'],
                                    'payment_options' => array (
                                        'allowed_payment_method' => 'INSTANT_FUNDING_SOURCE',
                                    ),
                                    'soft_descriptor' => 'ECHI5786786',
                                    'item_list' => array (
                                        'items' => array (
                                            0 => array (
                                                'name'        => ''.$order['product']->post_title.'',
                                                'description' => ''.$order['product']->post_excerpt.'',
                                                'quantity'    => ''.$order['quantity'].'',
                                                'price'       => ''.$product_price.'',
                                                'tax'         => '0.00',
                                                'sku'         => ''.$order['product']->post_name.'',
                                                'currency'    => 'USD',
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                            'note_to_payer' => __('Contact us for any questions on your order.', 'sejoli-paypal'),
                            'redirect_urls' => array (
                                'return_url' => $redirect_urls,
                                'cancel_url' => $redirect_urls,
                            ),
                        );

                    }


                    $postData = json_encode( $postDataArray );
        
                    $postData = apply_filters( 'paypal_before_post_transaction', $postData, $order['ID'] );

                    $resBody  = $this->postTransaction( $apiUri, $token, $postData );

                    $this->add_to_table($order['ID']);
                    $resBody['paymentUrl']        = isset( $resBody['links'][1]['href'] ) ? $resBody['links'][1]['href'] : '';
                    $resBody['executePaymentUrl'] = isset( $resBody['links'][2]['href'] ) ? $resBody['links'][2]['href'] : '';
                    
                    $this->update_detail( $order['ID'], $resBody );
                    wp_redirect( $resBody['paymentUrl'] );
                    exit;
                
                }
            
            } else {
                
                do_action( 'sejoli/log/write', 'error-paypal', array( 'empty token', $order['ID'] ) );
                wp_die(
                    __('Terjadi kesalahan saat request ke Paypal. Silahkan kontak pemilik website ini', 'sejoli-paypal'),
                    __('Terjadi kesalahan', 'sejoli-paypal')
                );
            
            }

        }

    }

    /**
     * Check if current order is using paypal and will be redirected to paypal payment channel options
     * Hooked via action sejoli/thank-you/render, priority 100
     * @since   1.0.0
     * @param   array  $order Order data
     * @return  void
     */
    public function check_for_redirect( array $order ) {

        if(
            isset( $order['payment_info']['bank'] ) &&
            'PAYPAL' === strtoupper( $order['payment_info']['bank'] )
        ) :

            if( 'on-hold' === $order['status'] ) :
                
                $this->prepare_paypal_data( $order );

            elseif( in_array( $order['status'], array( 'refunded', 'cancelled' ) ) ) :

                $title = __('Order telah dibatalkan', 'sejoli-paypal');
                require 'template/checkout/order-cancelled.php';

            else :

                $title = __('Order sudah diproses', 'sejoli-paypal');
                require 'template/checkout/order-processed.php';

            endif;

            exit;

        endif;
    
    }

    /**
     * Display payment instruction in notification
     * @since   1.0.0
     * @param   array    $invoice_data
     * @param   string   $media email,whatsapp,sms
     * @return  string
     */
    public function display_payment_instruction( $invoice_data, $media = 'email' ) {
        
        if('on-hold' !== $invoice_data['order_data']['status']) :
            return;
        endif;

        $content = sejoli_get_notification_content(
                        'duitku',
                        $media,
                        array(
                            'order' => $invoice_data['order_data']
                        )
                    );

        return $content;
    
    }

    /**
     * Display simple payment instruction in notification
     * @since   1.0.0
     * @param   array    $invoice_data
     * @param   string   $media
     * @return  string
     */
    public function display_simple_payment_instruction( $invoice_data, $media = 'email' ) {

        if( 'on-hold' !== $invoice_data['order_data']['status'] ) :
            return;
        endif;

        $content = __('via Paypal', 'sejoli-paypal');

        return $content;

    }

    /**
     * Set payment info to order data
     * @since   1.0.0
     * @param   array $order_data
     * @return  array
     */
    public function set_payment_info( array $order_data ) {

        $trans_data = [
            'bank'  => 'Paypal'
        ];

        return $trans_data;

    }

    /**
     * Paypal Renew Token
     * @since   1.0.0
     * @param   array $api_uri, $client_id, $client_secret
     * @return  array
     */
    private function paypal_renew_token( $api_uri, $client_id, $client_secret ) {
        
        $postData = [
            'grant_type' => 'client_credentials'
        ];

        $result = wp_remote_post( $api_uri.'/oauth2/token', array(
            'body'    => $postData,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
            ),
            'timeout' => 300
        ));
    
        $resBody = wp_remote_retrieve_body( $result );
        $resBody = json_decode( ( $resBody ), true );

        if ( isset( $resBody['access_token'] ) && !empty( $resBody['access_token'] ) ) {
            
            $mode = carbon_get_theme_option( 'paypal_mode' );
            add_option( 'paypal_'.$mode.'_token', $resBody['access_token'] );
            add_option( 'paypal_'.$mode.'_token_expiration', time() + 3600 );

            return $resBody['access_token'];
        
        } else {
        
            return 0;
        
        }

    }

    /**
     * Post Transaction Data
     * @since   1.0.0
     * @return  array
     */
    private function postTransaction($apiUri, $token, $postData)
    {
        $time = $this->paypal_generate_isotime();
        $url  = 'POST:/payments/payment';
        
        $result = wp_remote_post( $apiUri.'/payments/payment', array(
            'body'    => $postData,
            'timeout' => 300,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
        ));

        if( is_wp_error( $result ) ){
            
            return [
                'success' => 0
            ];
        
        }

        $resBody = wp_remote_retrieve_body( $result );
        $resBody = json_decode( $resBody, true );

        return $resBody;

    }

    /**
     * CExcecute Transaction
     * @since   1.0.0
     * @return  array
     */
    private function executeTransaction($apiUri, $token, $executeData) {
        
        $result = wp_remote_post( $apiUri, array(
            'body'    => $executeData,
            'timeout' => 300,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
        ));

        if( is_wp_error( $result ) ){
            
            return [
                'success' => 0
            ];
        
        }

        $resBody = wp_remote_retrieve_body( $result );
        $resBody = json_decode( $resBody, true );

        return $resBody;

    }

    /**
     * Paypal Generate Iso Time
     * @since   1.0.0
     * @return  time
     */
    private function paypal_generate_isotime() {
        
        $fmt  = date( 'Y-m-d\TH:i:s' );
        $time = sprintf( "$fmt.%s%s", substr( microtime(), 2, 3 ), date( 'P' ) );

        return $time;

    }

}
