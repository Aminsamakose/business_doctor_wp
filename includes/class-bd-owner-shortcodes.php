<?php
/**
 * Shortcode for business owners: [business_doctor_owner platform="SME360"]
 * Renders a single-page app: sign in / register, organisation profile, the full assessment, and
 * the scored result. Separate from [business_doctor_assessment], which stays anonymous.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Owner_Shortcodes {

	/**
	 * Registers the shortcode and assets.
	 */
	public static function init() {
		add_shortcode( 'business_doctor_owner', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registers (does not enqueue) the front-end assets.
	 */
	public static function register_assets() {
		if ( wp_script_is( 'business-doctor-owner', 'registered' ) ) {
			return;
		}
		wp_register_style( 'business-doctor', BD_URL . 'assets/css/business-doctor.css', array(), BD_VERSION );
		wp_register_script( 'business-doctor-owner', BD_URL . 'assets/js/owner.js', array(), BD_VERSION, true );
	}

	/**
	 * Data the front end needs.
	 *
	 * @return array
	 */
	private static function config() {
		$platforms = array();
		foreach ( BD_Frameworks::platforms() as $code => $p ) {
			if ( BD_Frameworks::published( $code, 'full' ) ) {
				$platforms[] = array(
					'code'  => $code,
					'label' => $p['label'],
					'short' => $p['short'],
				);
			}
		}
		return array(
			'api'            => esc_url_raw( rest_url( BD_Rest_Owner::NS ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'loggedIn'       => is_user_logged_in() && BD_Accounts::is_owner( get_current_user_id() ),
			'lostPasswordUrl' => wp_lostpassword_url(),
			'platforms'      => $platforms,
			'paymentsEnabled' => (bool) BD_Settings::get( 'payments_enabled' ),
			'i18n'           => array(
				'signIn'          => __( 'Sign in', 'business-doctor' ),
				'register'        => __( 'Create an account', 'business-doctor' ),
				'fullName'        => __( 'Full name', 'business-doctor' ),
				'email'           => __( 'Email', 'business-doctor' ),
				'password'        => __( 'Password', 'business-doctor' ),
				'continue'        => __( 'Continue', 'business-doctor' ),
				'haveAccount'     => __( 'Already have an account? Sign in', 'business-doctor' ),
				'needAccount'     => __( "Don't have an account? Register", 'business-doctor' ),
				'forgotPassword'  => __( 'Forgot your password?', 'business-doctor' ),
				'choosePlatform'  => __( 'Choose an assessment', 'business-doctor' ),
				'orgProfile'      => __( 'Organisation profile', 'business-doctor' ),
				'orgName'         => __( 'Organisation name', 'business-doctor' ),
				'sector'          => __( 'Sector', 'business-doctor' ),
				'region'          => __( 'Region', 'business-doctor' ),
				'district'        => __( 'District', 'business-doctor' ),
				'sizeBand'        => __( 'Size', 'business-doctor' ),
				'regStatus'       => __( 'Registration status', 'business-doctor' ),
				'phone'           => __( 'Phone', 'business-doctor' ),
				'website'         => __( 'Website', 'business-doctor' ),
				'saveContinue'    => __( 'Save and continue', 'business-doctor' ),
				'saving'          => __( 'Saving...', 'business-doctor' ),
				'startAssessment' => __( 'Start the full assessment', 'business-doctor' ),
				'resume'          => __( 'Resume your assessment', 'business-doctor' ),
				'evidence'        => __( 'How is this backed by evidence?', 'business-doctor' ),
				'notApplicable'   => __( 'Not applicable to us', 'business-doctor' ),
				'evidenceNote'    => __( 'Notes (optional)', 'business-doctor' ),
				'evidenceFile'    => __( 'Attach a supporting file (optional)', 'business-doctor' ),
				'uploading'       => __( 'Uploading...', 'business-doctor' ),
				'fileAttached'    => __( 'File attached', 'business-doctor' ),
				'saveAnswer'      => __( 'Save', 'business-doctor' ),
				'submit'          => __( 'Submit assessment', 'business-doctor' ),
				'submitting'      => __( 'Submitting...', 'business-doctor' ),
				'overall'         => __( 'Overall score', 'business-doctor' ),
				'maturity'        => __( 'Maturity band', 'business-doctor' ),
				'confidence'      => __( 'Confidence', 'business-doctor' ),
				'dimensions'      => __( 'Dimension scores', 'business-doctor' ),
				'generic'         => __( 'Something went wrong. Please try again.', 'business-doctor' ),
				'network'         => __( 'Could not reach the server. Please check your connection.', 'business-doctor' ),
				'completeOrg'     => __( 'Please complete your organisation profile.', 'business-doctor' ),
				'unlockTitle'     => __( 'Unlock the full assessment', 'business-doctor' ),
				'unlockBody'      => __( 'Pay once to unlock this organisation\'s full, evidence-based assessment.', 'business-doctor' ),
				'payNow'          => __( 'Pay with Paystack', 'business-doctor' ),
				'redirecting'     => __( 'Redirecting to Paystack...', 'business-doctor' ),
				'verifyingPayment' => __( 'Checking your payment...', 'business-doctor' ),
				'paymentSuccess'  => __( 'Payment received. You can now start the assessment.', 'business-doctor' ),
				'paymentFailed'   => __( 'That payment did not go through. You can try again below.', 'business-doctor' ),
				'paymentPending'  => __( 'Your payment is still being confirmed. This can take a minute; refresh to check again.', 'business-doctor' ),
				'notConfigured'   => __( 'Online payment is not set up yet. Please contact us to arrange access.', 'business-doctor' ),
				'invoice'         => __( 'Invoice', 'business-doctor' ),
			),
			// "Verified" is left out deliberately: only a reviewer can mark evidence as independently
			// verified, since a business owner cannot verify their own evidence. The REST API rejects
			// it from an owner too, so this is a UI convenience, not the only guard.
			'evidenceClasses' => array(
				array(
					'value' => 'Document-supported',
					'label' => __( 'Supported by a document', 'business-doctor' ),
				),
				array(
					'value' => 'Self-reported',
					'label' => __( 'Self-reported', 'business-doctor' ),
				),
				array(
					'value' => 'Unverified',
					'label' => __( 'Unverified', 'business-doctor' ),
				),
				array(
					'value' => 'Missing',
					'label' => __( 'No evidence available', 'business-doctor' ),
				),
			),
		);
	}

	/**
	 * [business_doctor_owner]
	 *
	 * @param array $atts Shortcode attributes (platform optional).
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'platform' => '' ), $atts, 'business_doctor_owner' );
		wp_enqueue_style( 'business-doctor' );
		wp_enqueue_script( 'business-doctor-owner' );
		wp_add_inline_script( 'business-doctor-owner', 'window.BusinessDoctorOwner = ' . wp_json_encode( self::config() ) . ';', 'before' );
		return '<div class="bd-owner-app" data-platform="' . esc_attr( bd_sanitize_code( $atts['platform'] ) ) . '"></div>';
	}
}
