<?php // phpcs:disable
/**
 * PrivacyGate Payment Gateway.
 *
 * Provides a PrivacyGate Payment Gateway.
 *
 * @class       WC_Gateway_privacygate
 * @extends     WC_Payment_Gateway
 * @since       1.0.0
 * @package     WooCommerce/Classes/Payment
 * @author      WooThemes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_privacygate Class.
 */
class WC_Gateway_privacygate extends WC_Payment_Gateway {

	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;

	/** @var WC_Logger Logger instance */
	public static $log = false;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'privacygate';
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Proceed to PrivacyGate', 'privacygate' );
		$this->method_title       = __( 'PrivacyGate', 'privacygate' );
		$this->method_description = '<p>' .
			// translators: Introduction text at top of PrivacyGate settings page.
			__( 'A payment gateway that sends your customers to PrivacyGate to pay with cryptocurrency.', 'privacygate' )
			. '</p><p>' .
			sprintf(
				// translators: Introduction text at top of PrivacyGate settings page. Includes external URL.
				__( 'If you do not currently have a PrivacyGate account, you can set one up here: %s', 'privacygate' ),
				'<a target="_blank" href="https://dash.privacygate.io/">https://dash.privacygate.io/</a>'
			);

		// Timeout after 3 days. Default to 3 days as pending Bitcoin txns
		// are usually forgotten after 2-3 days.
		$this->timeout = ( new WC_DateTime() )->sub( new DateInterval( 'P3D' ) );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

