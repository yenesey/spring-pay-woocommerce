<?php

/**
*
 * @since      1.0.0
 * @package    Spring_Pay
 * @subpackage Spring_Pay/includes
 * @author     Denis Bogachev <denesey@gmail.com>
 */
class Spring_Pay {

	protected $version;
	public $plugin_name;
	public $plugin_path;
	public $plugin_url;
	public $plugin_dir;

	public function __construct($version)	{
		$dir = plugin_dir_path( __FILE__ );
		$this->version = $version;
		$this->plugin_name = 'spring_pay';
		$this->plugin_path = trailingslashit( dirname( $dir ) );
		$this->plugin_url  = plugin_dir_url( $dir );
		$this->plugin_dir = substr($this->plugin_path, strlen(dirname( $this->plugin_path ))); // - just relative dir name
	}

	function load_plugin_textdomain() {
		load_plugin_textdomain('spring-pay',	false,	$this->plugin_path . 'languages'	);
	}

	function load_gateway() {
		require_once 'class-spring-pay-gateway.php';
	}
	
	function add_gateway_class( $methods ) {
		$methods[] = 'Spring_Pay_Gateway'; 
		return $methods;
	}

	public function run() {
		//register_activation_hook( $this->file, array( $this, 'activate' ) );
		//register_deactivation_hook( __FILE__, 'deactivate_spring_pay' );

		add_action('plugins_loaded', array( $this, 'load_gateway'));
		//add_action('parse_request', array( $this, 'processCallback'));
		add_action('init', array( $this, 'load_plugin_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway_class') );
	}

}
