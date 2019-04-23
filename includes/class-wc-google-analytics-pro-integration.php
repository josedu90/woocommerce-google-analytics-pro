<?php
/**
 * WooCommerce Google Analytics Pro
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Google Analytics Pro to newer
 * versions in the future. If you wish to customize WooCommerce Google Analytics Pro for your
 * needs please refer to http://docs.woocommerce.com/document/woocommerce-google-analytics-pro/ for more information.
 *
 * @package     WC-Google-Analytics-Pro/Integration
 * @author      SkyVerge
 * @copyright   Copyright (c) 2015-2018, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * The plugin integration class.
 *
 * Handles settings and provides common tracking functions needed by enhanced
 * eCommerce tracking.
 *
 * @since 1.0.0
 */
class WC_Google_Analytics_Pro_Integration extends SV_WC_Tracking_Integration {

	/** @var string URL to Google Analytics Pro Authentication proxy */
	const PROXY_URL = 'https://wc-google-analytics-pro-proxy.herokuapp.com';

	/** @var string MonsterInsights's GA tracking type, Universal or old 'ga.js'. Default is empty string, which means that MonsterInsights tracking is inactive. */
	private $_monsterinsights_tracking_type = '';

	/** @var \WC_Google_Analytics_Pro_Email_Tracking instance **/
	public $email_tracking;

	/** @var array cache for user tracking status **/
	private $user_tracking_enabled = array();

	/** @var object Google Client instance **/
	private $ga_client;

	/** @var object Google_Service_Analytics instance **/
	private $analytics;

	/** @var string google analytics js tracker function name **/
	private $ga_function_name;

	/** @var array associative array of queued tracking JavaScript **/
	private $queued_js = array();


	/**
	 * Constructs the class.
	 *
	 * Sets up the settings page & adds the necessary hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct(
			'google_analytics_pro',
			__( 'Google Analytics Pro', 'woocommerce-google-analytics-pro' ),
			__( 'Supercharge your Google Analytics tracking with enhanced eCommerce tracking, and custom event tracking', 'woocommerce-google-analytics-pro' )
		);

		// header/footer JavaScript code, only add if tracking ID is available
		if ( $this->get_tracking_id() ) {

			add_action( 'wp_head',    array( $this, 'ga_tracking_code' ), 9 );
			add_action( 'login_head', array( $this, 'ga_tracking_code' ), 9 );

			// print tracking JavaScript
			add_action( 'wp_footer', array( $this, 'print_js' ) );
		}

		// Enhanced Ecommerce related product impressions
		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'product_impression' ) );

		// save GA identity to each order
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'store_ga_identity' ) );

		// two filters catching the event of MonsterInsights doing tracking
		if ( wc_google_analytics_pro()->is_monsterinsights_active() ) {

			if ( wc_google_analytics_pro()->is_monsterinsights_lt_6() ) {
				add_filter( 'yoast-ga-push-array-ga-js',     array( $this, 'set_monsterinsights_tracking_type_ga_js' ) );
				add_filter( 'yoast-ga-push-array-universal', array( $this, 'set_monsterinsights_tracking_data' ) );
			} else {
				add_filter( 'monsterinsights_frontend_tracking_options_analytics_end', array( $this, 'set_monsterinsights_tracking_data' ) );
			}
		}

		// track emails
		$this->email_tracking = wc_google_analytics_pro()->load_class( '/includes/class-wc-google-analytics-pro-email-tracking.php', 'WC_Google_Analytics_Pro_Email_Tracking' );

		// handle Google Client API callbacks
		add_action( 'woocommerce_api_wc-google-analytics-pro/auth', array( $this, 'authenticate' ) );

		// load styles/scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'load_styles_scripts' ) );

		add_filter( 'woocommerce_settings_api_sanitized_fields_google_analytics_pro', array( $this, 'filter_admin_options' ) );
	}


	/**
	 * Returns the Google Analytics tracking function name.
	 *
	 * @since 1.3.0
	 * @return string Google Analytics tarckign function name
	 */
	public function get_ga_function_name() {

		if ( ! isset( $this->ga_function_name ) ) {

			$ga_function_name = $this->get_option( 'function_name', 'ga' );

			if ( '__gaTracker' !== $ga_function_name && wc_google_analytics_pro()->is_monsterinsights_active() && wc_google_analytics_pro()->is_monsterinsights_gte_6() && ! monsterinsights_get_option( 'gatracker_compatibility_mode', false ) ) {
				$ga_function_name = '__gaTracker';
			}

			/**
			 * Filters the Google Analytics tracking function name.
			 *
			 * Since 1.3.0 the tracking function name defaults to `ga` except when:
			 * - MonsterInsighs is enabled and not in compatibility mode
			 * - plugin was upgraded from a previous version and has not been configured to use the new `ga` function name
			 * in which case it will default to `__gaTracker`
			 *
			 * @since 1.0.3
			 * @param string $ga_function_name the Google Analytics tracking function name, defaults to 'ga'
			 */
			$this->ga_function_name = apply_filters( 'wc_google_analytics_pro_tracking_function_name', $ga_function_name );
		}

		return $this->ga_function_name;
	}


	/**
	 * Loads admin styles and scripts.
	 *
	 * @internal
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix the current URL filename, i.e. edit.php, post.php, etc...
	 */
	public function load_styles_scripts( $hook_suffix ) {

		if ( wc_google_analytics_pro()->is_plugin_settings() ) {

			wp_enqueue_script( 'wc-google-analytics-pro-admin', wc_google_analytics_pro()->get_plugin_url() . '/assets/js/admin/wc-google-analytics-pro-admin.min.js', array( 'jquery' ), WC_Google_Analytics_Pro::VERSION );

			wp_localize_script( 'wc-google-analytics-pro-admin', 'wc_google_analytics_pro', array(
				'ajax_url'            => admin_url('admin-ajax.php'),
				'auth_url'            => $this->get_auth_url(),
				'revoke_access_nonce' => wp_create_nonce( 'revoke-access' ),
				'i18n' => array(
					'ays_revoke' => esc_html__( 'Are you sure you wish to revoke access to your Google Account?', 'woocommerce-google-analytics-pro' ),
				),
			) );

			wp_enqueue_style( 'wc-google-analytics-pro-admin', wc_google_analytics_pro()->get_plugin_url() . '/assets/css/admin/wc-google-analytics-pro-admin.min.css', WC_Google_Analytics_Pro::VERSION );
		}
	}


	/**
	 * Enqueues the tracking JavaScript.
	 *
	 * Google Analytics is a bit picky about the order tacking JavaScript is output:
	 *
	 * + Impressions -> Pageview -> Events
	 *
	 * This method queues tracking JavaScript so it can be later output in the
	 * correct order.
	 *
	 * @since 1.0.3
	 * @param string $type the tracking type. One of 'impression', 'pageview', or 'event'
	 * @param string $javascript
	 */
	public function enqueue_js( $type, $javascript ) {

		if ( ! isset( $this->queued_js[ $type ] ) ) {
			$this->queued_js[ $type ] = array();
		}

		$this->queued_js[ $type ][] = $javascript;
	}


	/**
	 * Prints the tracking JavaScript.
	 *
	 * This method prints the queued tracking JavaScript in the correct order.
	 *
	 * @internal
	 *
	 * @see \WC_Google_Analytics_Pro_Integration::enqueue_js()
	 *
	 * @since 1.0.3
	 */
	public function print_js() {

		if ( $this->do_not_track() ) {
			return;
		}

		// define the correct order tracking types should be printed
		$types = array( 'impression', 'pageview', 'event' );

		$javascript = '';

		foreach ( $types as $type ) {

			if ( isset( $this->queued_js[ $type ] ) ) {

				foreach ( $this->queued_js[ $type ] as $code ) {
					$javascript .= "\n" . $code . "\n";
				}
			}
		}

		// enqueue the JavaScript
		wc_enqueue_js( $javascript );
	}


	/** Tracking methods ************************************************/


	/**
	 * Prints the tracking code JavaScript.
	 *
	 * @since 1.0.0
	 */
	public function ga_tracking_code() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		// helper functions for ga pro
		$gateways = array();

		foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
			$gateways[ $gateway->id ] = html_entity_decode( wp_strip_all_tags( $gateway->get_title() ) );
		}
?>
<script>
window.wc_ga_pro = {};

window.wc_ga_pro.available_gateways = <?php echo json_encode( $gateways ); ?>;

// interpolate json by replacing placeholders with variables
window.wc_ga_pro.interpolate_json = function( object, variables ) {

	if ( ! variables ) {
		return object;
	}

	var j = JSON.stringify( object );

	for ( var k in variables ) {
		j = j.split( '{$' + k + '}' ).join( variables[ k ] );
	}

	return JSON.parse( j );
};

// return the title for a payment gateway
window.wc_ga_pro.get_payment_method_title = function( payment_method ) {
	return window.wc_ga_pro.available_gateways[ payment_method ] || payment_method;
};

// check if an email is valid
window.wc_ga_pro.is_valid_email = function( email ) {
  return /[^\s@]+@[^\s@]+\.[^\s@]+/.test( email );
};
</script>
<?php

		// bail if MonsterInsights is doing the basic tracking already
		if ( $this->is_monsterinsights_tracking_active() ) {
			return;
		}

		/**
		 * Filters if the tracking code should be removed
		 *
		 * @since 1.5.1
		 *
		 * @param bool $remove_tracking_code
		 */
		if ( apply_filters( 'wc_google_analytics_pro_remove_tracking_code', false ) ) {
			return;
		}

		// no indentation on purpose
		?>
