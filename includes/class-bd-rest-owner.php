<?php
/**
 * REST API for signed-in business owners: /wp-json/business-doctor/v1/owner/...
 * Unlike BD_Rest (the anonymous free-assessment API), every route here requires a signed-in
 * WordPress user holding the business_owner role (or an administrator).
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Rest_Owner {

	const NS = 'business-doctor/v1';

	/**
	 * Registers routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * True if the current request comes from a signed-in business owner.
	 *
	 * @return bool
	 */
	public static function permission() {
		return is_user_logged_in() && BD_Accounts::is_owner( get_current_user_id() );
	}

	/**
	 * Route table.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/owner/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/owner/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/owner/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'me' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/owner/organisations/(?P<platform>[A-Z0-9_]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_organisation' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'save_organisation' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/owner/diagnostics/(?P<platform>[A-Z0-9_]+)/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/owner/diagnostics/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_diagnostic' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/owner/diagnostics/(?P<id>\d+)/answers',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'save_answer' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/owner/diagnostics/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/owner/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_diagnostics' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * A diagnostic owned by the current user, or an error.
	 *
	 * @param int $id Diagnostic id.
	 * @return array|WP_Error
	 */
	private static function owned_diagnostic( $id ) {
		$d = BD_Diagnostics::get( (int) $id );
		if ( ! $d || (int) $d['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'bd_not_found', __( 'Assessment not found.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		return $d;
	}

	/**
	 * The published full framework for a platform, validated against enabled platforms.
	 *
	 * @param string $platform Platform code.
	 * @return array|WP_Error
	 */
	private static function framework( $platform ) {
		$platform = bd_sanitize_code( $platform );
		if ( ! array_key_exists( $platform, BD_Frameworks::platforms() ) ) {
			return new WP_Error( 'bd_unavailable', __( 'That platform is not recognised.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		$fw = BD_Frameworks::published( $platform, 'full' );
		if ( ! $fw ) {
			return new WP_Error( 'bd_unavailable', __( 'The full assessment for this platform is not available right now.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		return $fw;
	}

	/**
	 * POST /owner/register  { fullName, email, password }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'owner_register', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'bd_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'business-doctor' ), array( 'status' => 429 ) );
		}
		$user = BD_Accounts::register(
			(string) $req->get_param( 'fullName' ),
			(string) $req->get_param( 'email' ),
			(string) $req->get_param( 'password' )
		);
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		return rest_ensure_response( self::me() );
	}

	/**
	 * POST /owner/login  { email, password }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( WP_REST_Request $req ) {
		if ( ! BD_Rate_Limit::hit( 'owner_login', 20, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'bd_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'business-doctor' ), array( 'status' => 429 ) );
		}
		$user = BD_Accounts::login( (string) $req->get_param( 'email' ), (string) $req->get_param( 'password' ) );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		return rest_ensure_response( self::me() );
	}

	/**
	 * GET /owner/me
	 *
	 * @return WP_REST_Response
	 */
	public static function me() {
		if ( ! self::permission() ) {
			return rest_ensure_response( array( 'loggedIn' => false ) );
		}
		$user = wp_get_current_user();
		return rest_ensure_response(
			array(
				'loggedIn'      => true,
				'fullName'      => $user->display_name,
				'email'         => $user->user_email,
				'organisations' => BD_Organisations::for_user( $user->ID ),
			)
		);
	}

	/**
	 * GET /owner/organisations/{platform}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function get_organisation( WP_REST_Request $req ) {
		$org = BD_Organisations::get_for( get_current_user_id(), bd_sanitize_code( (string) $req['platform'] ) );
		return rest_ensure_response( $org ?: array() );
	}

	/**
	 * PUT /owner/organisations/{platform}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_organisation( WP_REST_Request $req ) {
		$platform = bd_sanitize_code( (string) $req['platform'] );
		$org      = BD_Organisations::save( get_current_user_id(), $platform, (array) $req->get_json_params() );
		return is_wp_error( $org ) ? $org : rest_ensure_response( $org );
	}

	/**
	 * POST /owner/diagnostics/{platform}/start
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $req ) {
		$platform = bd_sanitize_code( (string) $req['platform'] );
		$fw       = self::framework( $platform );
		if ( is_wp_error( $fw ) ) {
			return $fw;
		}
		$org = BD_Organisations::get_for( get_current_user_id(), $platform );
		if ( ! $org ) {
			return new WP_Error( 'bd_no_org', __( 'Please complete your organisation profile before starting the assessment.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$d = BD_Diagnostics::start_or_resume( $org, $fw );
		return rest_ensure_response(
			array_merge(
				BD_Diagnostics::to_public( $d ),
				array(
					'questions'  => $fw['questions'],
					'dimensions' => $fw['dimensions'],
					'answers'    => BD_Diagnostics::answers( $d['id'] ),
				)
			)
		);
	}

	/**
	 * GET /owner/diagnostics/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_diagnostic( WP_REST_Request $req ) {
		$d = self::owned_diagnostic( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$out = BD_Diagnostics::to_public( $d );
		if ( 'draft' === $d['status'] ) {
			$fw             = BD_Frameworks::get( $d['framework_id'] );
			$out['questions']  = $fw ? $fw['questions'] : array();
			$out['dimensions'] = $fw ? $fw['dimensions'] : array();
			$out['answers']    = BD_Diagnostics::answers( $d['id'] );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * PUT /owner/diagnostics/{id}/answers  { questionCode, value, notApplicable, evidenceClass, evidenceNote, attachmentId }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_answer( WP_REST_Request $req ) {
		$d = self::owned_diagnostic( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$fw = BD_Frameworks::get( $d['framework_id'] );
		if ( ! $fw ) {
			return new WP_Error( 'bd_not_found', __( 'The question bank for this assessment is missing.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		$code     = bd_sanitize_code( (string) $req->get_param( 'questionCode' ) );
		$question = null;
		foreach ( $fw['questions'] as $q ) {
			if ( $q['code'] === $code ) {
				$question = $q;
				break;
			}
		}
		if ( ! $question ) {
			return new WP_Error( 'bd_invalid', __( 'Unknown question.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$result = BD_Diagnostics::save_answer( $d, $question, (array) $req->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /owner/diagnostics/{id}/submit
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( WP_REST_Request $req ) {
		$d = self::owned_diagnostic( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$fw = BD_Frameworks::get( $d['framework_id'] );
		if ( ! $fw ) {
			return new WP_Error( 'bd_not_found', __( 'The question bank for this assessment is missing.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		$result = BD_Diagnostics::submit( $d, $fw );
		return is_wp_error( $result ) ? $result : rest_ensure_response( BD_Diagnostics::to_public( $result ) );
	}

	/**
	 * GET /owner/diagnostics
	 *
	 * @return WP_REST_Response
	 */
	public static function list_diagnostics() {
		$items = array_map( array( 'BD_Diagnostics', 'to_public' ), BD_Diagnostics::for_user( get_current_user_id() ) );
		return rest_ensure_response( $items );
	}
}
