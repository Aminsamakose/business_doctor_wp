<?php
/**
 * Shortcodes:
 *   [business_doctor_assessment platform="SME360"]  one assessment
 *   [business_doctor_assessment]                    lets the visitor choose the platform
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Shortcodes {

	/**
	 * Registers shortcodes and assets.
	 */
	public static function init() {
		add_shortcode( 'business_doctor_assessment', array( __CLASS__, 'assessment' ) );
		// Registered on init, not wp_enqueue_scripts: block themes render page content (and so run
		// this shortcode) before wp_enqueue_scripts fires, and an unregistered handle would lose its
		// inline configuration.
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registers (does not enqueue) the front-end assets.
	 */
	public static function register_assets() {
		if ( wp_script_is( 'business-doctor', 'registered' ) ) {
			return;
		}
		wp_register_style( 'business-doctor', BD_URL . 'assets/css/business-doctor.css', array(), BD_VERSION );
		wp_register_script( 'business-doctor', BD_URL . 'assets/js/assessment.js', array(), BD_VERSION, true );
	}

	/**
	 * Data the front end needs.
	 *
	 * @return array
	 */
	private static function config() {
		$s = BD_Settings::all();
		return array(
			'api'           => esc_url_raw( rest_url( BD_Rest::NS ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'privacyUrl'    => $s['privacy_url'] ? $s['privacy_url'] : ( get_privacy_policy_url() ? get_privacy_policy_url() : '' ),
			'fullUrl'       => $s['full_assessment_url'],
			'homeUrl'       => home_url( '/' ),
			'disclaimer'    => BD_Report::disclaimer(),
			'i18n'          => array(
				'choose'        => __( 'Choose the assessment that fits you', 'business-doctor' ),
				/* translators: %1$d: number of questions. */
				'questionCount' => __( '%1$d questions', 'business-doctor' ),
				'name'          => __( 'Your name', 'business-doctor' ),
				'email'         => __( 'Email', 'business-doctor' ),
				'privacyBefore' => __( 'I have read and agree to the', 'business-doctor' ),
				'privacyLink'   => __( 'privacy notice', 'business-doctor' ),
				'start'         => __( 'Start the 25 questions', 'business-doctor' ),
				'starting'      => __( 'Starting...', 'business-doctor' ),
				'question'      => __( 'Question %1$d of %2$d', 'business-doctor' ),
				'back'          => __( 'Back', 'business-doctor' ),
				'next'          => __( 'Next', 'business-doctor' ),
				'see'           => __( 'See my result', 'business-doctor' ),
				'scoring'       => __( 'Scoring...', 'business-doctor' ),
				'answerAll'     => __( 'Answer all %1$d questions to see your result. %2$d of %1$d answered.', 'business-doctor' ),
				'resultTitle'   => __( 'Your preliminary result', 'business-doctor' ),
				'dimensions'    => __( 'Dimension scores', 'business-doctor' ),
				'strengths'     => __( 'Strengths', 'business-doctor' ),
				'priorities'    => __( 'Priority areas', 'business-doctor' ),
				'emailMe'       => __( 'Email me this result', 'business-doctor' ),
				'sending'       => __( 'Sending...', 'business-doctor' ),
				'emailed'       => __( 'Emailed', 'business-doctor' ),
				'report'        => __( 'Open printable report', 'business-doctor' ),
				'keepCopy'      => __( 'Print or save your report before closing this page.', 'business-doctor' ),
				'explore'       => __( 'Explore the full Business Health Assessment', 'business-doctor' ),
				'home'          => __( 'Return to Home', 'business-doctor' ),
				'checkFields'   => __( 'Please check the highlighted fields.', 'business-doctor' ),
				'needName'      => __( 'Enter your name', 'business-doctor' ),
				'needEmail'     => __( 'Enter a valid email address', 'business-doctor' ),
				'needPrivacy'   => __( 'Please acknowledge the privacy notice to continue', 'business-doctor' ),
				'network'       => __( 'We could not reach the server. Check your connection and try again.', 'business-doctor' ),
				'generic'       => __( 'Something went wrong. Please try again.', 'business-doctor' ),
				'startOver'     => __( 'Take another assessment', 'business-doctor' ),
				'unavailable'   => __( 'No assessment is available right now. Please check back soon.', 'business-doctor' ),
			),
		);
	}

	/**
	 * Renders the assessment container; assets/js/assessment.js does the rest.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function assessment( $atts ) {
		$atts     = shortcode_atts( array( 'platform' => '' ), $atts, 'business_doctor_assessment' );
		$platform = bd_sanitize_code( $atts['platform'] );
		if ( $platform && ! array_key_exists( $platform, BD_Frameworks::platforms() ) ) {
			$platform = '';
		}
		wp_enqueue_style( 'business-doctor' );
		wp_enqueue_script( 'business-doctor' );
		wp_add_inline_script( 'business-doctor', 'window.BusinessDoctor = window.BusinessDoctor || ' . wp_json_encode( self::config() ) . ';', 'before' );

		return '<div class="bd-app" data-bd-assessment data-platform="' . esc_attr( $platform ) . '">'
			. '<noscript><p>' . esc_html__( 'Please enable JavaScript to take the assessment.', 'business-doctor' ) . '</p></noscript>'
			. '<div class="bd-card bd-loading" aria-busy="true">' . esc_html__( 'Loading the assessment...', 'business-doctor' ) . '</div>'
			. '</div>';
	}
}
