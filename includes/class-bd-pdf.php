<?php
/**
 * Renders a result as a one-page branded PDF, using the bundled FPDF library (no external
 * service, no Composer dependency). Used for the "Download PDF" button and as the email
 * attachment, so results sent by email keep the formatted report rather than a plain-text dump.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

require_once BD_DIR . 'vendor/fpdf/fpdf.php';

class BD_Pdf {

	const FOREST = array( 15, 74, 63 );
	const LIME   = array( 198, 242, 107 );
	const INK    = array( 12, 38, 34 );
	const MUTED  = array( 90, 90, 90 );
	const TRACK  = array( 229, 229, 229 );
	const BAR    = array( 30, 122, 90 );

	/**
	 * Builds the PDF and returns it as a binary string.
	 *
	 * @param array $r Result row (as hydrated by BD_Results).
	 * @return string
	 */
	public static function build( array $r ) {
		$s        = BD_Settings::all();
		$name     = BD_Frameworks::short_name( $r['platform'] );
		$findings = BD_Scoring::findings( $r['dimension_scores'] );
		$date     = wp_date( get_option( 'date_format' ), (int) strtotime( $r['completed_at'] . ' UTC' ) );
		$ref      = BD_Results::reference( $r );

		$pdf = new FPDF( 'P', 'mm', 'A4' );
		$pdf->SetAutoPageBreak( true, 14 );
		$pdf->SetMargins( 16, 14, 16 );
		$pdf->AddPage();
		$pdf->SetTitle( $name . ' ' . __( 'Preliminary Result', 'business-doctor' ) );

		// Header: brand mark + platform.
		$pdf->SetFillColor( self::FOREST[0], self::FOREST[1], self::FOREST[2] );
		$pdf->Rect( 16, 15, 8, 8, 'F' );
		$pdf->SetFont( 'Helvetica', 'B', 13 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$pdf->SetXY( 28, 15 );
		$pdf->Cell( 100, 6, self::txt( $s['brand_name'] ), 0, 2 );
		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->SetX( 28 );
		$pdf->Cell( 100, 5, self::txt( $s['brand_byline'] ) );

		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$pdf->SetXY( 130, 15 );
		$pdf->Cell( 64, 5, self::txt( __( 'Free Business Health Check', 'business-doctor' ) ), 0, 2, 'R' );
		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->SetX( 130 );
		$pdf->Cell( 64, 5, self::txt( $name ), 0, 1, 'R' );

		$pdf->SetY( 30 );
		$pdf->SetDrawColor( self::FOREST[0], self::FOREST[1], self::FOREST[2] );
		$pdf->SetLineWidth( 0.6 );
		$pdf->Line( 16, 30, 194, 30 );

		// Title and meta line.
		$pdf->SetY( 35 );
		$pdf->SetFont( 'Helvetica', 'B', 16 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$pdf->Cell( 0, 8, self::txt( $name . ' ' . __( 'Preliminary Result', 'business-doctor' ) ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->Cell( 0, 6, self::txt( implode( '   |   ', array_filter( array( $r['full_name'], $ref, $date ) ) ) ), 0, 1 );

		// Score.
		$pdf->Ln( 2 );
		$pdf->SetFont( 'Helvetica', 'B', 30 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$score_txt = (string) $r['overall_score'];
		$pdf->Cell( self::width( $pdf, $score_txt ), 14, self::txt( $score_txt ) );
		$pdf->SetFont( 'Helvetica', '', 11 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->Cell( 14, 14, '/100' );
		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetTextColor( self::BAR[0], self::BAR[1], self::BAR[2] );
		$pdf->Cell( 0, 14, self::txt( $r['classification'] ), 0, 1 );

		// Dimension scores.
		$pdf->Ln( 2 );
		$pdf->SetFont( 'Helvetica', 'B', 12 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$pdf->Cell( 0, 7, self::txt( __( 'Dimension scores', 'business-doctor' ) ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 9.5 );
		foreach ( $r['dimension_scores'] as $d ) {
			$y   = $pdf->GetY();
			$pct = max( 0, min( 100, (float) $d['score'] ) );
			$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
			$pdf->SetXY( 16, $y );
			$pdf->Cell( 70, 6, self::txt( $d['dimension'] ) );
			$pdf->SetFillColor( self::TRACK[0], self::TRACK[1], self::TRACK[2] );
			$pdf->SetXY( 88, $y + 1.6 );
			$pdf->Cell( 72, 3, '', 0, 0, '', true );
			if ( $pct > 0 ) {
				$pdf->SetFillColor( self::BAR[0], self::BAR[1], self::BAR[2] );
				$pdf->SetXY( 88, $y + 1.6 );
				$pdf->Cell( 72 * ( $pct / 100 ), 3, '', 0, 0, '', true );
			}
			$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
			$pdf->SetXY( 162, $y );
			$pdf->Cell( 32, 6, self::txt( $d['score'] . '/100' ), 0, 1, 'R' );
		}

		// Findings.
		$pdf->Ln( 3 );
		$pdf->SetFont( 'Helvetica', 'B', 12 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$pdf->Cell( 0, 7, self::txt( __( 'Key findings', 'business-doctor' ) ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 9.5 );
		$pdf->SetTextColor( self::INK[0], self::INK[1], self::INK[2] );
		$s_label = count( $findings['strengths'] ) > 1 ? __( 'Strengths', 'business-doctor' ) : __( 'Strength', 'business-doctor' );
		$p_label = count( $findings['priorities'] ) > 1 ? __( 'Priority areas', 'business-doctor' ) : __( 'Priority area', 'business-doctor' );
		$pdf->MultiCell( 0, 5.5, self::txt( $s_label . ': ' . implode( ', ', $findings['strengths'] ) . '.' ), 0, 'L' );
		$pdf->MultiCell( 0, 5.5, self::txt( $p_label . ': ' . implode( ', ', $findings['priorities'] ) . '.' ), 0, 'L' );

		// Next step.
		$pdf->Ln( 1 );
		$pdf->SetFont( 'Helvetica', 'B', 12 );
		$pdf->Cell( 0, 7, self::txt( __( 'Recommended next step', 'business-doctor' ) ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 9.5 );
		/* translators: %s: platform name. */
		$pdf->MultiCell( 0, 5.5, self::txt( sprintf( __( 'Address your priority areas first, then explore the full %s Business Health Assessment for a complete diagnosis, evidence review and written action plan.', 'business-doctor' ), $name ) ), 0, 'L' );

		// Disclaimer.
		$pdf->Ln( 2 );
		$pdf->SetFont( 'Helvetica', '', 7.5 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->MultiCell( 0, 4, self::txt( BD_Report::disclaimer() ), 0, 'L' );

		// Footer.
		$pdf->SetY( -22 );
		$pdf->SetDrawColor( 204, 204, 204 );
		$pdf->SetLineWidth( 0.2 );
		$pdf->Line( 16, $pdf->GetY(), 194, $pdf->GetY() );
		$pdf->Ln( 2 );
		$pdf->SetFont( 'Helvetica', '', 7.5 );
		$pdf->SetTextColor( self::MUTED[0], self::MUTED[1], self::MUTED[2] );
		$pdf->Cell( 95, 5, self::txt( $s['brand_name'] . ' ' . $s['brand_byline'] . '  |  ' . __( 'Free preliminary assessment', 'business-doctor' ) ) );
		$pdf->Cell( 83, 5, self::txt( $s['site_label'] ), 0, 0, 'R' );

		return $pdf->Output( 'S' );
	}

	/**
	 * FPDF's core fonts are Windows-1252 only; converts UTF-8 text so accented characters
	 * (and the Â£-type artefacts of a naive cut) render correctly instead of as boxes or mojibake.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private static function txt( $text ) {
		$converted = @iconv( 'UTF-8', 'CP1252//TRANSLIT', (string) $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $converted ? (string) $text : $converted;
	}

	/**
	 * Approximate rendered width of a string at the PDF's current font, in mm.
	 *
	 * @param FPDF   $pdf  Document.
	 * @param string $text Text.
	 * @return float
	 */
	private static function width( $pdf, $text ) {
		return $pdf->GetStringWidth( self::txt( $text ) ) + 4;
	}
}
