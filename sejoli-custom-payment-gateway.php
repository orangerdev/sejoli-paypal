<?php
/**
 *
 * @link              https://ridwan-arifandi.com
 * @since             1.0.0
 * @package           Sejoli
 *
 * @wordpress-plugin
 * Plugin Name:       Sejoli - Paypal Payment Gateway
 * Plugin URI:        https://sejoli.co.id
 * Description:       Integrate Sejoli Premium WordPress Membership Plugin with Paypal Payment Gateway.
 * Version:           1.0.2
 * Requires PHP: 	  7.2.1
 * Author:            Sejoli
 * Author URI:        https://sejoli.co.id
 * Text Domain:       sejoli-paypal
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {

	die;

}

add_filter('sejoli/payment/available-libraries', function( array $libraries ){

    require_once ( plugin_dir_path( __FILE__ ) . '/class-payment-gateway.php' );

    $libraries['paypal'] = new \SejoliPaypal();

    return $libraries;

});