<!-- Start WooCommerce Google Analytics Pro -->
		<?php
		/**
		 * Fires before the JS tracking code is added.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wc_google_analytics_pro_before_tracking_code' );
		?>
<script>
	(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
	(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
	m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
	})(window,document,'script','https://www.google-analytics.com/analytics.js','<?php echo $this->get_ga_function_name(); ?>');
	<?php $tracker_options = $this->get_tracker_options(); ?>
	<?php echo $this->get_ga_function_name(); ?>( 'create', '<?php echo esc_js( $this->get_tracking_id() ); ?>', <?php echo ! empty( $tracker_options ) ? wp_json_encode( $tracker_options ) : "'auto'"; ?> );
	<?php echo $this->get_ga_function_name(); ?>( 'set', 'forceSSL', true );
<?php if ( 'yes' === $this->get_option( 'track_user_id' ) && is_user_logged_in() ) : ?>
	<?php echo $this->get_ga_function_name(); ?>( 'set', 'userId', '<?php echo esc_js( get_current_user_id() ) ?>' );
<?php endif; ?>
<?php if ( 'yes' === $this->get_option( 'anonymize_ip' ) ) : ?>
	<?php echo $this->get_ga_function_name(); ?>( 'set', 'anonymizeIp', true );
<?php endif; ?>
<?php if ( 'yes' === $this->get_option( 'enable_displayfeatures' ) ) : ?>
	<?php echo $this->get_ga_function_name(); ?>( 'require', 'displayfeatures' );
<?php endif; ?>
<?php if ( 'yes' === $this->get_option( 'enable_linkid' ) ) : ?>
	<?php echo $this->get_ga_function_name(); ?>( 'require', 'linkid' );
<?php endif; ?>
<?php if ( 'yes' === $this->get_option( 'enable_google_optimize' ) && '' !== $this->get_option( 'google_optimize_code' ) ) : ?>
	<?php echo $this->get_ga_function_name(); ?>( 'require', '<?php printf( '%1$s', esc_js( $this->get_option( 'google_optimize_code' ) ) ); ?>' );
<?php endif; ?>
	<?php echo $this->get_ga_function_name(); ?>( 'require', 'ec' );
	<?php
	/**
	 * Fires after the JS tracking code is setup.
	 *
	 * Allows to add custom JS calls after tracking code is setup.
	 *
	 * @since 1.3.5
	 *
	 * @param string $ga_function_name google analytics tracking function name
	 * @param string $tracking_id google analytics tracking ID
	 */
	do_action( 'wc_google_analytics_pro_after_tracking_code_setup', $this->get_ga_function_name(), $this->get_tracking_id() );
	?>