		self::$log_enabled = $this->debug;

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, '_custom_query_var' ), 10, 2 );
		add_action( 'woocommerce_api_wc_gateway_privacygate', array( $this, 'handle_webhook' ) ); // T
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $level   Optional. Default 'info'.
	 *     emergency|alert|critical|error|warning|notice|info|debug
	 */
	public static function log( $message, $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log( $level, $message, array( 'source' => 'privacygate' ) );
		}
	}

	/**
	 * Get gateway icon.
	 * @return string
	 */
	public function get_icon() {
		if ( $this->get_option( 'show_icons' ) === 'no' ) {
			return '';
		}

		$image_path = plugin_dir_path( __FILE__ ) . 'assets/images';
		$icon_html  = '';
		$methods    = get_option( 'privacygate_payment_methods', array( 'bitcoin', 'bitcoincash', 'dai', 'ethereum', 'litecoin', 'usdt', 'usdc', 'chainlink' ) );

		// Load icon for each available payment method.
		foreach ( $methods as $m ) {
			$path = realpath( $image_path . '/' . $m . '.png' );
			if ( $path && dirname( $path ) === $image_path && is_file( $path ) ) {
				$url        = WC_HTTPS::force_https_url( plugins_url( '/assets/images/' . $m . '.png', __FILE__ ) );
				$icon_html .= '<img width="26" src="' . esc_attr( $url ) . '" alt="' . esc_attr__( $m, 'privacygate' ) . '" />';
			}
		}

		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PrivacyGate Payment', 'privacygate' ),
				'default' => 'yes',
			),
			'title'          => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Bitcoin and other cryptocurrencies', 'privacygate' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Pay with Bitcoin or other cryptocurrencies.', 'privacygate' ),
			),
			'api_key'        => array(
				'title'       => __( 'API Key', 'privacygate' ),
				'type'        => 'text',
				'default'     => '',
				'description' => sprintf(
					// translators: Description field for API on settings page. Includes external link.
					__(
						'You can manage your API keys within the PrivacyGate Settings page, available here: %s',
						'privacygate'
					),
					esc_url( 'https://dash.privacygate.io/dashboard/settings' )
				),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook Shared Secret', 'privacygate' ),
				'type'        => 'text',
				'description' =>

				// translators: Instructions for setting up 'webhook shared secrets' on settings page.
				__( 'Using webhooks allows PrivacyGate to send payment confirmation messages to the website. To fill this out:', 'privacygate' )

				. '<br /><br />' .

				// translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
				__( '1. In your PrivacyGate settings page, scroll to the \'Webhook subscriptions\' section', 'privacygate' )

				. '<br />' .

				// translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
				sprintf( __( '2. Click \'Add an endpoint\' and paste the following URL: %s', 'privacygate' ), add_query_arg( 'wc-api', 'WC_Gateway_privacygate', home_url( '/', 'https' ) ) )

				. '<br />' .

				// translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
				__( '3. Make sure to select "Send me all events", to receive all payment updates.', 'privacygate' )

				. '<br />' .

				// translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
				__( '4. Click "Show shared secret" and paste into the box above.', 'privacygate' ),

			),
			'show_icons'     => array(
				'title'       => __( 'Show icons', 'privacygate' ),
				'type'        => 'checkbox',
				'label'       => __( 'Display currency icons on checkout page.', 'privacygate' ),
				'default'     => 'yes',
			),
			'debug'          => array(
				'title'       => __( 'Debug log', 'woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'woocommerce' ),
				'default'     => 'no',
				// translators: Description for 'Debug log' section of settings page.
				'description' => sprintf( __( 'Log privacygate API events inside %s', 'privacygate' ), '<code>' . WC_Log_Handler_File::get_log_file_path( 'privacygate' ) . '</code>' ),
			),
		);
	}

	/**
	 * Process the payment and return the result.
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Create description for charge based on order's products. Ex: 1 x Product1, 2 x Product2
		try {
			$order_items = array_map( function( $item ) {
				return $item['quantity'] . ' x ' . $item['name'];
			}, $order->get_items() );

			$description = mb_substr( implode( ', ', $order_items ), 0, 200 );
		} catch ( Exception $e ) {
			$description = null;
		}

		$this->init_api();

		// Create a new charge.
		$metadata = array(
			'order_id'  => $order->get_id(),
			'order_key' => $order->get_order_key(),
            		'source' => 'woocommerce'
		);
		$result   = privacygate_API_Handler::create_charge(
			$order->get_total(), get_woocommerce_currency(), $metadata,
			$this->get_return_url( $order ), null, $description,
			$this->get_cancel_url( $order )
		);

		if ( ! $result[0] ) {
			return array( 'result' => 'fail' );
		}

		$charge = $result[1]['data'];

		$order->update_meta_data( '_privacygate_charge_id', $charge['code'] );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $charge['hosted_url'],
		);
	}

	/**
	 * Get the cancel url.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function get_cancel_url( $order ) {
		$return_url = $order->get_cancel_order_url();

		if ( is_ssl() || get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
			$return_url = str_replace( 'http:', 'https:', $return_url );
		}

		return apply_filters( 'woocommerce_get_cancel_url', $return_url, $order );
	}

	/**
	 * Check payment statuses on orders and update order statuses.
	 */
	public function check_orders() {
		$this->init_api();

		// Check the status of non-archived privacygate orders.
		$orders = wc_get_orders( array( 'privacygate_archived' => false, 'status'   => array( 'wc-pending' ) ) );
		foreach ( $orders as $order ) {
			$charge_id = $order->get_meta( '_privacygate_charge_id' );

			usleep( 300000 );  // Ensure we don't hit the rate limit.
			$result = privacygate_API_Handler::send_request( 'charges/' . $charge_id );

			if ( ! $result[0] ) {
				self::log( 'Failed to fetch order updates for: ' . $order->get_id() );
				continue;
			}

			$timeline = $result[1]['data']['timeline'];
			self::log( 'Timeline: ' . print_r( $timeline, true ) );
			$this->_update_order_status( $order, $timeline );
		}
	}

	/**
	 * Handle requests sent to webhook.
	 */
	public function handle_webhook() {
		$payload = file_get_contents( 'php://input' );
		if ( ! empty( $payload ) && $this->validate_webhook( $payload ) ) {
			$data       = json_decode( $payload, true );
			$event_data = $data['event']['data'];

			self::log( 'Webhook received event: ' . print_r( $data, true ) );

			if ( ! isset( $event_data['metadata']['order_id'] ) ) {
				// Probably a charge not created by us.
				exit;
			}

			$order_id = $event_data['metadata']['order_id'];

			$this->_update_order_status( wc_get_order( $order_id ), $event_data['timeline'] );

			exit;  // 200 response for acknowledgement.
		}

		wp_die( 'privacygate Webhook Request Failure', 'privacygate Webhook', array( 'response' => 500 ) );
	}

	/**
	 * Check privacygate webhook request is valid.
	 * @param  string $payload
	 */
	public function validate_webhook( $payload ) {
		self::log( 'Checking Webhook response is valid' );

		if ( ! isset( $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'] ) ) {
			return false;
		}

		$sig    = $_SERVER['HTTP_X_CC_WEBHOOK_SIGNATURE'];
		$secret = $this->get_option( 'webhook_secret' );

		$sig2 = hash_hmac( 'sha256', $payload, $secret );

		if ( $sig === $sig2 ) {
			return true;
		}

		return false;
	}

	/**
	 * Init the API class and set the API key etc.
	 */
	protected function init_api() {
		include_once dirname( __FILE__ ) . '/includes/class-privacygate-api-handler.php';

		privacygate_API_Handler::$log     = get_class( $this ) . '::log';
		privacygate_API_Handler::$api_key = $this->get_option( 'api_key' );
	}

	/**
	 * Update the status of an order from a given timeline.
	 * @param  WC_Order $order
	 * @param  array    $timeline
	 */
	public function _update_order_status( $order, $timeline ) {
		$prev_status = $order->get_meta( '_privacygate_status' );

		$last_update = end( $timeline );
		$status      = $last_update['status'];
		if ( $status !== $prev_status ) {
			$order->update_meta_data( '_privacygate_status', $status );

			if ( 'EXPIRED' === $status && 'pending' == $order->get_status() ) {
				$order->update_status( 'cancelled', __( 'privacygate payment expired.', 'privacygate' ) );
			} elseif ( 'CANCELED' === $status ) {
				$order->update_status( 'cancelled', __( 'privacygate payment cancelled.', 'privacygate' ) );
			} elseif ( 'UNRESOLVED' === $status ) {
			    	if ($last_update['context'] === 'OVERPAID') {
                    			$order->update_status( 'processing', __( 'privacygate payment was successfully processed.', 'privacygate' ) );
                    			$order->payment_complete();
                		} else {
                    			// translators: privacygate error status for "unresolved" payment. Includes error status.
                    			$order->update_status( 'failed', sprintf( __( 'privacygate payment unresolved, reason: %s.', 'privacygate' ), $last_update['context'] ) );
                		}
			} elseif ( 'PENDING' === $status ) {
				$order->update_status( 'blockchainpending', __( 'privacygate payment detected, but awaiting blockchain confirmation.', 'privacygate' ) );
			} elseif ( 'RESOLVED' === $status ) {
				// We don't know the resolution, so don't change order status.
				$order->add_order_note( __( 'privacygate payment marked as resolved.', 'privacygate' ) );
            		} elseif ( 'COMPLETED' === $status ) {
                		$order->update_status( 'processing', __( 'privacygate payment was successfully processed.', 'privacygate' ) );
                		$order->payment_complete();
            		}
		}

		// Archive if in a resolved state and idle more than timeout.
		if ( in_array( $status, array( 'EXPIRED', 'COMPLETED', 'RESOLVED' ), true ) &&
			$order->get_date_modified() < $this->timeout ) {
			self::log( 'Archiving order: ' . $order->get_order_number() );
			$order->update_meta_data( '_privacygate_archived', true );
		}
	}

	/**
	 * Handle a custom 'privacygate_archived' query var to get orders
	 * payed through privacygate with the '_privacygate_archived' meta.
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function _custom_query_var( $query, $query_vars ) {
		if ( array_key_exists( 'privacygate_archived', $query_vars ) ) {
			$query['meta_query'][] = array(
				'key'     => '_privacygate_archived',
				'compare' => $query_vars['privacygate_archived'] ? 'EXISTS' : 'NOT EXISTS',
			);
			// Limit only to orders payed through privacygate.
			$query['meta_query'][] = array(
				'key'     => '_privacygate_charge_id',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	}
}
