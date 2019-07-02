<?php

/**
 
 * @link              http://example.com
 * @since             1.0.0
 * @package           Spring_Pay
 *
 * @wordpress-plugin
 * Plugin Name:       SpringPay
 * Plugin URI:        http://example.com/plugin-name-uri/
 * Description:       Payments via "SpringSystem" (Telegram bot)
 * Version:           1.0.0
 * Author:            Denis Bogachev
 * Author URI:        http://bogachevo.ru
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       spring-pay
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'VERSION', '1.0.0' );

function spring_pay() {
	static $plugin;
	if ( ! isset( $plugin ) ) {
		require_once plugin_dir_path( __FILE__ ) .'includes/class-spring-pay.php';
		$plugin = new Spring_Pay( VERSION );
	}
	return $plugin;
}

spring_pay()->run();

