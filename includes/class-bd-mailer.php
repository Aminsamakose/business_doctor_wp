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
			. '<p><a href="' . esc_url( BD_Report::url( $r['token'] ) ) . '">' . esc_html__( 'View, print or save your full report', 'business-doctor' ) . '</a> (' . esc_html( BD_Results::reference( $r ) ) . ')</p>'
			. $cta
			. '<p style="font-size:12px;color:#777;">' . esc_html( BD_Report::disclaimer() ) . '</p>'
			. '<p style="font-size:12px;color:#777;">' . esc_html( $s['brand_name'] . ' ' . $s['brand_byline'] . ' | ' . $s['site_label'] ) . '</p></div>';

		$sent = wp_mail( $r['email'], $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			BD_Results::mark_emailed( $r['id'] );
		}
		return $sent;
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
}
