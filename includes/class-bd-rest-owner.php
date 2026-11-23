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
			'/owner/diagnostics/(?P<id>\d+)/evidence',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_evidence' ),
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
		$code     = sanitize_text_field( (string) $req->get_param( 'questionCode' ) );
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
		$body = (array) $req->get_json_params();
		if ( ! empty( $body['attachmentId'] ) ) {
			// An evidence attachment must have been uploaded for this exact diagnostic: never let one
			// assessment's evidence be claimed by referencing another owner's attachment id.
			if ( (int) get_post_meta( (int) $body['attachmentId'], '_bd_diagnostic_id', true ) !== (int) $d['id'] ) {
				return new WP_Error( 'bd_invalid', __( 'That attachment does not belong to this assessment.', 'business-doctor' ), array( 'status' => 422 ) );
			}
		}
		$result = BD_Diagnostics::save_answer( $d, $question, $body );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Mime types accepted as evidence attachments.
	 *
	 * @return string[]
	 */
	private static function allowed_evidence_types() {
		return array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	}

	/**
	 * POST /owner/diagnostics/{id}/evidence  multipart upload, field name "file". Returns an
	 * attachment id to pass as attachmentId when saving the answer it supports.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_evidence( WP_REST_Request $req ) {
		$d = self::owned_diagnostic( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( 'draft' !== $d['status'] ) {
			return new WP_Error( 'bd_submitted', __( 'This assessment has already been submitted and can no longer be changed.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		if ( ! BD_Rate_Limit::hit( 'owner_evidence', 60, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'bd_rate_limited', __( 'Too many uploads. Please wait a few minutes and try again.', 'business-doctor' ), array( 'status' => 429 ) );
		}
		$files = $req->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'bd_invalid', __( 'Choose a file to upload.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$file = $files['file'];
		if ( ! empty( $file['size'] ) && $file['size'] > 10 * MB_IN_BYTES ) {
			return new WP_Error( 'bd_invalid', __( 'Files must be 10MB or smaller.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$type = wp_check_filetype( $file['name'] );
		if ( empty( $type['type'] ) || ! in_array( $type['type'], self::allowed_evidence_types(), true ) ) {
			return new WP_Error( 'bd_invalid', __( 'Please upload a PDF, Word document, or image (JPG, PNG or WEBP).', 'business-doctor' ), array( 'status' => 422 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Evidence files are owner-supplied and private to their own assessment; never attached to
		// a public post, so they do not appear in the media library's post-attachment listings.
		// test_form is off because this upload comes through the REST API, not a classic wp-admin
		// post form with an expected "action" field; wp_handle_upload() would otherwise reject it
		// as an invalid form submission.
		$attachment_id = media_handle_upload( 'file', 0, array(), array( 'test_form' => false ) );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'bd_upload', __( 'The file could not be uploaded. Please try again.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		update_post_meta( $attachment_id, '_bd_diagnostic_id', $d['id'] );
		update_post_meta( $attachment_id, '_bd_uploaded_by', get_current_user_id() );
		return rest_ensure_response(
			array(
				'attachmentId' => (int) $attachment_id,
				'url'          => wp_get_attachment_url( $attachment_id ),
				'fileName'     => get_the_title( $attachment_id ),
			)
		);
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
