<?php
/**
 * Emails: the visitor's result (on request) and a new-lead alert to staff.
 * Uses wp_mail, so any SMTP plugin the site already uses applies.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Mailer {

	/**
	 * Hooks the staff alert.
	 */
	public static function init() {
		add_action( 'bd_result_completed', array( __CLASS__, 'notify_staff' ) );
		add_action( 'bd_diagnostic_submitted', array( __CLASS__, 'notify_staff_diagnostic' ) );
		add_action( 'bd_payment_settled', array( __CLASS__, 'notify_staff_payment' ) );
	}

	/**
	 * Emails a visitor their own result, with a link to the printable report.
	 *
	 * @param array $r Result.
	 * @return bool
	 */
	public static function send_result( array $r ) {
		$s        = BD_Settings::all();
		$name     = BD_Frameworks::short_name( $r['platform'] );
		$findings = BD_Scoring::findings( $r['dimension_scores'] );
		/* translators: %s: platform name, for example SME360. */
		$subject = sprintf( __( 'Your free %s Business Health result', 'business-doctor' ), $name );

		$rows = '';
		foreach ( $r['dimension_scores'] as $d ) {
			$w     = max( 0, min( 100, (float) $d['score'] ) );
			$rows .= '<tr><td style="padding:6px 12px 6px 0;font-size:14px;">' . esc_html( $d['dimension'] ) . '</td>'
				. '<td style="padding:6px 0;width:160px;"><div style="background:#e5e5e5;border-radius:4px;height:8px;"><div style="background:#1e7a5a;border-radius:4px;height:8px;width:' . esc_attr( $w ) . '%;"></div></div></td>'
				. '<td style="padding:6px 0 6px 12px;font-size:14px;color:#555;text-align:right;">' . esc_html( $d['score'] ) . '/100</td></tr>';
		}
		$cta = $s['full_assessment_url'] ? '<p><a href="' . esc_url( $s['full_assessment_url'] ) . '" style="display:inline-block;background:#0f4a3f;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:600;">' . esc_html__( 'Explore the full Business Health Assessment', 'business-doctor' ) . '</a></p>' : '';

		$body = '<div style="font-family:Arial,Helvetica,sans-serif;color:#0c2622;max-width:620px;margin:0 auto;">'
			. '<p style="font-size:18px;font-weight:700;margin:0;">' . esc_html( $s['brand_name'] ) . ' <span style="font-weight:400;color:#555;font-size:13px;">' . esc_html( $s['brand_byline'] ) . '</span></p><hr style="border:0;border-top:2px solid #0f4a3f;">'
			/* translators: %s: person's name. */
			. '<p>' . esc_html( sprintf( __( 'Hello %s,', 'business-doctor' ), $r['full_name'] ) ) . '</p>'
			/* translators: %s: platform name. */
			. '<p>' . esc_html( sprintf( __( 'Here is your preliminary %s result.', 'business-doctor' ), $name ) ) . '</p>'
			. '<p style="font-size:36px;font-weight:700;margin:8px 0;">' . esc_html( $r['overall_score'] ) . '<span style="font-size:16px;color:#777;font-weight:400;">/100</span> <span style="font-size:16px;">' . esc_html( $r['classification'] ) . '</span></p>'
			. '<table role="presentation" style="border-collapse:collapse;">' . $rows . '</table>'
			. '<p><strong>' . esc_html__( 'Strengths:', 'business-doctor' ) . '</strong> ' . esc_html( implode( ', ', $findings['strengths'] ) ) . '<br><strong>' . esc_html__( 'Priority areas:', 'business-doctor' ) . '</strong> ' . esc_html( implode( ', ', $findings['priorities'] ) ) . '</p>'
			. '<p>' . esc_html__( 'A PDF copy of this report is attached.', 'business-doctor' ) . ' <a href="' . esc_url( BD_Report::url( $r['token'] ) ) . '">' . esc_html__( 'Or view it online', 'business-doctor' ) . '</a> (' . esc_html( BD_Results::reference( $r ) ) . ')</p>'
			. $cta
			. '<p style="font-size:12px;color:#777;">' . esc_html( BD_Report::disclaimer() ) . '</p>'
			. '<p style="font-size:12px;color:#777;">' . esc_html( $s['brand_name'] . ' ' . $s['brand_byline'] . ' | ' . $s['site_label'] ) . '</p></div>';

		$attachment = self::attach_pdf( $r );
		$sent       = wp_mail( $r['email'], $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachment ? array( $attachment ) : array() );
		if ( $attachment ) {
			wp_delete_file( $attachment );
		}
		if ( $sent ) {
			BD_Results::mark_emailed( $r['id'] );
		}
		return $sent;
	}

	/**
	 * Writes the result's PDF to a temporary file for wp_mail's attachments parameter, which
	 * takes file paths rather than in-memory content. Deleted by the caller once the mail is sent.
	 *
	 * @param array $r Result.
	 * @return string|null Temp file path, or null if it could not be written.
	 */
	private static function attach_pdf( array $r ) {
		try {
			$pdf = BD_Pdf::build( $r );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.Files.LineLength
			return null;
		}
		$path = wp_tempnam( 'bd-result-' . $r['token'] . '.pdf' );
		if ( ! $path || false === file_put_contents( $path, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		// wp_tempnam() always returns a path ending in .tmp regardless of the name hint, and PHPMailer
		// needs a .pdf extension to attach it with a sensible filename and MIME type. Strip whatever
		// extension is there (there is always exactly one, from wp_tempnam's own suffix) and add .pdf,
		// rather than a trailing regex replace, which can match twice and double the extension.
		$renamed = preg_replace( '/\.[^.\/\\\\]*$/', '', $path ) . '.pdf';
		if ( $renamed !== $path && @rename( $path, $renamed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $renamed;
		}
		return $path;
	}

	/**
	 * Tells staff that a new result has arrived.
	 *
	 * @param array $row Stored row.
	 */
	public static function notify_staff( $row ) {
		$s = BD_Settings::all();
		if ( empty( $s['notify_on_complete'] ) || ! is_email( $s['notify_email'] ) ) {
			return;
		}
		$link = admin_url( 'admin.php?page=bd-results&action=view&id=' . (int) $row['id'] );
		/* translators: 1: platform name, 2: score, 3: band. */
		$subject = sprintf( __( 'New free assessment: %1$s, %2$s/100 (%3$s)', 'business-doctor' ), BD_Frameworks::short_name( $row['platform'] ), $row['overall_score'], $row['classification'] );
		$lines   = array(
			/* translators: %s: name. */
			sprintf( __( 'Name: %s', 'business-doctor' ), $row['full_name'] ),
			/* translators: %s: email. */
			sprintf( __( 'Email: %s', 'business-doctor' ), $row['email'] ),
			/* translators: %s: platform. */
			sprintf( __( 'Platform: %s', 'business-doctor' ), BD_Frameworks::short_name( $row['platform'] ) ),
			/* translators: 1: score, 2: band. */
			sprintf( __( 'Score: %1$s/100 (%2$s)', 'business-doctor' ), $row['overall_score'], $row['classification'] ),
			'',
			__( 'The visitor acknowledged the privacy notice. That is not consent to marketing; follow up only as your privacy notice allows.', 'business-doctor' ),
			'',
			$link,
		);
		wp_mail( $s['notify_email'], $subject, implode( "\n", $lines ) );
	}

	/**
	 * Tells staff that a business owner has submitted the full assessment.
	 *
	 * @param array $d Diagnostic, as returned by BD_Diagnostics::get().
	 */
	public static function notify_staff_diagnostic( array $d ) {
		$s = BD_Settings::all();
		if ( empty( $s['notify_on_complete'] ) || ! is_email( $s['notify_email'] ) ) {
			return;
		}
		$org  = BD_Organisations::get( $d['org_id'] );
		$user = get_userdata( $d['user_id'] );
		$link = admin_url( 'admin.php?page=bd-diagnostics&action=view&id=' . (int) $d['id'] );
		/* translators: 1: platform name, 2: score, 3: maturity band. */
		$subject = sprintf( __( 'New full assessment: %1$s, %2$s/100 (%3$s)', 'business-doctor' ), BD_Frameworks::short_name( $d['platform'] ), $d['overall_score'], $d['maturity'] );
		$lines   = array(
			/* translators: %s: organisation name. */
			sprintf( __( 'Organisation: %s', 'business-doctor' ), $org ? $org['name'] : __( '(no organisation name)', 'business-doctor' ) ),
			/* translators: %s: owner name and email. */
			sprintf( __( 'Owner: %s', 'business-doctor' ), $user ? $user->display_name . ' (' . $user->user_email . ')' : '' ),
			/* translators: %s: platform. */
			sprintf( __( 'Platform: %s', 'business-doctor' ), BD_Frameworks::short_name( $d['platform'] ) ),
			/* translators: 1: score, 2: maturity band, 3: confidence. */
			sprintf( __( 'Score: %1$s/100 (%2$s), confidence %3$s', 'business-doctor' ), $d['overall_score'], $d['maturity'], $d['confidence'] ),
			'',
			$link,
		);
		wp_mail( $s['notify_email'], $subject, implode( "\n", $lines ) );
	}

	/**
	 * Tells staff that a payment settled and an organisation's full assessment is now unlocked.
	 *
	 * @param array $payment Settled payment row, merged with its final status and invoice number.
	 */
	public static function notify_staff_payment( array $payment ) {
		$s = BD_Settings::all();
		if ( empty( $s['notify_on_complete'] ) || ! is_email( $s['notify_email'] ) ) {
			return;
		}
		$org  = BD_Organisations::get( (int) $payment['org_id'] );
		$user = get_userdata( (int) $payment['user_id'] );
		$link = admin_url( 'admin.php?page=bd-payments' );
		/* translators: 1: platform name, 2: amount in GHS. */
		$subject = sprintf( __( 'Payment received: %1$s, GHS %2$s', 'business-doctor' ), BD_Frameworks::short_name( $payment['platform'] ), $payment['amount_ghs'] );
		$lines   = array(
			/* translators: %s: organisation name. */
			sprintf( __( 'Organisation: %s', 'business-doctor' ), $org ? $org['name'] : __( '(no organisation name)', 'business-doctor' ) ),
			/* translators: %s: owner name and email. */
			sprintf( __( 'Owner: %s', 'business-doctor' ), $user ? $user->display_name . ' (' . $user->user_email . ')' : '' ),
			/* translators: %s: platform. */
			sprintf( __( 'Platform: %s', 'business-doctor' ), BD_Frameworks::short_name( $payment['platform'] ) ),
			/* translators: 1: amount, 2: invoice number. */
			sprintf( __( 'Amount: GHS %1$s (invoice %2$s)', 'business-doctor' ), $payment['amount_ghs'], $payment['invoice_number'] ),
			'',
			$link,
		);
		wp_mail( $s['notify_email'], $subject, implode( "\n", $lines ) );
	}
}
