<?php
/**
 * Public REST API used by the assessment form: /wp-json/business-doctor/v1/...
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Rest {

	const NS = 'business-doctor/v1';

	/**
	 * Registers routes.
	 */
	public static function init() {
		BD_Mailer::init();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route table.
	 */
	public static function routes() {
		$public = '__return_true';
		register_rest_route(
			self::NS,
			'/platforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'platforms' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/eligibility',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'eligibility' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/questions/(?P<platform>[A-Z0-9_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'questions' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/results/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'result' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/results/(?P<token>[a-f0-9]{32})/email',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'email' ),
				'permission_callback' => $public,
			)
		);
	}

	/**
	 * A 429 error.
	 *
	 * @return WP_Error
	 */
	private static function too_many() {
		return new WP_Error( 'bd_rate_limited', __( 'Too many attempts from this connection. Please wait a few minutes and try again.', 'business-doctor' ), array( 'status' => 429 ) );
	}

	/**
	 * A 422 error with per-field messages.
	 *
	 * @param array $fields Field => message.
	 * @return WP_Error
	 */
	private static function invalid( array $fields ) {
		return new WP_Error(
			'bd_invalid',
			__( 'Please check the highlighted fields.', 'business-doctor' ),
			array(
				'status' => 422,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Validates a platform code against the enabled, published platforms.
	 *
	 * @param string $platform Code.
	 * @return array|WP_Error Published framework.
	 */
	private static function platform( $platform ) {
		$platform = bd_sanitize_code( $platform );
		$enabled  = (array) BD_Settings::get( 'enabled_platforms' );
		$fw       = in_array( $platform, $enabled, true ) ? BD_Frameworks::published( $platform ) : null;
		if ( ! $fw ) {
			return new WP_Error( 'bd_unavailable', __( 'This assessment is not available right now.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		return $fw;
	}

	/**
	 * GET /platforms
	 *
	 * @return WP_REST_Response
	 */
	public static function platforms() {
		$out = array();
		foreach ( BD_Frameworks::platforms() as $code => $p ) {
			$fw = self::platform( $code );
			if ( ! is_wp_error( $fw ) ) {
				$out[] = array(
					'code'      => $code,
					'label'     => $p['label'],
					'name'      => $fw['name'],
					'questions' => count( $fw['questions'] ),
				);
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /eligibility  { email, platform }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function eligibility( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'eligibility', 30 ) ) {
			return self::too_many();
		}
		$fw = self::platform( (string) $req->get_param( 'platform' ) );
		if ( is_wp_error( $fw ) ) {
			return $fw;
		}
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return self::invalid( array( 'email' => __( 'Enter a valid email address', 'business-doctor' ) ) );
		}
		return rest_ensure_response( BD_Results::is_eligible( $email, $fw['platform'] ) ? array( 'eligible' => true ) : BD_Results::already_used() );
	}

	/**
	 * GET /questions/{platform}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function questions( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'questions', 60 ) ) {
			return self::too_many();
		}
		$fw = self::platform( (string) $req['platform'] );
		if ( is_wp_error( $fw ) ) {
			return $fw;
		}
		return rest_ensure_response(
			array(
				'platform'     => $fw['platform'],
				'platformName' => BD_Frameworks::short_name( $fw['platform'] ),
				'name'         => $fw['name'],
				'version'      => $fw['version'],
				'questions'    => BD_Frameworks::public_questions( $fw ),
			)
		);
	}

	/**
	 * POST /complete  { platform, fullName, email, privacy, answers: {code: letter}, website }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'complete', (int) BD_Settings::get( 'rate_limit' ) ) ) {
			return self::too_many();
		}
		// Honeypot: real visitors never see or fill this field.
		if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
			return self::invalid( array( 'form' => __( 'Your submission could not be accepted.', 'business-doctor' ) ) );
		}
		$fw = self::platform( (string) $req->get_param( 'platform' ) );
		if ( is_wp_error( $fw ) ) {
			return $fw;
		}
		$name   = sanitize_text_field( (string) $req->get_param( 'fullName' ) );
		$email  = sanitize_email( (string) $req->get_param( 'email' ) );
		$fields = array();
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 120 ) {
			$fields['fullName'] = __( 'Enter your name', 'business-doctor' );
		}
		if ( ! is_email( $email ) || strlen( $email ) > 150 ) {
			$fields['email'] = __( 'Enter a valid email address', 'business-doctor' );
		}
		if ( true !== rest_sanitize_boolean( $req->get_param( 'privacy' ) ) ) {
			$fields['privacy'] = __( 'Please acknowledge the privacy notice to continue', 'business-doctor' );
		}
		if ( $fields ) {
			return self::invalid( $fields );
		}
		$raw     = $req->get_param( 'answers' );
		$answers = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $code => $letter ) {
				$answers[ bd_sanitize_code( $code ) ] = strtoupper( substr( sanitize_text_field( (string) $letter ), 0, 1 ) );
			}
		}
		$result = BD_Results::complete( $fw['platform'], $name, $email, $answers );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /results/{token}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function result( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'result', 60 ) ) {
			return self::too_many();
		}
		$r = BD_Results::get_by_token( (string) $req['token'] );
		if ( ! $r ) {
			return new WP_Error( 'bd_not_found', __( 'Assessment result not found', 'business-doctor' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( BD_Results::to_public( $r ) );
	}

	/**
	 * POST /results/{token}/email  sends the result to the address it was taken with, never another.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function email( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'email', 5, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$r = BD_Results::get_by_token( (string) $req['token'] );
		if ( ! $r ) {
			return new WP_Error( 'bd_not_found', __( 'Assessment result not found', 'business-doctor' ), array( 'status' => 404 ) );
		}
		$key = 'bd_mail_' . $r['id'];
		if ( get_transient( $key ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( ! BD_Mailer::send_result( $r ) ) {
			return new WP_Error( 'bd_mail', __( 'We could not send the email just now. Please print or save your result instead.', 'business-doctor' ), array( 'status' => 502 ) );
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
