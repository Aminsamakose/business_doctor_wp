<?php
/**
 * The Paystack webhook endpoint: /wp-json/business-doctor/v1/webhooks/paystack. Public (Paystack is
 * not a signed-in WordPress user), authenticated instead by the X-Paystack-Signature header -- see
 * BD_Paystack::verify_signature(). This is the authoritative settlement path; BD_Rest_Owner's
 * get_payment() also re-checks with Paystack directly so the UI can show a result without waiting
 * for the webhook, but a payment is not truly trusted until one of these two paths confirms it.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Rest_Webhooks {

	const NS = 'business-doctor/v1';

	/**
	 * Registers the route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route table.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/webhooks/paystack',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'paystack' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies the signature, parses the event, and hands it to BD_Payments. Always returns a 200
	 * once the signature and JSON are valid (even for an event we ignore), per Paystack's own
	 * recommendation, so it does not keep retrying events we have no use for.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function paystack( WP_REST_Request $req ) {
		if ( ! BD_Paystack::is_configured() ) {
			return new WP_Error( 'bd_not_configured', __( 'Payments are not configured.', 'business-doctor' ), array( 'status' => 503 ) );
		}
		$raw_body  = $req->get_body();
		$signature = $req->get_header( 'x-paystack-signature' );
		if ( ! BD_Paystack::verify_signature( $raw_body, $signature ) ) {
			return new WP_Error( 'bd_bad_signature', __( 'Signature check failed.', 'business-doctor' ), array( 'status' => 401 ) );
		}
		$event = json_decode( (string) $raw_body, true );
		if ( ! is_array( $event ) ) {
			return new WP_Error( 'bd_bad_json', __( 'Not JSON.', 'business-doctor' ), array( 'status' => 400 ) );
		}
		$result = BD_Payments::handle_webhook_event( $event );
		return rest_ensure_response( $result );
	}
}