</script>
		<?php
		/**
		 * Fires after the JS tracking code is added.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wc_google_analytics_pro_after_tracking_code' );
		?>
<!-- end WooCommerce Google Analytics Pro -->
		<?php
	}


	/**
	 * Returns the JS tracker options.
	 *
	 * @since 1.3.5
	 *
	 * @return array|null
	 */
	private function get_tracker_options() {

		/**
		 * Filters the JS tracker options for the create method.
		 *
		 * @since 1.3.5
		 *
		 * @param array $tracker_options an associative array of tracker options
		 */
		return apply_filters( 'wc_google_analytics_pro_tracker_options', array(
			'cookieDomain' => 'auto'
		) );
	}


	/**
	 * Outputs the event tracking JavaScript.
	 *
	 * @since 1.0.0
	 * @param string $event_name the name of the event to be set
	 * @param array/string $properties Optional. The properties to be set with event
	 */
	private function js_record_event( $event_name, $properties = array() ) {

		// verify tracking status
		if ( $this->do_not_track() ) {
			return;
		}

		// MonsterInsights is in non-universal mode, skip
		if ( $this->is_monsterinsights_tracking_active() && ! $this->is_monsterinsights_tracking_universal() ) {
			return;
		}

		if ( ! is_array( $properties ) ) {
			return;
		}

		$this->enqueue_js( 'event', $this->get_event_tracking_js( $event_name, $properties ) );
	}


	/**
	 * Returns event tracking JS code.
	 *
	 * @since 1.0.0
	 * @param string $event_name the name of the vent to be set
	 * @param array/string $properties the roperties to be set with event
	 * @param string|null $js_args_variable (optional) name of the JS variable to use for interpolating dynamic event properties
	 * @return string|null
	 */
	private function get_event_tracking_js( $event_name, $properties, $js_args_variable = null ) {

		if ( ! is_array( $properties ) ) {
			return;
		}

		$properties = array(
			'hitType'        => isset( $properties['hitType'] )        ? $properties['hitType']        : 'event',     // Required
			'eventCategory'  => isset( $properties['eventCategory'] )  ? $properties['eventCategory']  : 'page',      // Required
			'eventAction'    => isset( $properties['eventAction'] )    ? $properties['eventAction']    : $event_name, // Required
			'eventLabel'     => isset( $properties['eventLabel'] )     ? $properties['eventLabel']     : null,
			'eventValue'     => isset( $properties['eventValue'] )     ? $properties['eventValue']     : null,
			'nonInteraction' => isset( $properties['nonInteraction'] ) ? $properties['nonInteraction'] : false,
		);

		// remove blank properties
		unset( $properties[''] );

		$properties = json_encode( $properties );

		// interpolate dynamic event properties
		if ( $js_args_variable ) {
			$properties = "wc_ga_pro.interpolate_json( {$properties}, {$js_args_variable} )";
		}

		return sprintf( "%s( 'send', %s );", $this->get_ga_function_name(), $properties );
	}


	/**
	 * Records an event via the Measurement Protocol API.
	 *
	 * @since 1.0.0
	 * @param string $event_name the name of the event to be set
	 * @param string[] $properties the properties to be set with event
	 * @param string[] $ec additional enhanced ecommerce data to be sent with the event
	 * @param string[] $identities (optional) identities to use when tracking the event - if not provided, auto-detects from GA cookie and current user
	 */
	public function api_record_event( $event_name, $properties = array(), $ec = array(), $identities = null, $admin_event = false ) {

		$user_id = is_array( $identities ) && isset( $identities['uid'] ) ? $identities['uid'] : null;

		// verify tracking status
		if ( $this->do_not_track( $admin_event, $user_id ) ) {
			return;
		}

		// remove blank properties/ec properties
		unset( $properties[''] );
		unset( $ec[''] );

		// auto-detect identities, if not provided
		if ( ! is_array( $identities ) || empty( $identities ) || empty( $identities['cid'] ) ) {
			$identities = $this->get_identities();
		}

		// checking if CID is not null
		if ( empty( $identities['cid'] ) ) {
			return;
		}

		// remove user ID, unless user ID tracking is enabled,
		if ( 'yes' !== $this->get_option( 'track_user_id' ) && isset( $identities['uid'] ) ) {
			unset( $identities['uid'] );
		}

		// set IP and user-agent overrides, unless already provided
		if ( empty( $identities['uip'] ) ) {
			$identities['uip'] = $this->get_client_ip();
		}

		if ( empty( $identities['ua'] ) ) {
			$identities['ua'] = SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? wc_get_user_agent() : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '' );
		}

		// track the event via Measurement Protocol
		$this->get_api()->track_event( $event_name, $identities, $properties, $ec );
	}


	/**
	 * Gets the code to add a product to the tracking code.
	 *
	 * @since 1.0.0
	 * @global array $woocommerce_loop The WooCommerce loop position data
	 * @param int $product_id ID of the product to add.
	 * @param int $quantity Optional. Quantity to add to the code.
	 * @return string Code to use within a tracking code.
	 */
	private function get_ec_add_product_js( $product_id, $quantity = 1 ) {
		global $woocommerce_loop;

		$product = wc_get_product( $product_id );

		/**
		 * Filters the product details data (productFieldObject).
		 *
		 * @link https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce#product-data
		 *
		 * @since 1.1.1
		 * @param array $product_details_data An associative array of product product details data
		 */
		$product_details_data = apply_filters( 'wc_google_analytics_pro_product_details_data', array(
			'id'       => $this->get_product_identifier( $product ),
			'name'     => $product->get_title(),
			'brand'    => '',
			'category' => $this->get_category_hierarchy( $product ),
			'variant'  => $this->get_product_variation_attributes( $product ),
			'price'    => $product->get_price(),
			'quantity' => $quantity,
			'position' => isset( $woocommerce_loop['loop'] ) ? $woocommerce_loop['loop'] : '',
		) );

		$js = sprintf(
			"%s( 'ec:addProduct', %s );",
			$this->get_ga_function_name(),
			wp_json_encode( $product_details_data )
		);

		return $js;
	}


	/**
	 * Gets a unique identity for the current user.
	 *
	 * @link http://www.stumiller.me/implementing-google-analytics-measurement-protocol-in-php-and-wordpress/
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_generate_uuid (optional) whether to force generating a UUID if no CID can be found from cookies, defaults to false
	 * @return string the visitor's ID from Google's cookie, or user's meta, or generated
	 */
	private function get_cid( $force_generate_uuid = false ) {

		$identity = '';

		// get identity via GA cookie
		if ( isset( $_COOKIE['_ga'] ) ) {

			list( $version, $domainDepth, $cid1, $cid2 ) = preg_split( '[\.]', $_COOKIE['_ga'], 4 );

			$contents = array( 'version' => $version, 'domainDepth' => $domainDepth, 'cid' => $cid1 . '.' . $cid2 );
			$identity = $contents['cid'];
		}

		// generate UUID if identity is not set
		if ( empty( $identity ) ) {

			// neither cookie set and named identity not passed, cookies are probably disabled for visitor or GA tracking might be blocked
			if ( $this->debug_mode_on() ) {

				wc_google_analytics_pro()->log( 'No identity found. Cookies are probably disabled for visitor or GA tracking might be blocked.' );
			}

			// by default, a UUID will only be generated if we have no CID, we have a user logged in and user-id tracking is enabled
			// note: when changing this logic here, adjust the logic in WC_Google_Analytics_Pro_Email_Tracking::track_opens() as well
			$generate_uuid = $force_generate_uuid || ( ! $identity && is_user_logged_in() && 'yes' === $this->get_option( 'track_user_id' ) );

			/**
			 * Filters whether a client ID should be generated.
			 *
			 * Allows generating a UUID for to be used as the client ID, when it can't be determined from cookies or other sources, such as the order or user meta.
			 *
			 * @since 1.3.5
			 *
			 * @param bool $generate_uuid the generate UUID flag
			 */
			$generate_uuid = apply_filters( 'wc_google_analytics_pro_generate_client_id', $generate_uuid );

			if ( $generate_uuid ) {

				$identity = $this->generate_uuid();
			}
		}

		return $identity;
	}


	/**
	 * Gets an the current visitor identities.
	 *
	 * Returns 1 or 2 identities - the CID (GA client ID from cookie) and
	 * current user ID, if available.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_identities() {

		$identities = array();

		// get CID
		$cid = $this->get_cid();

		// set CID only if it is not null
		if ( ! empty( $cid ) ) {
			$identities['cid'] = $cid;
		}

		if ( is_user_logged_in() ) {
			$identities['uid'] = get_current_user_id();
		}

		return $identities;
	}


	/**
	 * Generates a UUID v4.
	 *
	 * Needed to generate a CID when one isn't available.
	 *
	 * @link https://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid/15875555#15875555
	 *
	 * @since 1.0.0
	 *
	 * @return string the generated UUID
	 */
	public function generate_uuid() {

		try {

			$bytes = random_bytes( 16 );

			$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 ); // set version to 0100
			$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 ); // set bits 6-7 to 10

			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );

		} catch( Exception $e ) {

			// fall back to mt_rand if random_bytes is unavailable
			return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
				// 32 bits for "time_low"
				mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
				// 16 bits for "time_mid"
				mt_rand( 0, 0xffff ),
				// 16 bits for "time_hi_and_version",
				// four most significant bits holds version number 4
				mt_rand( 0, 0x0fff ) | 0x4000,
				// 16 bits, 8 bits for "clk_seq_hi_res",
				// 8 bits for "clk_seq_low",
				// two most significant bits holds zero and one for variant DCE1.1
				mt_rand( 0, 0x3fff ) | 0x8000,
				// 48 bits for "node"
				mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
			);
		}
	}


	/**
	 * Determines if tracking is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @param  bool $admin_event (optional) Whether or not this is an admin event that should be tracked. Defaults to false.
	 * @param  int  $user_id     (optional) User ID to check roles for
	 * @return bool
	 */
	private function do_not_track( $admin_event = false, $user_id = null ) {

		$do_not_track = false;

		// do not track activity in the admin area, unless specified
		if ( ! $admin_event && ! is_ajax() && is_admin() ) {
			$do_not_track = true;
		} else {
			$do_not_track = ! $this->is_tracking_enabled_for_user_role( $user_id );
		}

		/**
		 * Filters whether tracking should be disabled.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $do_not_track
		 * @param bool $admin_event
		 * @param int  $user_id
		 */
		return (bool) apply_filters( 'wc_google_analytics_pro_do_not_track', $do_not_track, $admin_event, $user_id );
	}


	/**
	 * Determines if tracking should be performed for the provided user, by the role.
	 *
	 * In 1.3.5 removed the $admin_event param
	 *
	 * @since 1.0.0
	 * @param int $user_id (optional) user id to check, defaults to current user id
	 * @return bool
	 */
	public function is_tracking_enabled_for_user_role( $user_id = null ) {

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! isset( $this->user_tracking_enabled[ $user_id ] ) ) {

			// enable tracking by default for all users and visitors
			$enabled = true;

			// get user's info
			$user = get_user_by( 'id', $user_id );

			if ( $user && wc_google_analytics_pro()->is_monsterinsights_active() ) {

				// if MonsterInsights is active, use their setting for disallowed roles,
				// see Yoast_GA_Universal::do_tracking(), monsterinsights_disabled_user_group()
				$ignored_roles = wc_google_analytics_pro()->get_monsterinsights_option( 'ignore_users' );

				if ( ! empty( $ignored_roles ) ) {
					$enabled = array_intersect( $user->roles, $ignored_roles ) ? false : true;
				}

			} elseif ( $user && user_can( $user_id, 'manage_woocommerce' ) ) {

				// Enable tracking of admins and shop managers only if checked in settings.
				$enabled = 'yes' === $this->get_option( 'admin_tracking_enabled' );

			}

			$this->user_tracking_enabled[ $user_id ] = $enabled;
		}

		return $this->user_tracking_enabled[ $user_id ];
	}


	/**
	 * Determines if a request was not a page reload.
	 *
	 * Prevents duplication of tracking events when user submits
	 * a form, e.g. applying a coupon on the cart page.
	 *
	 * This is not intended to prevent pageview events on a manual page refresh.
	 * Those are valid user interactions and should still be tracked.
	 *
	 * @since 1.0.0
	 *
	 * @return bool true if not a page reload, false if page reload
	 */
	private function not_page_reload() {

		// no referer..consider it's not a reload.
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return true;
		}

		// compare paths
		return ( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_PATH ) !== parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
	}


	/**
	 * Returns the visitor's IP
	 *
	 * @since 1.3.0
	 * @return string client IP
	 */
	private function get_client_ip() {

		return WC_Geolocation::get_ip_address();
	}


	/** MonsterInsights integration methods *************************************************/


	/**
	 * Invoked by a filter at the end of MonsterInsights' tracking.
	 *
	 * If we came here then MonsterInsights is going to print the GA init script.
	 *
	 * In 1.3.0 renamed from `yoast_ga_push_array_universal` to `set_monsterinsights_tracking_data`
	 *
	 * @internal
	 *
	 * @see Yoast_GA_Universal::tracking
	 * @see MonsterInsights_Tracking_Analytics::frontend_tracking_options
	 *
	 * @since 1.0.0
	 * @param mixed $data the tracking data
	 * @return mixed
	 */
	public function set_monsterinsights_tracking_data( $data ) {

		$this->_monsterinsights_tracking_type = 'universal';

		// require Enhanced Ecommerce
		$data[] = "'require','ec'";

		// remove the pageview tracking, as we need to track it
		// in the footer instead (because of product impressions)
		if ( ! empty( $data ) ) {

			foreach ( $data as $key => $value ) {

				// check strpos rather than strict equal to account for search archives and 404 pages
				if ( strpos( $value, "'send','pageview'" ) !== false ) {
					unset( $data[ $key ] );
				}
			}
		}

		return $data;
	}


	/**
	 * Sets the internal MonsterInsights' tracking type.
	 *
	 * Invoked by a filter at the end of MonsterInsights' tracking.
	 * If we came here then MonsterInsights is going to print the GA init script.
	 *
	 * In 1.3.0 renamed from `set_yoast_ga_tracking_type_ga_js` to `set_monsterinsights_tracking_type_ga_js`
	 *
	 * @internal
	 *
	 * @see Yoast_GA_JS::tracking
	 *
	 * @since 1.0.0
	 * @param mixed $ignore Ignored because we just need a trigger, not data.
	 * @return mixed
	 */
	public function set_monsterinsights_tracking_type_ga_js( $ignore ) {

		$this->_monsterinsights_tracking_type = 'ga-js';

		return $ignore;
	}


	/**
	 * Returns MonsterInsights' GA tracking type.
	 *
	 * In 1.3.0 renamed from `get_yoast_ga_tracking_type` to `get_monsterinsights_tracking_type`
	 *
	 * @since 1.0.0
	 * @return string MonsterInsights' GA tracking type
	 */
	public function get_monsterinsights_tracking_type() {

		return $this->_monsterinsights_tracking_type;
	}


	/**
	 * Determines if MonsterInsights' tracking is active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_monsterinsights_tracking_active() {

		return $this->get_monsterinsights_tracking_type() !== '';
	}


	/**
	 * Determines if MonsterInsights' GA tracking is universal.
	 *
	 * In 1.3.0 renamed from `is_yoast_ga_tracking_universal` to `is_monsterinsights_tracking_universal`
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_monsterinsights_tracking_universal() {

		return 'universal' === $this->get_monsterinsights_tracking_type();
	}


	/** Helper methods ********************************************************/


	/**
	 * Determines if this tracking integration supports property names.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function supports_property_names() {

		return false;
	}


	/**
	 * Gets the plugin instance.
	 *
	 * @since 1.0.0
	 * @return \WC_Google_Analytics_Pro
	 */
	protected function get_plugin() {

		return wc_google_analytics_pro();
	}


	/**
	 * Gets the configured Google Analytics tracking ID.
	 *
	 * @since 1.0.0
	 * @return string the tracking ID
	 */
	public function get_tracking_id() {

		// MonsterInsights' settings override ours
		if ( wc_google_analytics_pro()->is_monsterinsights_active() ) {
			return class_exists( 'Yoast_GA_Options' ) ? Yoast_GA_Options::instance()->get_tracking_code() : monsterinsights_get_ua_to_output();
		}

		/**
		 * Filters the tracking ID for the Google Analytics property being used.
		 *
		 * @since 1.2.0
		 *
		 * @param string $tracking_id the tracking code
		 * @param \WC_Google_Analytics_Pro_Integration $integration the integration instance
		 */
		return apply_filters( 'wc_google_analytics_pro_tracking_id', $this->get_option( 'tracking_id' ), $this );
	}


	/**
	 * Gets the Measurement Protocol API wrapper.
	 *
	 * @since 1.0.0
	 * @return \WC_Google_Analytics_Pro_Measurement_Protocol_API
	 */
	public function get_api() {

		if ( is_object( $this->api ) ) {
			return $this->api;
		}

		// measurement protocol API wrapper
		require_once( wc_google_analytics_pro()->get_plugin_path() . '/includes/api/class-wc-google-analytics-pro-measurement-protocol-api.php' );

		// measurement protocol API request
		require_once( wc_google_analytics_pro()->get_plugin_path() . '/includes/api/class-wc-google-analytics-pro-measurement-protocol-api-request.php' );

		// measurement protocol API response
		require_once( wc_google_analytics_pro()->get_plugin_path() . '/includes/api/class-wc-google-analytics-pro-measurement-protocol-api-response.php' );

		return $this->api = new WC_Google_Analytics_Pro_Measurement_Protocol_API( $this->get_tracking_id() );
	}


	/**
	 * Gets the list type for the current screen.
	 *
	 * @since 1.0.0
	 * @return string the list type for the current screen
	 */
	public function get_list_type() {

		$list_type = '';

		if ( is_search() ) {

			$list_type = __( 'Search', 'woocommerce-google-analytics-pro' );

		} elseif ( is_product_category() ) {

			$list_type = __( 'Product category', 'woocommerce-google-analytics-pro' );

		} elseif ( is_product_tag() ) {

			$list_type = __( 'Product tag', 'woocommerce-google-analytics-pro' );

		} elseif ( is_archive() ) {

			$list_type = __( 'Archive', 'woocommerce-google-analytics-pro' );

		} elseif ( is_single() ) {

			$list_type = __( 'Related/Up sell', 'woocommerce-google-analytics-pro' );

		} elseif ( is_cart() ) {

			$list_type = __( 'Cross sell (cart)', 'woocommerce-google-analytics-pro' );
		}

		/**
		 * Filters the list type for the current screen.
		 *
		 * @since 1.0.0
		 * @param string $list_type the list type for the current screen
		 */
		return apply_filters( 'wc_google_analytics_pro_list_type', $list_type );
	}


	/**
	 * Returns the Enhanced Ecommerce action JavaScript of the provided event key if it exists.
	 *
	 * @since 1.3.0
	 * @param string $action the action, see https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce#action-types for available options
	 * @param array $args Optional. An array of args to be encoded as the `actionFieldObject`, see https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce#action-data for availalable options
	 * @param string|null $js_args_variable (optional) name of the JS variable to use for interpolating dynamic event properties
	 * @return string the JavaScript or an empty string
	 */
	private function get_ec_action_js( $action, $args = array(), $js_args_variable = null ) {

		$args = wp_json_encode( $args );

		// interpolate dynamic event properties
		if ( $js_args_variable ) {
			$args = sprintf( 'window.wc_ga_pro.interpolate_json( %s, %s )', $args, $js_args_variable );
		}

		return sprintf( "%s( 'ec:setAction', '%s', %s );", $this->get_ga_function_name(), $action, $args );
	}


	/** Settings **************************************************************/


	/**
	 * Initializes form fields in the format required by \WC_Integration.
	 *
	 * @see \SV_WC_Tracking_Integration::init_form_fields()
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields() {

		// initialize common fields
		parent::init_form_fields();

		$form_fields = array_merge( array(

			'tracking_settings_section' => array(
				'title' => __( 'Tracking Settings', 'woocommerce-google-analytics-pro' ),
				'type'  => 'title',
			),

			'enabled' => array(
				'title'   => __( 'Enable Google Analytics tracking', 'woocommerce-google-analytics-pro' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		),

		$this->get_auth_fields(),

		array(

			'use_manual_tracking_id' => array(
				'label'       => __( 'Enter tracking ID manually (not recommended)', 'woocommerce-google-analytics-pro' ),
				'type'        => 'checkbox',
				'class'       => 'js-wc-google-analytics-toggle-manual-tracking-id',
				'default'     => 'no',
				'desc_tip'    => __( "We won't be able to display reports or configure your account automatically", 'woocommerce-google-analytics-pro' ),
			),

			'tracking_id' => array(
				'title'       => __( 'Google Analytics tracking ID', 'woocommerce-google-analytics-pro' ),
				'label'       => __( 'Google Analytics tracking ID', 'woocommerce-google-analytics-pro' ),
				'description' => __( 'Go to your Google Analytics account to find your ID. e.g. <code>UA-XXXXX-X</code>', 'woocommerce-google-analytics-pro' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'UA-XXXXX-X',
			),

			'admin_tracking_enabled' => array(
				'title'       => __( 'Track Administrators?', 'woocommerce-google-analytics-pro' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'Check to enable tracking when logged in as Administrator or Shop Manager.', 'woocommerce-google-analytics-pro' ),
			),

			'enable_displayfeatures' => array(
				'title'         => __( 'Tracking Options', 'woocommerce-google-analytics-pro' ),
				'label'         => __( 'Use Advertising Features', 'woocommerce-google-analytics-pro' ),
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'start',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( 'Set the Google Analytics code to support Demographics and Interests Reports for Remarketing and Advertising. %1$sRead more about Advertising Features%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://support.google.com/analytics/answer/2700409" target="_blank">', '</a>' ),
			),

			'enable_linkid' => array(
				'label'         => __( 'Use Enhanced Link Attribution', 'woocommerce-google-analytics-pro' ),
				'type'          => 'checkbox',
				'default'       => 'no',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( 'Set the Google Analytics code to support Enhanced Link Attribution. %1$sRead more about Enhanced Link Attribution%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-link-attribution" target="_blank">', '</a>' ),
			),

			'anonymize_ip'          => array(
				'label'         => __( 'Anonymize IP addresses', 'woocommerce-google-analytics-pro' ),
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( 'Enabling this option is mandatory in certain countries due to national privacy laws. %1$sRead more about IP Anonymization%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://support.google.com/analytics/answer/2763052" target="_blank">', '</a>' ),
			),

			'track_user_id'         => array(
				'label'         => __( 'Track User ID', 'woocommerce-google-analytics-pro' ),
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( 'Enable User ID tracking. %1$sRead more about the User ID feature%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://support.google.com/analytics/answer/3123662" target="_blank">', '</a>' ),
			),

			'enable_google_optimize' => array(
				'title'         => __( 'Google Optimize', 'woocommerce-google-analytics-pro' ),
				'label'         => __( 'Enable Google Optimize', 'woocommerce-google-analytics-pro' ),
				'type'          => 'checkbox',
				'default'       => 'no',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( '%1$sRead more about Google Optimize%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://www.google.com/analytics/optimize" target="_blank">', '</a>' ),
			),

			'google_optimize_code' => array(
				'title'         => __( 'Google Optimize Code', 'woocommerce-google-analytics-pro' ),
				'type'          => 'text',
				'default'       => '',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'   => sprintf( __( 'e.g. "GTM-XXXXXX". %1$sRead more about this code%2$s', 'woocommerce-google-analytics-pro' ), '<a href="https://support.google.com/360suite/optimize/answer/6262084" target="_blank">', '</a>' ),
			),

			'track_product_impressions_on' => array(
				'title'       => __( 'Track product impressions on:', 'woocommerce-google-analytics-pro' ),
				'desc_tip'    => __( 'Control where product impressions are tracked.', 'woocommerce-google-analytics-pro' ),
				'description' => __( 'If you\'re running into issues, particularly if you see the "No HTTP response detected" error, try disabling product impressions on archive pages.', 'woocommerce-google-analytics-pro' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'single_product_pages' => __( 'Single Product Pages', 'woocommerce-google-analytics-pro' ),
					'archive_pages'        => __( 'Archive Pages', 'woocommerce-google-analytics-pro' ),
				),
				'default'     => array( 'single_product_pages', 'archive_pages' ),
			),

		),

		$this->form_fields,

		array(

			'funnel_steps_section' => array(
				'title'       => __( 'Checkout Funnel', 'woocommerce-google-analytics-pro' ),
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description' => sprintf( __( 'Configure your Analytics account to match the checkout funnel steps below to take advantage of %1$sCheckout Behavior Analysis%2$s.', 'woocommerce-google-analytics-pro' ), '<a href="https://support.google.com/analytics/answer/6014872?hl=en#cba">', '</a>' ),
				'type'        => 'title',
			),

			'funnel_steps' => array(
				'title' => __( 'Funnel Steps', 'woocommerce-google-analytics-pro' ),
				'type'  => 'ga_pro_funnel_steps',
			),

		)

		);

		// TODO: remove this block when removing backwards compatibility with __gaTracker {IT 2016-10-12}
		if ( get_option( 'woocommerce_google_analytics_upgraded_from_gatracker' ) ) {

			$compat_fields['function_name'] = array(
				'title'     => __( 'JavaScript function name', 'woocommerce-google-analytics-pro' ),
				/* translators: %1$s - function name, %2$s - function name */
				'description' => sprintf( __( 'Set the global tracker function name. %1$s is deprecated and support for it will be removed in a future version. IMPORTANT: set the function name to %2$s only after any custom code is updated to use %2$s.', 'woocommerce-google-analytics-pro' ), '<code>__gaTracker</code>', '<code>ga</code>' ),
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options' => array(
					'ga'          => 'ga ' . __( '(Recommended)', 'woocommerce-google-analytics-pro' ),
					'__gaTracker' => '__gaTracker',
				),
				'default' => '__gaTracker',
			);

			$form_fields = SV_WC_Helper::array_insert_after( $form_fields, 'additional_settings_section', $compat_fields );
		}

		/**
		 * Filters Google Analytics Pro Settings.
		 *
		 * @since 1.3.0
		 * @param array $settings settings fields
		 * @param \WC_Google_Analytics_Pro_Integration $ga_pro_integration instance
		 */
		$this->form_fields = apply_filters( 'wc_google_analytics_pro_settings', $form_fields, $this );
	}


	/**
	 * Outputs checkout funnel steps table.
	 *
	 * @since 1.3.0
	 * @param mixed $key
	 * @param mixed $data
	 */
	public function generate_ga_pro_funnel_steps_html( $key, $data ) {

		$columns = array(
			'step'    => __( 'Step', 'woocommerce-google-analytics-pro' ),
			'event'   => __( 'Event', 'woocommerce-google-analytics-pro' ),
			'name'    => __( 'Name', 'woocommerce-google-analytics-pro' ),
			'status'  => __( 'Enabled', 'woocommerce-google-analytics-pro' ),
		);

		$steps = array(
			1 => 'started_checkout',
			2 => 'provided_billing_email',
			3 => 'selected_payment_method',
			4 => 'placed_order',
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html__( $data['title'] ); ?></th>
			<td class="forminp">
				<table class="wc-google-analytics-pro-funnel-steps widefat" cellspacing="0">
					<thead>
						<tr>
							<?php
								foreach ( $columns as $key => $column ) {
									echo '<th class="' . esc_attr( $key ) . '">' . esc_html( $column ) . '</th>';
								}
							?>
						</tr>
					</thead>
					<tbody>
						<?php
							foreach ( $steps as $step => $event ) {

								echo '<tr class="event-' . esc_attr( $event ) . '" data-event="' . esc_attr( $event ) . '">';

								foreach ( $columns as $key => $column ) {

									switch ( $key ) {

										case 'step' :
											echo '<td class="step">' . $step . '</td>';
											break;

										case 'event' :
											$event_title = $this->get_event_title( $event );
											echo '<td class="event"><a href="#woocommerce_google_analytics_pro_' . esc_attr( $event ) . '_event_name">' . esc_html( $event_title ) . '</a></td>';
											break;

										case 'name' :
											echo '<td class="name">' . esc_html( $this->get_event_name( $event ) ) . '</td>';
											break;

										case 'status' :
											echo '<td class="status">';
											echo '<span class="status-enabled tips" ' . ( ! $this->get_event_name( $event ) ? 'style="display:none;"' : '' ) . ' data-tip="' . __( 'Yes', 'woocommerce-google-analytics-pro' ) . '">' . __( 'Yes', 'woocommerce-google-analytics-pro' ) . '</span>';
											echo '<span class="status-disabled tips" ' . ( $this->get_event_name( $event ) ? 'style="display:none;"' : '' ) . ' data-tip="' . __( 'Currently disabled, because the event name is not set.', 'woocommerce-google-analytics-pro' ) . '">-</span>';
											echo '</td>';
											break;
									}
								}

								echo '</tr>';
							}
						?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}


	/**
	 * Returns the authentication fields.
	 *
	 * Only when on the plugin settings screen as this requires an API call to GA to get property data.
	 *
	 * @since 1.0.0
	 * @return array the authentication fields or an empty array
	 */
	protected function get_auth_fields() {

		if ( ! wc_google_analytics_pro()->is_plugin_settings() ) {
			return array();
		}

		$auth_fields = array();

		$ga_properties      = $this->get_access_token() ? $this->get_ga_properties() : null;
		$auth_button_text = $this->get_access_token() ? esc_html__( 'Re-authenticate with your Google account', 'woocommerce-google-analytics-pro' ) : esc_html__( 'Authenticate with your Google account', 'woocommerce-google-analytics-pro' );

		if ( ! empty( $ga_properties ) ) {

			// add empty option so clearing the field is possible
			$ga_properties = array_merge( array( '' => '' ), $ga_properties );

			$auth_fields = array(
				'property' => array(
					'title'    => __( 'Google Analytics Property', 'woocommerce-google-analytics-pro' ),
					'type'     => 'deep_select',
					'default'  => '',
					'class'    => 'wc-enhanced-select-nostd',
					'options'  => $ga_properties,
					'custom_attributes' => array(
						'data-placeholder' => __( 'Select a property&hellip;', 'woocommerce-google-analytics-pro' ),
					),
					'desc_tip' => __( "Choose which Analytics property you want to track", 'woocommerce-google-analytics-pro' ),
				),
			);
		}

		$auth_fields['oauth_button'] = array(
			'type'     => 'button',
			'default'  => $auth_button_text,
			'class'    => 'button',
			'desc_tip' => __( 'We need view & edit access to your Analytics account so we can display reports and automatically configure Analytics settings for you.', 'woocommerce-google-analytics-pro' ),
		);

		if ( empty( $ga_properties ) ) {
			$auth_fields['oauth_button']['title'] = __( 'Google Analytics Property', 'woocommerce-google-analytics-pro' );
		}

		if ( $this->get_access_token() ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			$auth_fields['oauth_button']['description'] = sprintf( __( 'or %1$srevoke authorization%2$s' ), '<a href="#" class="js-wc-google-analytics-pro-revoke-authorization">', '</a>' );
		}

		return $auth_fields;
	}


	/**
	 * Gets the Google Client API authentication URL.
	 *
	 * @since 1.0.0
	 * @return string the Google Client API authentication URL
	 */
	public function get_auth_url() {

		return self::PROXY_URL . '/auth?callback=' . urlencode( $this->get_callback_url() );
	}


	/**
	 * Gets the Google Client API refresh token.
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	private function get_refresh_token() {

		return get_option( 'wc_google_analytics_pro_refresh_token' );
	}


	/**
	 * Gets the Google Client API refresh access token URL, if a refresh token
	 * is available.
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_access_token_refresh_url() {

		if ( $refresh_token = $this->get_refresh_token() ) {

			return self::PROXY_URL . '/auth/refresh?token=' . base64_encode( $refresh_token );
		}
	}


	/**
	 * Gets the Google Client API revoke access token URL, if a token is available.
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_access_token_revoke_url() {

		if ( $token = $this->get_access_token() ) {

			return self::PROXY_URL . '/auth/revoke?token=' . base64_encode( $token );
		}
	}


	/**
	 * Gets the Google Client API callback URL.
	 *
	 * @since 1.0.0
	 * @return string url
	 */
	public function get_callback_url() {

		return get_home_url( null, 'wc-api/wc-google-analytics-pro/auth' );
	}


	/** Event tracking methods ******************************/


	/**
	 * Tracks a pageview.
	 *
	 * @since 1.0.0
	 */
	public function pageview() {

		if ( $this->do_not_track() ) {
			return;
		}

		// MonsterInsights is in non-universal mode, skip
		if ( $this->is_monsterinsights_tracking_active() && ! $this->is_monsterinsights_tracking_universal() ) {
			return;
		}

		$this->enqueue_js( 'pageview', $this->get_ga_function_name() . "( 'send', 'pageview' );" );
	}


	/**
	 * Tracks a homepage view.
	 *
	 * @since 1.1.3
	 */
	public function viewed_homepage() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		if ( is_front_page() && $this->event_name['viewed_homepage'] ) {

			$properties = array(
				'eventCategory'  => 'Homepage',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['viewed_homepage'], $properties );
		}
	}


	/**
	 * Tracks the log-in event.
	 *
	 * @since 1.0.0
	 * @param string $user_login the signed-in username
	 * @param \WP_User $user the logged-in user object
	 */
	public function signed_in( $user_login, $user ) {

		/**
		 * Filters the user roles track on the signed in event.
		 *
		 * @since 1.0.0
		 * @param string[] array of user roles to track the event for
		 */
		if ( isset( $user->roles[0] ) && in_array( $user->roles[0], apply_filters( 'wc_google_analytics_pro_signed_in_user_roles', array( 'subscriber', 'customer' ) ), true ) ) {

			$properties = array(
				'eventCategory' => 'My Account',
				'eventLabel'    => $user_login,
			);

			$ec      = null;
			$post_id = url_to_postid( wp_unslash( $_SERVER['REQUEST_URI'] ) );

			// logged in at checkout
			if ( $post_id && $post_id === (int) get_option( 'woocommerce_checkout_page_id' ) ) {
				$ec = array( 'checkout_option' => array(
					'step'   => 1,
					'option' => __( 'Registered User', 'woocommerce-google-analytics-pro' ) // can't check is_user_logged_in() as it still returns false here
				));
			}

			$this->api_record_event( $this->event_name['signed_in'], $properties, $ec );

			// get CID
			$cid = $this->get_cid();

			// store CID in user meta if it is not empty
			if ( ! empty( $cid ) ) {

				// store GA identity in user meta
				update_user_meta( $user->ID, '_wc_google_analytics_pro_identity', $cid );
			}
		}
	}


	/**
	 * Tracks a sign-out
	 *
	 * @since 1.0.0
	 */
	public function signed_out() {

		$this->api_record_event( $this->event_name['signed_out'] );
	}


	/**
	 * Tracks sign up page view (on my account page when enabled).
	 *
	 * @since 1.0.0
	 */
	public function viewed_signup() {

		if ( $this->not_page_reload() ) {

			$properties = array(
				'eventCategory'  => 'My Account',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['viewed_signup'], $properties );
		}
	}


	/**
	 * Tracks the sign up event.
	 *
	 * @since 1.0.0
	 */
	public function signed_up() {

		$properties = array(
			'eventCategory' => 'My Account',
		);

		$this->api_record_event( $this->event_name['signed_up'], $properties );
	}


	/**
	 * Track a product view.
	 *
	 * @since 1.0.0
	 */
	public function viewed_product() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		if ( $this->not_page_reload() ) {

			// add Enhanced Ecommerce tracking
			$product_id = get_the_ID();

			// JS add product
			$js = $this->get_ec_add_product_js( $product_id );

			// JS add action
			$js .= $this->get_ec_action_js( 'detail' );

			// enqueue JS
			$this->enqueue_js( 'event', $js );

			// set event properties - EC data will be sent with the event
			$properties = array(
				'eventCategory'  => 'Products',
				'eventLabel'     => esc_js( get_the_title() ),
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['viewed_product'], $properties );
		}
	}


	/**
	 * Tracks a product click event.
	 *
	 * @since 1.0.0
	 */
	public function clicked_product() {

		if ( $this->do_not_track() ) {
			return;
		}

		// MonsterInsights is in non-universal mode, skip
		if ( $this->is_monsterinsights_tracking_active() && ! $this->is_monsterinsights_tracking_universal() ) {
			return;
		}

		global $product;

		$list       = $this->get_list_type();
		$properties = array(
			'eventCategory' => 'Products',
			'eventLabel'    => htmlentities( $product->get_title(), ENT_QUOTES, 'UTF-8' ),
		);

		$product_id = ( $parent_id = SV_WC_Product_Compatibility::get_prop( $product, 'parent_id' ) ) ? $parent_id : SV_WC_Product_Compatibility::get_prop( $product, 'id' );

		$js =
			"$( '.products .post-" . esc_js( $product_id ) . " a' ).click( function() {
				if ( true === $(this).hasClass( 'add_to_cart_button' ) ) {
					return;
				}
				" . $this->get_ec_add_product_js( $product_id ) . $this->get_ec_action_js( 'click', array( 'list' => $list ) ) . $this->get_event_tracking_js( $this->event_name['clicked_product'], $properties ) . "
			});";

		$this->enqueue_js( 'event', $js );
	}


	/**
	 * Tracks the (non-ajax) add-to-cart event.
	 *
	 * @since 1.0.0
	 * @param string $cart_item_key  the unique cart item ID
	 * @param int    $product_id     the product ID
	 * @param int    $quantity       the quantity added to the cart
	 * @param int    $variation_id   the variation ID
	 * @param array  $variation      the variation data
	 * @param array  $cart_item_data the cart item data
	 */
	public function added_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {

		// don't track add to cart from AJAX here
		if ( is_ajax() ) {
			return;
		}

		$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

		$properties = array(
			'eventCategory' => 'Products',
			'eventLabel'    => htmlentities( $product->get_title(), ENT_QUOTES, 'UTF-8' ),
			'eventValue'    => (int) $quantity,
		);

		if ( ! empty( $variation ) ) {

			// added a variable product to cart, set attributes as properties
			// remove 'pa_' from keys to keep property names consistent
			$variation = array_flip( str_replace( 'attribute_', '', array_flip( $variation ) ) );

			$properties = array_merge( $properties, $variation );
		}

		$ec = array( 'add_to_cart' => array( 'product' => $product, 'quantity' => $quantity ) );

		$this->api_record_event( $this->event_name['added_to_cart'], $properties, $ec );
	}


	/**
	 * Tracks the (ajax) add-to-cart event.
	 *
	 * @since 1.0.0
	 * @param int $product_id the product ID
	 */
	public function ajax_added_to_cart( $product_id ) {

		$product = wc_get_product( $product_id );

		$properties = array(
			'eventCategory' => 'Products',
			'eventLabel'    => htmlentities( $product->get_title(), ENT_QUOTES, 'UTF-8' ),
			'eventValue'    => 1,
		);

		$ec = array( 'add_to_cart' => array( 'product' => $product, 'quantity' => 1 ) );

		$this->api_record_event( $this->event_name['added_to_cart'], $properties, $ec );
	}


	/**
	 * Tracks a product cart removal event.
	 *
	 * @since 1.0.0
	 * @param string $cart_item_key the unique cart item ID
	 */
	public function removed_from_cart( $cart_item_key ) {

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {

			$item    = WC()->cart->cart_contents[ $cart_item_key ];
			$product = ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : wc_get_product( $item['product_id'] );

			$properties = array(
				'eventCategory' => 'Cart',
				'eventLabel'    => htmlentities( $product->get_title(), ENT_QUOTES, 'UTF-8' ),
			);

			$ec = array( 'remove_from_cart' => array( 'product' => $product ) );

			$this->api_record_event( $this->event_name['removed_from_cart'], $properties, $ec );
		}
	}


	/**
	 * Tracks the cart changed quantity event.
	 *
	 * @since 1.0.0
	 * @param string $cart_item_key the unique cart item ID
	 * @param int $quantity the changed quantity
	 */
	public function changed_cart_quantity( $cart_item_key, $quantity ) {;

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {

			$item    = WC()->cart->cart_contents[ $cart_item_key ];
			$product = wc_get_product( $item['product_id'] );

			$properties = array(
				'eventCategory' => 'Cart',
				'eventLabel'    => htmlentities( $product->get_title(), ENT_QUOTES, 'UTF-8' ),
			);

			$this->api_record_event( $this->event_name['changed_cart_quantity'], $properties );
		}
	}


	/**
	 * Tracks a cart page view.
	 *
	 * @since 1.0.0
	 */
	public function viewed_cart() {

		if ( $this->not_page_reload() ) {

			// enhanced Ecommerce tracking
			$js = '';

			foreach ( WC()->cart->get_cart() as $item ) {

				// JS add product
				$js .= $this->get_ec_add_product_js( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $item['quantity'] );
			}

			// enqueue JS
			$this->enqueue_js( 'event', $js );

			$properties = array(
				'eventCategory'  => 'Cart',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['viewed_cart'], $properties );
		}
	}


	/**
	 * Tracks the start of checkout.
	 *
	 * @since 1.0.0
	 */
	public function started_checkout() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		if ( $this->not_page_reload() ) {

			// enhanced Ecommerce tracking
			$js = '';

			foreach ( WC()->cart->get_cart() as $item ) {

				// JS add product
				$js .= $this->get_ec_add_product_js( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $item['quantity'] );
			}

			// JS checkout action
			$args = array(
				'step'   => 1,
				'option' => ( is_user_logged_in() ? __( 'Registered User', 'woocommerce-google-analytics-pro' ) : __( 'Guest', 'woocommerce-google-analytics-pro' ) ),
			);

			$js .= $this->get_ec_action_js( 'checkout', $args );

			// enqueue JS
			$this->enqueue_js( 'event', $js );

			// set event properties
			$properties = array(
				'eventCategory'  => 'Checkout',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['started_checkout'], $properties );
		}
	}


	/**
	 * Tracks when a customer provides a billing email on checkout.
	 *
	 * @since 1.3.0
	 */
	public function provided_billing_email() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		// set event properties
		$properties = array(
			'eventCategory' => 'Checkout',
		);

		// enhanced ecommerce tracking
		$handler_js = '';

		foreach ( WC()->cart->get_cart() as $item ) {

			// JS add product
			$handler_js .= $this->get_ec_add_product_js( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $item['quantity'] );
		}

		// JS checkout action
		$args = array( 'step' => 2 );

		$handler_js .= $this->get_ec_action_js( 'checkout', $args );

		// event
		$handler_js .= $this->get_event_tracking_js( $this->event_name['provided_billing_email'], $properties );

		$user_logged_in = is_user_logged_in();
		$billing_email  = null;

		// TODO it looks like in WooCommerce versions prior to v3.0 there isn't a get_billing_email() method equivalent, and using the current user WP email isn't accurate {FN 2017-04-21}
		if ( $user_logged_in ) {
			if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {
				$billing_email = WC()->customer->get_billing_email();
			} else {
				$current_user  = wp_get_current_user();
				$billing_email = $current_user->user_email;
			}
		}

		// track the billing email only once for the logged in user, if they have one
		if ( $user_logged_in && is_email( $billing_email ) && $this->not_page_reload() ) {
			$js = sprintf( "if ( ! wc_ga_pro.payment_method_tracked ) { %s };", $handler_js );
		} elseif ( ! $user_logged_in ) {
			// track billing email once it's provided & valid
			$js = sprintf( "$( 'form.checkout' ).on( 'change', 'input#billing_email', function() { if ( ! wc_ga_pro.provided_billing_email && wc_ga_pro.is_valid_email( this.value ) ) { wc_ga_pro.provided_billing_email = true; %s } });", $handler_js );
		}

		if ( ! empty( $js ) ) {
			$this->enqueue_js( 'event', $js );
		}
	}


	/**
	 * Tracks payment method selection event on checkout.
	 *
	 * @since 1.3.0
	 */
	public function selected_payment_method() {

		// bail if tracking is disabled
		if ( $this->do_not_track() ) {
			return;
		}

		// set event properties
		$properties = array(
			'eventCategory' => 'Checkout',
			'eventLabel'    => '{$payment_method}',
		);

		// enhanced ecommerce tracking
		$handler_js = '';

		foreach ( WC()->cart->get_cart() as $item ) {

			// JS add product
			$handler_js .= $this->get_ec_add_product_js( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $item['quantity'] );
		}

		// JS checkout action
		$args = array( 'step' => 3, 'option' => '{$payment_method}' );

		$handler_js .= $this->get_ec_action_js( 'checkout', $args, 'args' );

		// event
		$handler_js .= $this->get_event_tracking_js( $this->event_name['selected_payment_method'], $properties, 'args' );

		$js = '';

		/**
		 * Filters whether the initial payment method selection should be ignored.
		 *
		 * WooCommerce automatically selects a payment method when the checkout page is loaded.
		 * Allow the tracking of this automatic selection to be enabled or disabled.
		 *
		 * @since 1.4.1
		 *
		 * @param bool $ignore_initial_payment_method_selection
		 */
		if ( true === apply_filters( 'wc_google_analytics_pro_ignore_initial_payment_method_selection', true ) ) {
			$js .= 'wc_ga_pro.selected_payment_method = $( "input[name=\'payment_method\']:checked" ).val();';
		}

		// listen to payment method selection event
		$js .= sprintf( "$( 'form.checkout' ).on( 'click', 'input[name=\"payment_method\"]', function( e ) { if ( wc_ga_pro.selected_payment_method !== this.value ) { var args = { payment_method: wc_ga_pro.get_payment_method_title( this.value ) }; wc_ga_pro.payment_method_tracked = true; %s wc_ga_pro.selected_payment_method = this.value; } });", $handler_js );

		// fall back to sending the payment method on checkout_place_order (clicked place order)
		$js .= sprintf( "$( 'form.checkout' ).on( 'checkout_place_order', function() { if ( ! wc_ga_pro.payment_method_tracked ) { var args = { payment_method: wc_ga_pro.get_payment_method_title( $( 'input[name=\"payment_method\"]' ).val() ) }; %s } });", $handler_js );


		$this->enqueue_js( 'event', $js );
	}


	/**
	 * Tracks "Place Order" event in checkout.
	 *
	 * @since 1.3.0
	 * @param int $order_id the order ID
	 */
	public function placed_order( $order_id ) {

		$order = wc_get_order( $order_id );

		$properties = array(
			'eventCategory'  => 'Checkout',
			'eventLabel'     => $order->get_order_number(),
			'nonInteraction' => true,
		);

		$ec = array( 'checkout' => array( 'order' => $order, 'step' => 4, 'option' => $order->get_shipping_method() ) );

		$this->api_record_event( $this->event_name['placed_order'], $properties, $ec );
	}


	/**
	 * Tracks the start of payment at checkout.
	 *
	 * @since 1.0.0
	 */
	public function started_payment() {

		if ( $this->not_page_reload() ) {

			$properties = array(
				'eventCategory'  => 'Checkout',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['started_payment'], $properties );
		}
	}


	/**
	 * Tracks when someone is commenting.
	 *
	 * This can be a regular comment or an product review.
	 *
	 * @since 1.0.0
	 */

	public function wrote_review_or_commented() {

		// separate comments from review tracking
		$type = get_post_type();

		if ( 'product' === $type ) {

			$properties = array(
				'eventCategory' => 'Products',
				'eventLabel'    => get_the_title(),
			);

			if ( $this->event_name['wrote_review'] ) {
				$this->api_record_event( $this->event_name['wrote_review'], $properties );
			}

		} elseif ( 'post' === $type ) {

			$properties = array(
				'eventCategory' => 'Post',
				'eventLabel'    => get_the_title(),
			);

			if ( $this->event_name['commented'] ) {
				$this->api_record_event( $this->event_name['commented'], $properties );
			}
		}
	}


	/**
	 * Tracks a completed purchase and records revenue/sales with GA.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 */
	public function completed_purchase( $order_id ) {

		/**
		 * Filters whether the completed purchase event should be tracked or not.
		 *
		 * @since 1.1.5
		 * @param bool $do_not_track true to not track the event, false otherwise
		 * @param int $order_id the order ID
		 */
		if ( true === apply_filters( 'wc_google_analytics_pro_do_not_track_completed_purchase', false, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		// bail if tracking is disabled but not if the status is being manually changed by the admin
		if ( ! $this->is_tracking_enabled_for_user_role( SV_WC_Order_Compatibility::get_prop( $order, 'customer_id' ) ) ) {
			return;
		}

		// don't track order when its already tracked
		if ( 'yes' === get_post_meta( $order_id, '_wc_google_analytics_pro_tracked', true ) ) {
			return;
		}

		// record purchase event
		$properties = array(
			'eventCategory' => 'Checkout',
			'eventLabel'    => $order->get_order_number(),
			'eventValue'    => round( $order->get_total() * 100 ),
		);

		// set to non-interaction if this is a renewal order
		if ( class_exists( 'WC_Subscriptions_Renewal_Order' ) && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) ) ) {
			$properties['nonInteraction'] = 1;
		}

		$ec = array( 'purchase' => array( 'order' => $order ) );

		$identities = $this->get_order_identities( $order );

		$this->api_record_event( $this->event_name['completed_purchase'], $properties, $ec, $identities, true );

		// mark order as tracked
		update_post_meta( SV_WC_Order_Compatibility::get_prop( $order, 'id' ), '_wc_google_analytics_pro_tracked', 'yes' );
	}


	/**
	 * Checks 'On Hold' orders to see if we should record a completed transaction or not.
	 *
	 * Currently, the only reason we might want to do this is if Paypal returns On Hold
	 * from the IPN. This is usually due to an email address mismatch, and the payment has
	 * technically already been captured at this point.
	 *
	 * @see https://github.com/skyverge/wc-plugins/issues/2332
	 *
	 * @since 1.4.1
	 *
	 * @param int $order_id
	 */
	public function purchase_on_hold( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( 'paypal' === $order->get_payment_method() ) {
			$this->completed_purchase( $order_id );
		}
	}


	/**
	 * Tracks an account page view.
	 *
	 * @since 1.0.0
	 */
	public function viewed_account() {

		if ( $this->not_page_reload() ) {

			$properties = array(
				'eventCategory'  => 'My Account',
				'nonInteraction' => true,
			);

			$this->js_record_event( $this->event_name['viewed_account'], $properties );
		}
	}


	/**
	 * Tracks an order view.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 */
	public function viewed_order( $order_id ) {

		if ( $this->not_page_reload() ) {

			$order = wc_get_order( $order_id );

			$properties = array(
				'eventCategory'  => 'Orders',
				'eventLabel'     => $order->get_order_number(),
				'nonInteraction' => true,
			);

			$this->api_record_event( $this->event_name['viewed_order'], $properties );
		}
	}


	/**
	 * Tracks the updated address event.
	 *
	 * @since 1.0.0
	 */
	public function updated_address() {

		if ( $this->not_page_reload() ) {

			$properties = array(
				'eventCategory' => 'My Account',
			);

			$this->api_record_event( $this->event_name['updated_address'], $properties );
		}
	}


	/**
	 * Tracks the changed password event.
	 *
	 * @since 1.0.0
	 */
	public function changed_password() {

		if ( ! empty( $_POST['password_1'] ) && $this->not_page_reload() ) {

			$properties = array(
				'eventCategory' => 'My Account',
			);

			$this->api_record_event( $this->event_name['changed_password'], $properties );
		}
	}


	/**
	 * Tracks the apply coupon event.
	 *
	 * @since 1.0.0
	 * @param string $coupon_code the coupon code that is being applied
	 */
	public function applied_coupon( $coupon_code ) {

		$properties = array(
			'eventCategory' => 'Coupons',
			'eventLabel'    => $coupon_code,
		);

		$this->api_record_event( $this->event_name['applied_coupon'], $properties );
	}


	/**
	 * Tracks the coupon removal event.
	 *
	 * @since 1.0.0
	 */
	public function removed_coupon() {

		if ( $this->not_page_reload() ) {

			$properties = array(
				'eventCategory' => 'Coupons',
				'eventLabel'    => $_GET['remove_coupon'],
			);

			$this->api_record_event( $this->event_name['removed_coupon'], $properties );
		}
	}


	/**
	 * Tracks the 'track order' event.
	 *
	 * @since 1.0.0
	 * @param int $order_id ID of the order being tracked.
	 */
	public function tracked_order( $order_id ) {

		if ( $this->not_page_reload() ) {

			$order = wc_get_order( $order_id );

			$properties = array(
				'eventCategory' => 'Orders',
				'eventLabel'    => $order->get_order_number(),
			);

			$this->api_record_event( $this->event_name['tracked_order'], $properties );
		}
	}


	/**
	 * Tracks the "calculate shipping" event.
	 *
	 * @since 1.0.0
	 */
	public function estimated_shipping() {

		$properties = array(
			'eventCategory' => 'Cart',
		);

		$this->api_record_event( $this->event_name['estimated_shipping'], $properties );
	}


	/**
	 * Tracks when an order is cancelled.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 */
	public function cancelled_order( $order_id ) {

		$order = wc_get_order( $order_id );

		$properties = array(
			'eventCategory' => 'Orders',
			'eventLabel'    => $order->get_order_number(),
		);

		$this->api_record_event( $this->event_name['cancelled_order'], $properties );
	}


	/**
	 * Tracks when an order is refunded.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 */
	public function order_refunded( $order_id, $refund_id ) {

		// don't track if the refund is already tracked
		if ( 'yes' === get_post_meta( $refund_id, '_wc_google_analytics_pro_tracked' ) ) {
			return;
		}

		$order          = wc_get_order( $order_id );
		$refund         = wc_get_order( $refund_id );
		$refunded_items = array();

		// TODO partial refunds should work, as per https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce#measuring-refunds,
		// however, I could not get them to work - GA would simply not record a refund if any products (items) were set with the `refund` product action.
		// Disabled until we can figure out a solution. If you read this and can fix it, please apply for a position at info@skyverge.com {IT 2017-05-02}

		// get refunded items
		// $items = $refund->get_items();

		// if ( ! empty( $items ) ) {

		// 	foreach ( $items as $item_id => $item ) {

		// 		// any item with a quantity and line total is refunded
		// 		if ( abs( $item['qty'] ) >= 1 && abs( $refund->get_line_total( $item ) ) >= 0 ) {
		// 			$refunded_items[ $item_id ] = $item;
		// 		}
		// 	}
		// }

		$refund_amount = SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $refund->get_amount() : $refund->get_refund_amount();

		$properties = array(
			'eventCategory' => 'Orders',
			'eventLabel'    => $order->get_order_number(),
			'eventValue'    => round( $refund_amount * 100 ),
		);

		// Enhanced Ecommerce can only track full refunds and refunds for specific items
		if ( doing_action( 'woocommerce_order_fully_refunded' ) || ! empty( $refunded_items ) ) {
			$ec = array( 'refund' => array( 'order' => $order, 'refunded_items' => $refunded_items ) );
		} else {
			$ec = null;
		}

		$identities = $this->get_order_identities( $order );

		$this->api_record_event( $this->event_name['order_refunded'], $properties, $ec, $identities, true );

		// mark refund as tracked
		update_post_meta( $refund_id, '_wc_google_analytics_pro_tracked', 'yes' );
	}


	/**
	 * Tracks when someone uses the "Order Again" button.
	 *
	 * @since 1.0.0
	 */
	public function reordered( $order_id ) {

		if ( $this->not_page_reload() ) {

			$order = wc_get_order( $order_id );

			$properties = array(
				'eventCategory' => 'Orders',
				'eventLabel'    => $order->get_order_number(),
			);

			$this->api_record_event( $this->event_name['reordered'], $properties );
		}
	}


	/** Enhanced e-commerce specific methods **********************/


	/**
	 * Tracks a product impression.
	 *
	 * An impression is the listing of a product anywhere on the website, e.g.
	 * search/archive/category/related/cross sell.
	 *
	 * @since 1.0.0
	 */
	public function product_impression() {

		if ( $this->do_not_track() ) {
			return;
		}

		// MonsterInsights is in non-universal mode, skip
		if ( $this->is_monsterinsights_tracking_active() && ! $this->is_monsterinsights_tracking_universal() ) {
			return;
		}

		$track_on = $this->get_option( 'track_product_impressions_on', array() );

		// bail if product impression tracking is disabled on product pages and we're on a prdouct page
		// note: this doesn't account for the [product_page] shortcode unfortunately
		if ( ! in_array( 'single_product_pages', $track_on, true ) && is_product() ) {
			return;
		}

		// bail if product impression tracking is disabled on product archive pages and we're on an archive page
		if ( ! in_array( 'archive_pages', $track_on, true ) && ( is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag() ) ) {
			return;
		}

		global $product, $woocommerce_loop;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$attributes = array();
		$variant    = '';

		if ( 'variable' === $product->get_type() ) {

			if ( SV_WC_Plugin_Compatibility::is_wc_version_lt_3_0() ) {
				$attributes = $product->get_variation_default_attributes();
			} else {
				$attributes = $product->get_default_attributes();
			}

			$variant = implode( ', ', array_values( $attributes ) );
		}

		$product_identifier = wc_google_analytics_pro()->get_integration()->get_product_identifier( $product );

		// set up impression data as associative array and merge attributes to be sent as custom dimensions
		$impression_data = array_merge( array(
			'id'       => $this->get_product_identifier( $product ),
			'name'     => $product->get_title(),
			'list'     => $this->get_list_type(),
			'brand'    => '',
			'category' => $this->get_category_hierarchy( $product ),
			'variant'  => $this->get_product_variation_attributes( $product ),
			'position' => isset( $woocommerce_loop['loop'] ) ? $woocommerce_loop['loop'] : 1,
			'price'    => $product->get_price(),
		), $attributes );

		/**
		 * Filters the product impression data (impressionFieldObject).
		 *
		 * @link https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce#impression-data
		 *
		 * @since 1.1.1
		 * @param array $impression_data An associative array of product impression data
		 */
		$impression_data = apply_filters( 'wc_google_analytics_pro_product_impression_data', $impression_data );

		// unset empty values to reduce request size
		foreach ( $impression_data as $key => $value ) {

			if ( empty( $value ) ) {
				unset( $impression_data[ $key ] );
			}
		}

		$this->enqueue_js( 'impression', sprintf(
			"%s( 'ec:addImpression', %s );",
			$this->get_ga_function_name(),
			wp_json_encode( $impression_data )
		) );
	}


	/**
	 * Tracks a custom event.
	 *
	 * Contains excess checks to account for any kind of user input.
	 *
	 * @since 1.0.0
	 * @param string $event_name the event name
	 * @param array $properties Optional. The event properties
	 */
	public function custom_event( $event_name = false, $properties = false ) {

		if ( isset( $event_name ) && $event_name != '' && strlen( $event_name ) > 0 ) {

			// sanitize property names and values
			$prop_array = false;
			$props      = false;

			if ( isset( $properties ) && is_array( $properties ) && count( $properties ) > 0 ) {

				foreach ( $properties as $k => $v ) {

					$key   = $this->sanitize_event_string( $k );
					$value = $this->sanitize_event_string( $v );

					if ( $key && $value ) {
						$prop_array[$key] = $value;
					}
				}

				$props = false;

				if ( $prop_array && is_array( $prop_array ) && count( $prop_array ) > 0 ) {
					$props = $prop_array;
				}
			}

			// sanitize event name
			$event = $this->sanitize_event_string( $event_name );

			// if everything checks out then trigger event
			if ( $event ) {
				$this->api_record_event( $event, $props );
			}
		}
	}


	/**
	 * Sanitizes a custom event string.
	 *
	 * Contains excess checks to account for any kind of user input.
	 *
	 * @since 1.0.0
	 * @param string $str
	 * @return string|bool the santitized string or false on failure
	 */
	private function sanitize_event_string( $str = false ) {

		if ( isset( $str ) ) {

			// remove excess spaces
			$str = trim( $str );

			return $str;
		}

		return false;
	}


	/**
	 * Stores the GA Identity (CID) on an order.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 */
	public function store_ga_identity( $order_id ) {

		// get CID - ensuring that order will always have some kind of client id, so that
		// the transactions are properly tracked and reported in GA
		$cid = $this->get_cid( true );

		// store CID in order meta if it is not empty
		if ( ! empty( $cid ) ) {

			update_post_meta( $order_id, '_wc_google_analytics_pro_identity', $cid );
		}
	}


	/**
	 * Gets the GA Identity associated with an order.
	 *
	 * @since 1.0.0
	 * @param int $order_id the order ID
	 * @return string
	 */
	public function get_order_ga_identity( $order_id ) {

		return get_post_meta( $order_id, '_wc_google_analytics_pro_identity', true );
	}


	/**
	 * Gets the identities associated with a given order in the format useful for submission to Google Analytics.
	 *
	 * @since 1.5.0
	 *
	 * @param \WC_Order $order
	 * @return array
	 */
	public function get_order_identities( $order ) {

		$cid = $this->get_order_ga_identity( SV_WC_Order_Compatibility::get_prop( $order, 'id' ) );

		return array(
			'cid' => $cid ? $cid : $this->get_cid(),
			'uid' => SV_WC_Order_Compatibility::get_prop( $order, 'customer_id' ),
			'uip' => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $order->get_customer_ip_address() : $order->customer_ip_address,
			'ua'  => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $order->get_customer_user_agent() : $order->customer_user_agent,
		);
	}


	/**
	 * Authenticates with Google API.
	 *
	 * @since 1.0.0
	 */
	public function authenticate() {

		// missing token
		if ( ! isset( $_REQUEST['token'] ) || ! $_REQUEST['token'] ) {
			return;
		}

		$json_token = base64_decode( $_REQUEST['token'] );
		$token      = json_decode( $json_token, true );

		// invalid token
		if ( ! $token ) {
			return;
		}

		// update access token
		update_option( 'wc_google_analytics_pro_access_token', $json_token );
		update_option( 'wc_google_analytics_pro_account_id', md5( $json_token ) );
		delete_transient( 'wc_google_analytics_pro_properties' );

		// update refresh token
		if ( isset( $token['refresh_token'] ) ) {
			update_option( 'wc_google_analytics_pro_refresh_token', $token['refresh_token'] );
		}

		echo '<script>window.opener.wc_google_analytics_pro.auth_callback(' . $json_token . ');</script>';
		exit();
	}


	/**
	 * Gets the current access token.
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_access_token() {

		return get_option( 'wc_google_analytics_pro_access_token' );
	}


	/**
	 * Gets Google Client API instance.
	 *
	 * @since 1.0.0
	 * @return \Google_Client
	 */
	public function get_ga_client() {

		if ( ! isset( $this->ga_client ) ) {

			$this->ga_client = new Google_Client();
			$this->ga_client->setAccessToken( $this->get_access_token() );
		}

		// refresh token if required
		if ( $this->ga_client->isAccessTokenExpired() ) {
			$this->refresh_access_token();
		}

		return $this->ga_client;
	}


	/**
	 * Gets the Google Client API Analytics Service instance.
	 *
	 * @since 1.0.0
	 * @return \Google_Service_Analytics
	 */
	public function get_analytics() {

		if ( ! isset( $this->analytics ) ) {
			$this->analytics = new Google_Service_Analytics( $this->get_ga_client() );
		}

		return $this->analytics;
	}


	/**
	 * Refreshes the access token.
	 *
	 * @since 1.0.0
	 * @return string|null the refreshed JSON token or null on failure
	 */
	private function refresh_access_token() {

		// bail out if no refresh token is available
		if ( ! $this->get_refresh_token() ) {

			wc_google_analytics_pro()->log( 'Could not refresh access token: refresh token not available' );
			return;
		}

		$response = wp_remote_get( $this->get_access_token_refresh_url(), array( 'timeout' => MINUTE_IN_SECONDS ) );

		// bail out if the request failed
		if ( is_wp_error( $response ) ) {

			wc_google_analytics_pro()->log( sprintf( 'Could not refresh access token: %s', json_encode( $response->errors ) ) );
			return;
		}

		// bail out if the response was empty
		if ( ! $response || ! $response['body'] ) {
			wc_google_analytics_pro()->log( 'Could not refresh access token: response was empty' );
			return;
		}

		// try to decode the token
		$json_token = base64_decode( $response['body'] );
		$token      = json_decode( $json_token, true );

		// bail out if the token was invalid
		if ( ! $token ) {
			wc_google_analytics_pro()->log( 'Could not refresh access token: returned token was invalid' );
			return;
		}

		// update access token
		update_option( 'wc_google_analytics_pro_access_token', $json_token );
		$this->ga_client->setAccessToken( $json_token );

		return $json_token;
	}


	/**
	 * Generates the "deep select" field HTML.
	 *
	 * @since 1.0.0
	 * @param string $key the setting key
	 * @param array $data the setting data
	 * @return string the field HTML
	 */
	public function generate_deep_select_html( $key, $data ) {

		$field    = $this->get_field_key( $key );
		$defaults = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'options'           => array()
		);

		$data = wp_parse_args( $data, $defaults );

		$k = 0;
		$optgroup_open = false;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<select class="select <?php echo esc_attr( $data['class'] ); ?>" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?>>

						<?php foreach ( (array) $data['options'] as $option_key => $option_value ) : ?>

							<?php if ( is_array( $option_value ) ) : ?>

								<optgroup label="<?php echo esc_attr( $option_key ); ?>">

								<?php foreach ( $option_value as $option_key => $option_value ) : ?>
									<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $this->get_option( $key ) ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
								<?php endforeach; ?>

							<?php else : ?>
								<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $this->get_option( $key ) ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
							<?php endif; ?>

						<?php endforeach; ?>

					</select>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}


	/**
	 * Gets a list of Google Analytics profiles.
	 *
	 * @deprecated since 1.3.0
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_ga_profiles() {

		/* @deprecated since 1.3.0 */
		_deprecated_function( 'WC_Google_Analytics_Pro_Integration::get_ga_profiles()', '1.3.0', 'WC_Google_Analytics_Pro_Integration::get_ga_properties()' );

		return $this->get_ga_properties();
	}


	/**
	 * Returns a list of Google Analytics properties
	 *
	 * @since 1.3.0
	 * @return array
	 */
	public function get_ga_properties() {

		if ( ! wc_google_analytics_pro()->is_plugin_settings() ) {
			return array();
		}

		// check if properties transient exists
		if ( false === ( $ga_properties = get_transient( 'wc_google_analytics_pro_properties' ) ) ) {

			$ga_properties = array();
			$analytics   = $this->get_analytics();

			// try to fetch analytics accounts
			try {

				// give ourselves an unlimited timeout if possible
				@set_time_limit( 0 );

				// get the account summaries in one API call
				$account_summaries = $analytics->management_accountSummaries->listManagementAccountSummaries();

				// loop over the account summaries to get available web properties
				foreach ( $account_summaries->getItems() as $account_summary ) {

					if ( ! $account_summary instanceof Google_Service_Analytics_AccountSummary ) {
						continue;
					}

					// loop over the properties to create property options
					foreach ( $account_summary->getWebProperties() as $property ) {

						if ( ! $property instanceof Google_Service_Analytics_WebPropertySummary ) {
							continue;
						}

						$optgroup = $account_summary->getName();

						if ( ! isset( $ga_properties[ $optgroup ] ) ) {
							$ga_properties[ $optgroup ] = array();
						}

						$ga_properties[ $optgroup ][ $account_summary->getId() . '|' . $property->getId() ] = sprintf( '%s (%s)', $property->getName(), $property->getId() );

						// sort properties naturally
						natcasesort( $ga_properties[ $optgroup ] );
					}
				}
			}

			// catch service exception
			catch ( Google_Service_Exception $e ) {

				wc_google_analytics_pro()->log( $e->getMessage() );

				if ( is_admin() ) {
					wc_google_analytics_pro()->get_admin_notice_handler()->add_admin_notice(
						'<strong>' . wc_google_analytics_pro()->get_plugin_name() . ':</strong> ' .
						sprintf(
							/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
							__( 'The request to list the Google Analytics properties for the currently authenticated Google account has timed out. Please try again in a few minutes or try re-authenticating with your Google account.', 'woocommerce-google-analytics-pro' ),
							'<a href="https://console.developers.google.com/" target="_blank">',
							'</a>'
 						),
						wc_google_analytics_pro()->get_id() . '-account-' . get_option( 'wc_google_analytics_pro_account_id' ) . '-no-analytics-access',
						array( 'dismissible' => true, 'always_show_on_settings' => false, 'notice_class' => 'error' )
					);
				}

				// return a blank array so select box is valid
				return array();
			}

			// catch general google exception
			catch ( Google_Exception $e ) {

				wc_google_analytics_pro()->log( $e->getMessage() );

				if ( is_admin() ) {
					wc_google_analytics_pro()->get_admin_notice_handler()->add_admin_notice(
						'<strong>' . wc_google_analytics_pro()->get_plugin_name() . ':</strong> ' .
						__( 'The currently authenticated Google account does not have access to any Analytics accounts. Please re-authenticate with an account that has access to Google Analytics.', 'woocommerce-google-analytics-pro' ),
						wc_google_analytics_pro()->get_id() . '-account-' . get_option( 'wc_google_analytics_pro_account_id' ) . '-no-analytics-access',
						array( 'dismissible' => true, 'always_show_on_settings' => false, 'notice_class' => 'error' )
					);
				}

				// return a blank array so select box is valid
				return array();
			}

			// sort properties in the United Kingdom... just kidding, sort by keys, by comparing them naturally
			uksort( $ga_properties, 'strnatcasecmp' );

			// set 5 minute transient
			set_transient( 'wc_google_analytics_pro_properties', $ga_properties, 5 * MINUTE_IN_SECONDS );
		}

		return $ga_properties;
	}


	/**
	 * Bypasses validation for the oAuth button value.
	 *
	 * @see \WC_Settings_API::get_field_value()
	 *
	 * @since 1.1.6
	 * @return string the button default value
	 */
	protected function validate_oauth_button_field() {

		$form_fields = $this->get_form_fields();

		return ! empty( $form_fields[ 'oauth_button' ]['default'] ) ? $form_fields[ 'oauth_button' ]['default'] : '';
	}


	/**
	 * Filters the admin options before saving.
	 *
	 * @since 1.0.0
	 * @param array $sanitized_fields
	 * @return array
	 */
	public function filter_admin_options( $sanitized_fields ) {

		// prevent button labels from being saved
		unset( $sanitized_fields['oauth_button'] );

		// unset web property if manual tracking is being used
		if ( isset( $sanitized_fields['use_manual_tracking_id'] ) && 'yes' === $sanitized_fields['use_manual_tracking_id'] ) {
			$sanitized_fields['property'] = '';
		}

		// get tracking ID from web property, if using oAuth, and save it to the tracking ID option
		elseif ( ! empty( $sanitized_fields['property'] ) ) {

			$parts = explode( '|', $sanitized_fields['property'] );
			$sanitized_fields['tracking_id'] = $parts[1];
		}

		// manual tracking ID not configured, and no property selected. Remove tracking ID.
		else {
			$sanitized_fields['tracking_id'] = '';
		}

		return $sanitized_fields;
	}


	/**
	 * Returns the currently selected Google Analytics Account ID.
	 *
	 * @since 1.0.0
	 * @return int|null
	 */
	public function get_ga_account_id() {

		return $this->get_ga_property_part( 0 );
	}


	/**
	 * Returns the currently selected Google Analytics property ID.
	 *
	 * @since 1.0.0
	 * @return int|null
	 */
	public function get_ga_property_id() {

		return $this->get_ga_property_part( 1 );
	}


	/**
	 * Returns the given part from the property option.
	 *
	 * In 1.3.0 renamed from get_ga_property_part() to get_ga_property_part()
	 *
	 * @since 1.0.0
	 * @param int $key the array key
	 * @return mixed|null
	 */
	private function get_ga_property_part( $key ) {

		$property = $this->get_option( 'property' );

		if ( ! $property ) {
			return;
		}

		$pieces = explode( '|', $property );

		if ( ! isset( $pieces[ $key ] ) ) {
			return;
		}

		return $pieces[ $key ];
	}


}
