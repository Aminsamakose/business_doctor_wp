<?php
/**
 * Form handlers for the diagnosis/prescription workflow shown on the Full Assessments detail screen
 * (BD_Diagnostics_Admin::detail()): requesting an AI draft, writing one manually, submitting for
 * review, and the four-eyes approve/reject/return decisions. Kept as its own class, alongside the
 * read-only assessment detail screen it attaches panels to, the same split as BD_Payments_Admin sits
 * beside BD_Diagnostics_Admin for the payments panel.
 *
 * Manual diagnosis/prescription authoring is a JSON textarea for now rather than a dynamic add/remove
 * row form: Phase 4's scope is the review workflow and the four-eyes gate, not a rich editor, and the
 * JSON shape is documented inline on each form. A friendlier form is a natural fast follow.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Clinical_Admin {

	/**
	 * Hooks every admin-post handler this screen needs.
	 */
	public static function init() {
		add_action( 'admin_post_bd_request_ai_diagnosis', array( __CLASS__, 'handle_request_ai_diagnosis' ) );
		add_action( 'admin_post_bd_create_diagnosis', array( __CLASS__, 'handle_create_diagnosis' ) );
		add_action( 'admin_post_bd_review_diagnosis', array( __CLASS__, 'handle_review_diagnosis' ) );
		add_action( 'admin_post_bd_request_ai_prescription', array( __CLASS__, 'handle_request_ai_prescription' ) );
		add_action( 'admin_post_bd_create_prescription', array( __CLASS__, 'handle_create_prescription' ) );
		add_action( 'admin_post_bd_submit_prescription', array( __CLASS__, 'handle_submit_prescription' ) );
		add_action( 'admin_post_bd_review_prescription', array( __CLASS__, 'handle_review_prescription' ) );
	}

	/**
	 * Redirect target: back to the assessment's own detail screen, with a result flag.
	 *
	 * @param int    $diagnostic_id Diagnostic id.
	 * @param string $msg           Success flag, or empty.
	 * @param string $err           Error message, or empty.
	 */
	private static function back( $diagnostic_id, $msg = '', $err = '' ) {
		$url = admin_url( 'admin.php?page=bd-diagnostics&action=view&id=' . (int) $diagnostic_id );
		if ( $msg ) {
			$url = add_query_arg( 'bd_msg', $msg, $url );
		}
		if ( $err ) {
			$url = add_query_arg( 'bd_err', rawurlencode( $err ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Loads the diagnostic and its framework for a handler, or dies if the caller lacks permission or
	 * the id is bad.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return array{0: array, 1: array} [diagnostic, framework].
	 */
	private static function require_diagnostic( $diagnostic_id ) {
		if ( ! BD_Diagnostics_Admin::can() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$diagnostic = BD_Diagnostics::get( $diagnostic_id );
		if ( ! $diagnostic ) {
			wp_die( esc_html__( 'Assessment not found.', 'business-doctor' ) );
		}
		$fw = BD_Frameworks::get( $diagnostic['framework_id'] );
		return array( $diagnostic, $fw ?: array( 'dimensions' => array(), 'questions' => array() ) );
	}

	/**
	 * Requests an AI-drafted diagnosis for a submitted, scored assessment.
	 */
	public static function handle_request_ai_diagnosis() {
		$diagnostic_id = isset( $_POST['diagnostic_id'] ) ? absint( $_POST['diagnostic_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		list( $diagnostic, $fw ) = self::require_diagnostic( $diagnostic_id );
		$result = BD_Case_Diagnoses::request_ai_draft( $diagnostic, $fw, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, 'diagnosis_drafted' );
	}

	/**
	 * Saves a manually written diagnosis draft.
	 */
	public static function handle_create_diagnosis() {
		$diagnostic_id = isset( $_POST['diagnostic_id'] ) ? absint( $_POST['diagnostic_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		list( $diagnostic, $fw ) = self::require_diagnostic( $diagnostic_id );
		$summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$priority = isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( $_POST['priority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$root_causes = json_decode( (string) wp_unslash( $_POST['root_causes_json'] ?? '' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$risks       = json_decode( (string) wp_unslash( $_POST['risks_json'] ?? '' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$confidence  = isset( $_POST['model_confidence'] ) && '' !== trim( (string) $_POST['model_confidence'] ) ? (float) $_POST['model_confidence'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $root_causes ) || ! is_array( $risks ) ) {
			self::back( $diagnostic_id, '', __( 'Root causes and risks must each be valid JSON arrays.', 'business-doctor' ) );
		}
		$data = array(
			'summary'      => $summary,
			'root_causes'  => $root_causes,
			'priority'     => $priority,
			'risks'        => $risks,
			'model_confidence' => $confidence,
		);
		$result = BD_Case_Diagnoses::create( $diagnostic, $data, get_current_user_id(), false );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, 'diagnosis_drafted' );
	}

	/**
	 * Approves or rejects a draft diagnosis. Four-eyes is enforced inside BD_Case_Diagnoses::review().
	 */
	public static function handle_review_diagnosis() {
		$diagnosis_id  = isset( $_POST['diagnosis_id'] ) ? absint( $_POST['diagnosis_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diagnosis     = BD_Case_Diagnoses::get( $diagnosis_id );
		$diagnostic_id = $diagnosis ? $diagnosis['diagnostic_id'] : 0;
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		if ( ! BD_Diagnostics_Admin::can() || ! $diagnosis ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$approve = isset( $_POST['decision'] ) && 'approve' === $_POST['decision']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result  = BD_Case_Diagnoses::review( $diagnosis, get_current_user_id(), $approve, $note );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, $approve ? 'diagnosis_reviewed' : 'diagnosis_rejected' );
	}

	/**
	 * Requests an AI-drafted prescription from a reviewed diagnosis.
	 */
	public static function handle_request_ai_prescription() {
		$diagnosis_id = isset( $_POST['diagnosis_id'] ) ? absint( $_POST['diagnosis_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diagnosis    = BD_Case_Diagnoses::get( $diagnosis_id );
		$diagnostic_id = $diagnosis ? $diagnosis['diagnostic_id'] : 0;
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		if ( ! BD_Diagnostics_Admin::can() || ! $diagnosis ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$fw     = BD_Frameworks::published( $diagnosis['platform'], 'full' );
		$result = BD_Prescriptions::request_ai_draft( $diagnosis, $fw ?: array( 'dimensions' => array() ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, 'prescription_drafted' );
	}

	/**
	 * Saves a manually written prescription draft.
	 */
	public static function handle_create_prescription() {
		$diagnosis_id = isset( $_POST['diagnosis_id'] ) ? absint( $_POST['diagnosis_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$diagnosis    = BD_Case_Diagnoses::get( $diagnosis_id );
		$diagnostic_id = $diagnosis ? $diagnosis['diagnostic_id'] : 0;
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		if ( ! BD_Diagnostics_Admin::can() || ! $diagnosis ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$items = json_decode( (string) wp_unslash( $_POST['items_json'] ?? '' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $items ) ) {
			self::back( $diagnostic_id, '', __( 'Items must be valid JSON.', 'business-doctor' ) );
		}
		$fw     = BD_Frameworks::published( $diagnosis['platform'], 'full' );
		$result = BD_Prescriptions::create( $diagnosis, $fw ?: array( 'dimensions' => array() ), array( 'items' => $items ), get_current_user_id(), false );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, 'prescription_drafted' );
	}

	/**
	 * Moves a draft prescription into review.
	 */
	public static function handle_submit_prescription() {
		$prescription_id = isset( $_POST['prescription_id'] ) ? absint( $_POST['prescription_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prescription    = BD_Prescriptions::get( $prescription_id );
		$diagnosis       = $prescription ? BD_Case_Diagnoses::get( $prescription['diagnosis_id'] ) : null;
		$diagnostic_id   = $diagnosis ? $diagnosis['diagnostic_id'] : 0;
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		if ( ! BD_Diagnostics_Admin::can() || ! $prescription ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$result = BD_Prescriptions::submit_for_review( $prescription );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, 'prescription_submitted' );
	}

	/**
	 * Approves or returns a prescription in review. Four-eyes is enforced inside
	 * BD_Prescriptions::review(). Approval materialises the action plan and KPIs.
	 */
	public static function handle_review_prescription() {
		$prescription_id = isset( $_POST['prescription_id'] ) ? absint( $_POST['prescription_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prescription    = BD_Prescriptions::get( $prescription_id );
		$diagnosis       = $prescription ? BD_Case_Diagnoses::get( $prescription['diagnosis_id'] ) : null;
		$diagnostic_id   = $diagnosis ? $diagnosis['diagnostic_id'] : 0;
		check_admin_referer( 'bd_clinical_' . $diagnostic_id );
		if ( ! BD_Diagnostics_Admin::can() || ! $prescription || ! $diagnosis ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$org = BD_Organisations::get( $prescription['org_id'] );
		if ( ! $org ) {
			wp_die( esc_html__( 'Organisation not found.', 'business-doctor' ) );
		}
		$approve = isset( $_POST['decision'] ) && 'approve' === $_POST['decision']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result  = BD_Prescriptions::review( $prescription, $org, get_current_user_id(), $approve, $note );
		if ( is_wp_error( $result ) ) {
			self::back( $diagnostic_id, '', $result->get_error_message() );
		}
		self::back( $diagnostic_id, $approve ? 'prescription_approved' : 'prescription_returned' );
	}
}
