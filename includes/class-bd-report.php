<?php
/**
 * The branded one-page report at /?bd_report={token}. It prints cleanly to A4, so visitors can
 * save it as a PDF from any browser without the plugin needing a PDF library.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Report {

	/**
	 * Hooks the report renderer.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	/**
	 * Report URL for a token.
	 *
	 * @param string $token Result token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( 'bd_report', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * The preliminary-result disclaimer used on screen, in email and in print.
	 *
	 * @return string
	 */
	public static function disclaimer() {
		return __( 'This is a preliminary assessment based on your own answers, not a certified diagnosis, investment-readiness determination or guarantee of business success. The score bands (Critical Attention, Needs Improvement, Developing, Strong Foundation) are provisional, set by expert judgment and not yet statistically validated against business outcomes.', 'business-doctor' );
	}

	/**
	 * Renders the report and stops WordPress when the query asks for one.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET['bd_report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['bd_report'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$r     = BD_Rate_Limit::hit( 'report', 60 ) ? BD_Results::get_by_token( $token ) : null;
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		if ( ! $r ) {
			status_header( 404 );
			wp_die( esc_html__( 'This report was not found. Check the link in your email.', 'business-doctor' ), esc_html__( 'Report not found', 'business-doctor' ), array( 'response' => 404 ) );
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		self::render( $r );
		exit;
	}

	/**
	 * Outputs the standalone report page.
	 *
	 * @param array $r Result.
	 */
	public static function render( array $r ) {
		$s        = BD_Settings::all();
		$name     = BD_Frameworks::short_name( $r['platform'] );
		$findings = BD_Scoring::findings( $r['dimension_scores'] );
		$date     = wp_date( get_option( 'date_format' ), (int) strtotime( $r['completed_at'] . ' UTC' ) );
		$css      = BD_URL . 'assets/css/report.css?ver=' . rawurlencode( BD_VERSION );
		$plural_s = count( $findings['strengths'] ) > 1;
		$plural_p = count( $findings['priorities'] ) > 1;
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $name . ' ' . __( 'Preliminary Result', 'business-doctor' ) . ' | ' . $s['brand_name'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
</head>
<body>
<div class="bd-toolbar">
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'business-doctor' ); ?></button>
	<?php if ( $s['full_assessment_url'] ) : ?>
		<a href="<?php echo esc_url( $s['full_assessment_url'] ); ?>"><?php esc_html_e( 'Explore the full Business Health Assessment', 'business-doctor' ); ?></a>
	<?php endif; ?>
</div>
<main class="bd-report">
	<header class="bd-head">
		<div class="bd-brand">
			<svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#0f4a3f"/><path d="M6 21h7l3-8 5 16 4-11 2 3h7" fill="none" stroke="#c6f26b" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<div><strong><?php echo esc_html( $s['brand_name'] ); ?></strong><span><?php echo esc_html( $s['brand_byline'] ); ?></span></div>
		</div>
		<div class="bd-kind"><strong><?php esc_html_e( 'Free Business Health Check', 'business-doctor' ); ?></strong><span><?php echo esc_html( $name ); ?></span></div>
	</header>
	<h1><?php echo esc_html( $name . ' ' . __( 'Preliminary Result', 'business-doctor' ) ); ?></h1>
	<p class="bd-meta"><?php echo esc_html( implode( '   |   ', array_filter( array( $r['full_name'], BD_Results::reference( $r ), $date ) ) ) ); ?></p>
	<p class="bd-score"><span class="bd-num"><?php echo esc_html( $r['overall_score'] ); ?></span><span class="bd-of">/100</span> <span class="bd-band bd-band-<?php echo esc_attr( sanitize_title( $r['classification'] ) ); ?>"><?php echo esc_html( $r['classification'] ); ?></span></p>
	<h2><?php esc_html_e( 'Dimension scores', 'business-doctor' ); ?></h2>
	<ul class="bd-dims">
		<?php foreach ( $r['dimension_scores'] as $d ) : ?>
			<li><span class="bd-dname"><?php echo esc_html( $d['dimension'] ); ?></span><span class="bd-bar"><span style="width:<?php echo esc_attr( max( 0, min( 100, (float) $d['score'] ) ) ); ?>%"></span></span><span class="bd-dval"><?php echo esc_html( $d['score'] ); ?>/100</span></li>
		<?php endforeach; ?>
	</ul>
	<h2><?php esc_html_e( 'Key findings', 'business-doctor' ); ?></h2>
	<p><?php echo esc_html( ( $plural_s ? __( 'Strengths', 'business-doctor' ) : __( 'Strength', 'business-doctor' ) ) . ': ' . implode( ', ', $findings['strengths'] ) . '.' ); ?></p>
	<p><?php echo esc_html( ( $plural_p ? __( 'Priority areas', 'business-doctor' ) : __( 'Priority area', 'business-doctor' ) ) . ': ' . implode( ', ', $findings['priorities'] ) . '.' ); ?></p>
	<h2><?php esc_html_e( 'Recommended next step', 'business-doctor' ); ?></h2>
	<?php /* translators: %s: platform name. */ ?>
	<p><?php echo esc_html( sprintf( __( 'Address your priority areas first, then explore the full %s Business Health Assessment for a complete diagnosis, evidence review and written action plan.', 'business-doctor' ), $name ) ); ?></p>
	<p class="bd-disclaimer"><?php echo esc_html( self::disclaimer() ); ?></p>
	<footer class="bd-foot"><span><?php echo esc_html( $s['brand_name'] . ' ' . $s['brand_byline'] . '  |  ' . __( 'Free preliminary assessment', 'business-doctor' ) ); ?></span><span><?php echo esc_html( $s['site_label'] ); ?></span></footer>
</main>
</body>
</html>
		<?php
	}
}
