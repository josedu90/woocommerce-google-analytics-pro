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
 * The email tracking class.
 *
 * @since 1.0.0
 */
class WC_Google_Analytics_Pro_Email_Tracking {


	/** @var array the \WC_Email instances that should be tracked **/
	private $emails;


	/**
	 * Constructs the class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		add_action( 'woocommerce_after_template_part', array( $this, 'track_opens' ), 10, 4 );
	}


	/**
	 * Gets the emails that should be tracked.
	 *
	 * @since 1.0.0
	 * @return array associative array of \WC_Emails
	 */
	public function get_emails() {

		if ( ! isset( $this->emails ) ) {

			$wc_emails    = WC_Emails::instance();
			$all_emails   = $wc_emails->get_emails();
			$track_emails = array();

			// only track customer emails
			if ( ! empty( $all_emails ) ) {
				foreach ( $all_emails as $key => $email ) {

					$pos = strpos( $email->id, 'customer_' );

					if ( $pos !== false && $pos === 0 ) {
						$track_emails[ $key ] = $email;
					}
				}
			}

			/**
			 * Filter which emails should be tracked
			 *
			 * By default, only customer emails are tracked.
			 *
			 * @since 1.0.0
			 * @param array $track_emails Associative array of emails to be tracked
			 */
			$this->emails = apply_filters( 'wc_google_analytics_pro_track_emails', $track_emails );
		}

		return $this->emails;
	}


	/**
	 * Gets an email based on an HTML template path.
	 *
	 * @since 1.0.0
	 * @param string $template_path the template path
	 * @return \WC_Email|null
	 */
	private function get_email_by_template_html_path( $template_path ) {

		$found_email = null;

		foreach ( $this->get_emails() as $email ) {

			if ( $template_path === $email->template_html ) {

				$found_email = $email;
				break;
			}
		}

		return $found_email;
	}


	/** Tracking methods ************************************************/


	/**
	 * Adds the tracking image to the email HTML content.
	 *
	 * @internal
	 *
	 * @since 1.0.0
	 * @param string $template_name the template name
	 * @param string $template_path the template path
	 * @param bool $located whether the template was located
	 * @param array $args the template args, defaults to an empty array
	 */
	public function track_opens( $template_name, $template_path, $located, $args = array() ) {

		$tracking_id = wc_google_analytics_pro()->get_integration()->get_tracking_id();

		// skip if no tracking ID
		if ( ! $tracking_id ) {
			return;
		}

		// skip if not an email template or is plain email template
		if ( strpos( $template_name, 'emails/' ) === false || strpos( $template_name, 'emails/plain/' ) !== false ) {
			return;
		}

		$email = $this->get_email_by_template_html_path( $template_name );

		// skip if we're not tracking this email
		if ( ! $email ) {
			return;
		}

		$cid = $uid = null;

		if ( isset( $args['order'] ) ) {

			// try to get client & user ID from order
			$cid = get_post_meta( SV_WC_Order_Compatibility::get_prop( $args['order'], 'id' ), '_wc_google_analytics_pro_identity', true );
			$uid = SV_WC_Order_Compatibility::get_prop( $args['order'], 'customer_id' );

		} elseif ( isset( $args['user_login'] ) ) {

			// try to get client & user ID from user data
			$user = get_user_by( 'login', $args['user_login'] );
			$uid  = $user->ID;
			$cid  = get_user_meta( $user->ID, '_wc_google_analytics_pro_identity', true );
		}

		// fall back to generating UUID
		// get the integration class instance
		$integration = wc_google_analytics_pro()->get_integration();

		// skip tracking email open if not enabled for the user's role
		if ( null !== $uid && ! $integration->is_tracking_enabled_for_user_role( $uid ) ) {
			return;
		}

		$track_user_id = 'yes' === $integration->get_option( 'track_user_id' );

		// by default, a UUID will only be generated if we have no CID, we have a user id and user-id tracking is enabled
		// note: when changing this logic here, adjust the logic in WC_Google_Analytics_Pro_Integration::get_cid() as well
		$generate_uuid = ! $cid && $uid && $track_user_id;

		/** This filter is documented in includes/class-wc-google-analytics-pro-integration.php */
		$generate_uuid = apply_filters( 'wc_google_analytics_pro_generate_client_id', $generate_uuid );

		if ( $generate_uuid ) {
			$cid = $integration->generate_uuid();
		}

		// bail out if tracking user ID is enabled and we don't have a proper user ID nor client ID (registered users/guests)
		// or tracking user ID is disabled and we don't have proper CID
		if ( ! $track_user_id && ! $cid || $track_user_id && ! $cid && ! $uid ) {
			return;
		}

		$url   = 'https://www.google-analytics.com/collect?';
		$query = urldecode( http_build_query( array(
			'v'   => 1,
			'tid' => $tracking_id,                                              // Tracking ID. Required
			'cid' => $cid,                                                      // Client (anonymous) ID. Required
			'uid' => $uid,                                                      // User ID
			't'   => 'event',                                                   // Tracking an event
			'ec'  => 'Emails',                                                  // Event Category
			'ea'  => 'open',                                                    // Event Action
			'el'  => urlencode( $email->title ),                                // Event Label - email title
			'dp'  => urlencode( '/emails/' . sanitize_title( $email->title ) ), // Document Path. Unique for each email
			'dt'  => urlencode( $email->title ),                                // Document Title - email title
		), '', '&' ) );

		printf( '<img src="%s" alt="" />', $url . $query );
	}


}
