<?php

if ( ! class_exists('WC_Payment_Gateway')) {
	return;
}

if (version_compare(WOOCOMMERCE_VERSION, "3.0", "<")) {
	return;
}

include_once 'lib/phpqrcode/qrlib.php';

class Spring_Pay_Gateway extends WC_Payment_Gateway {
	public $qr_image_dir;
	public $qr_image_url;

	public function __construct() {
		// setup QR-code generation:
		$upload_dir = wp_upload_dir();
		$this->qr_image_dir = $upload_dir['basedir'] . spring_pay()->plugin_dir;
		$this->qr_image_url = $upload_dir['baseurl'] . spring_pay()->plugin_dir;
		if (!file_exists($this->qr_image_dir)) {
			mkdir($this->qr_image_dir, 0777, true);
		}
		$this->QRcode = new QRcode();

		// setup wc gateway class required fields:
		$this->id = spring_pay()->plugin_name;
		$this->icon = spring_pay()->plugin_url.'assets/img/spring-pay.png';
		$this->method_title = 'SpringPay';
		$this->method_description = 'Оплата через систему SpringPay (Telegram-бот)';
		//$this->has_fields = true;  // вывести поля прямо на странице оплаты заказа
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		$this->shop_id = $this->get_option( 'shop_id' );
				 
		// ipn (CALLBACK) from Spring System
		add_action('woocommerce_api_'. strtolower( get_class($this) ), array( $this, 'ipn_response'));

		add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));

		// add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
		add_action('woocommerce_thankyou', array( $this, 'view_order'), 4 );
		add_action('woocommerce_view_order', array( $this, 'view_order'), 4 );
		
		add_action('woocommerce_email_after_order_table', array( $this, 'hook_email'), 10, 4 );

		// add_filter( 'the_title',  array( $this, 'woo_title_order_received'), 10, 2 );
		add_filter('woocommerce_thankyou_order_received_text', array( $this, 'woo_change_order_received_text'), 10, 2 );

		// add_action( 'template_redirect', array( $this, 'wc_custom_redirect_after_purchase') ); 
	}

	/*
	function wc_custom_redirect_after_purchase() {
		global $wp;
		wc_get_logger()->info( 'wc_custom_redirect_after_purchase', array( 'source' => $this->id ) );
		
		if ( is_checkout() && ! empty( $wp->query_vars['order-received'] ) ) {
			wp_redirect( spring_pay()->plugin_url.'templates/thankyou/' );
			exit;
		}
	}

	function woo_title_order_received( $title, $id ) {
		if ( function_exists( 'is_order_received_page' ) && 
			 is_order_received_page() && get_the_ID() === $id ) {
			$title = "Thank you for your order! :)";
		}
		return $title;
	}
	*/

	function woo_change_order_received_text( $str, $order ) {
		if ($order->status == 'on-hold') {
			return $str . ' Ожидается оплата. ';
		} elseif ($order->status == 'processing') {
			return 'Ваш платеж подтвержден! Ожидается отправка.';
		} 
		return $str;
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __('Включить/Выключить', 'spring_pay'),
				'type'    => 'checkbox',
				'label'   => $this->method_description,
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __('Заголовок', 'spring_pay'),
				'type'        => 'text',
				'description' => __('Название, которое пользователь видит во время оплаты', 'spring_pay'),
				'default'     => $this->method_title,
			),
			'description' => array(
				'title'       => __('Описание', 'spring_pay'),
				'type'        => 'textarea',
				'description' => __('Описание, которое пользователь видит во время оплаты', 'spring_pay'),
				'default'     => $this->method_description,
			),
			'shop_id' => array(
				'title'       => 'TelegramID',
				'type'        => 'text',
				'description' => __('TelegramID используется как идентификатор магазина в SpringPay', 'spring_pay'),
				'default'     => '',
			),
			'public_key' => array(
				'title'       => 'Public key',
				'type'        => 'text',
				'description' => __('Ключ, полученный при регистрации в SpringPay', 'spring_pay'),
				'default'     => '',
			),
			'api_certificate' => array(
				'title'       => __( 'Live API Certificate', 'spring_pay' ),
				'type'        => 'file',
				'description' => 'Сертификат от SpringPay',
				'default'     => '',
			),
		);
	}

	public function process_payment( $order_id ) {
		global $woocommerce;
		$order = new WC_Order( $order_id );

		$order->update_status('on-hold',   'Awaiting payment'); 		// 'processing' 'failed'
		$woocommerce->cart->empty_cart();
		$order->reduce_order_stock();

		// $order->add_order_note( __('IPN payment completed', 'woocommerce') );
		// wc_add_notice( __('Payment error:', 'woothemes') . $error_message, 'error' );

		/**
		 * Generate QR-code image for order pay_link
		 */
		$qr_file = $this->qr_image_dir . 'qr_' . $order->id . '.png';
		if ( !file_exists ($qr_file) ) {
			$this->QRcode->png(	$this->pay_link($order), $qr_file, 'L', 4, 2 );
		};

		// Return thankyou redirect
		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url( $order )
		);
	}

	public function ipn_response() {
		// global $woocommerce;
		$order_id = $_GET['order_id'];
		$order = new WC_Order( $order_id );
		$order->payment_complete();

		wc_get_logger()->info( 'ipn_response:'.$order_id, array( 'source' => $this->id ) );

		echo 'OK!';
		die(); // temporary: prevent -1
	}

	function order_meta_keys( $keys ) {
		$keys[] = 'PayLink';
		return $keys;
	}

	/*
	public function receipt_page($order_id) {
		global $woocommerce;
		// $apiClient = $this->getApiClient();
		$order = new WC_Order($order_id);
		// $paymentId = $order->get_transaction_id();

		wc_get_logger()->info( 'receipt page fired!', array( 'source' => $this->id ) );
	}
	*/
	public function pay_link($order) {
		return 'https://t.me/SpringPayBot?start='.strval($this->shop_id).'_'.number_format($order->get_total(), 2, '', '').'_'.strval($order->id);
	}

	public function pay_link_qr($order) {
		return $this->qr_image_url . 'qr_' . $order->id . '.png';
	}


	function view_order( $order_id ) {
		// global $woocommerce;
		// $cart_subtotal = $woocommerce->cart->get_cart_subtotal();
		// [] = $order->get_customer_order_notes();
   		// $permalink = apply_filters('wcqrc_product_permalink', get_permalink($post_id), $post_id);
		// esc_url($permalink);
		// $image_name = time() . '_' . $order_id . '.png';
		$order = new WC_Order( $order_id );
		if ($order->status != 'on-hold') return;
		
		$useragent = $_SERVER['HTTP_USER_AGENT'];
		$mobile = preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',$useragent)||preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',substr($useragent,0,4));
		
		$template = '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
			<li class="woocommerce-order-overview__order order">
				<center>
				  <strong>
					  <a href="'.$this->pay_link($order).'"> 
					   <button style="background-image:url('.$this->icon.'); background-repeat: no-repeat; padding-left: 48px;">
						   Оплатить в SpringPay
					   </button>
					  </a>
				  </strong>
				</center>
			</li>';
		
		if (!$mobile) {  
			$template .= '<li class="woocommerce-order-overview__order order">
				<center>
					<strong>
						<img src='.$this->pay_link_qr($order).' alt="qrcode_place"/><span>Сканируйте код для оплаты смартфоном</span>
					</strong>
				</center>
			</li>';
		}
		$template .= '</ul>';
		echo $template;
	}

	function hook_email( $order, $sent_to_admin, $plain_text, $email ) {
		/*
		if ( $email->id == 'customer_processing_order' ) 
		if ( $email->id == 'cancelled_order' ) {}
		if ( $email->id == 'customer_completed_order' ) {}
		if ( $email->id == 'customer_invoice' ) {}
		if ( $email->id == 'customer_new_account' ) {}
		if ( $email->id == 'customer_note' ) {}
		if ( $email->id == 'customer_on_hold_order' ) {}
		if ( $email->id == 'customer_refunded_order' ) {}
		if ( $email->id == 'customer_reset_password' ) {}
		if ( $email->id == 'failed_order' ) {}
		if ( $email->id == 'new_order' ) {}
		*/

		if ( $email->id !== 'customer_on_hold_order' ) return;

		echo '<table border="0" cellpadding="0" cellspacing="0" style="margin-right:auto">
				<tbody>
					<tr>
						<td> 
							<a href="'.$this->pay_link($order).'" style="background-color:#96588a;color:#ffffff;display:table-cell;font-size:14px;font-weight:bold;padding-bottom:12px;padding-left:24px;padding-right:24px;padding-top:12px;text-align:center;text-decoration-line:none;width:100%">Оплатить в SpringPay</a>
						</td>
					</tr>
					<tr>
						<td> 
							<img src='.$this->pay_link_qr($order).' alt="qrcode_place"/><span>Сканируйте код для оплаты смартфоном</span>
						</td>
					</tr>
				</tbody>
			</table>						
			';
	}

	/**
	 * EXAMPLE: Do some additonal validation before saving options via the API.
	 */
	/*
	public function process_admin_options() {
		// If a certificate has been uploaded, read the contents and save that string instead.
		if ( array_key_exists( 'woocommerce_ppec_paypal_api_certificate', $_FILES )
			&& array_key_exists( 'tmp_name', $_FILES['woocommerce_ppec_paypal_api_certificate'] )
			&& array_key_exists( 'size', $_FILES['woocommerce_ppec_paypal_api_certificate'] )
			&& $_FILES['woocommerce_ppec_paypal_api_certificate']['size'] ) {

			$_POST['woocommerce_ppec_paypal_api_certificate'] = base64_encode( file_get_contents( $_FILES['woocommerce_ppec_paypal_api_certificate']['tmp_name'] ) );
			unlink( $_FILES['woocommerce_ppec_paypal_api_certificate']['tmp_name'] );
			unset( $_FILES['woocommerce_ppec_paypal_api_certificate'] );
		} else {
			$_POST['woocommerce_ppec_paypal_api_certificate'] = $this->get_option( 'api_certificate' );
		}
		parent::process_admin_options();
	}
	*/

}