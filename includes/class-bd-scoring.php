<?php
/**
 * Free assessment scoring. A direct port of scoreFreeAssessment() and freeAssessmentClassification()
 * in Samakose AI (src/domain/logic.ts), kept free of WordPress calls so it can be unit tested alone.
 *
 * Model (approved free-assessment spec): A=4, B=3, C=2, D=1, equal weight per question.
 * A dimension score is its points over its maximum, as a percentage to one decimal place;
 * the overall score is the same over every question. No evidence discount applies.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || defined( 'BD_TESTING' ) || exit;

class BD_Scoring {

	const LETTER_POINTS = array(
		'A' => 4,
		'B' => 3,
		'C' => 2,
		'D' => 1,
	);

	const DEFAULT_THRESHOLDS = array(
		'critical_below'    => 40,
		'needs_below'       => 60,
		'developing_below'  => 80,
	);

	const BANDS = array( 'Critical Attention', 'Needs Improvement', 'Developing', 'Strong Foundation' );

	/**
	 * Rounds to one decimal place exactly as JavaScript's Math.round(x * 1000) / 10 does
	 * for the ratio x, so PHP and the original TypeScript produce identical scores.
	 *
	 * @param float $ratio Points over maximum, between 0 and 1.
	 * @return float
	 */
	public static function percent( $ratio ) {
		return floor( $ratio * 1000 + 0.5 ) / 10;
	}

	/**
	 * Classifies an overall score into its band.
	 *
	 * @param float $score      Overall score, 0 to 100.
	 * @param array $thresholds Keys critical_below, needs_below, developing_below.
	 * @return string
	 */
	public static function classify( $score, array $thresholds = array() ) {
		$t = array_merge( self::DEFAULT_THRESHOLDS, $thresholds );
		if ( $score < (float) $t['critical_below'] ) {
			return 'Critical Attention';
		}
		if ( $score < (float) $t['needs_below'] ) {
			return 'Needs Improvement';
		}
		if ( $score < (float) $t['developing_below'] ) {
			return 'Developing';
		}
		return 'Strong Foundation';
	}

	/**
	 * Scores a complete set of answers.
	 *
	 * @param array $answers    Map of question code => letter A to D.
	 * @param array $questions  List of arrays with keys code and dimension, in framework order.
	 * @param array $thresholds Band thresholds.
	 * @return array{overall: float, classification: string, dimensions: array<int, array{dimension: string, score: float}>}
	 * @throws InvalidArgumentException When an answer is missing or not A to D.
	 */
	public static function score( array $answers, array $questions, array $thresholds = array() ) {
		$missing = array();
		foreach ( $questions as $q ) {
			$letter = isset( $answers[ $q['code'] ] ) ? strtoupper( (string) $answers[ $q['code'] ] ) : '';
			if ( ! isset( self::LETTER_POINTS[ $letter ] ) ) {
				$missing[] = $q['code'];
			}
		}
		if ( $missing ) {
			throw new InvalidArgumentException( 'Missing or invalid answer for: ' . implode( ', ', $missing ) );
		}

		$dims = array();
		foreach ( $questions as $q ) {
			$d = $q['dimension'];
			if ( ! isset( $dims[ $d ] ) ) {
				$dims[ $d ] = array(
					'sum'   => 0,
					'count' => 0,
				);
			}
			$dims[ $d ]['sum']  += self::LETTER_POINTS[ strtoupper( (string) $answers[ $q['code'] ] ) ];
			$dims[ $d ]['count'] += 1;
		}

		$total_sum   = 0;
		$total_count = 0;
		$dimensions  = array();
		foreach ( $dims as $name => $d ) {
			$total_sum   += $d['sum'];
			$total_count += $d['count'];
			$dimensions[] = array(
				'dimension' => (string) $name,
				'score'     => self::percent( $d['sum'] / ( $d['count'] * 4 ) ),
			);
		}
		$overall = $total_count ? self::percent( $total_sum / ( $total_count * 4 ) ) : 0.0;

		return array(
			'overall'        => $overall,
			'classification' => self::classify( $overall, $thresholds ),
			'dimensions'     => $dimensions,
		);
	}

	/**
	 * Picks strengths and priority areas the same way the original result screen does:
	 * the top two and bottom two dimensions when there are five or more, otherwise one each.
	 * Ties keep framework order.
	 *
	 * @param array $dimensions List of array{dimension, score}.
	 * @return array{strengths: string[], priorities: string[]}
	 */
	public static function findings( array $dimensions ) {
		$indexed = array();
		foreach ( array_values( $dimensions ) as $i => $d ) {
			$indexed[] = array( $i, $d );
		}
		usort(
			$indexed,
			static function ( $a, $b ) {
				if ( $a[1]['score'] === $b[1]['score'] ) {
					return $a[0] - $b[0];
				}
				return $a[1]['score'] < $b[1]['score'] ? 1 : -1;
			}
		);
		$sorted     = array_map(
			static function ( $x ) {
				return $x[1]['dimension'];
			},
			$indexed
		);
		$top_n      = count( $sorted ) >= 5 ? 2 : 1;
		$strengths  = array_slice( $sorted, 0, $top_n );
		$priorities = array_reverse( array_slice( $sorted, -$top_n ) );
		return array(
			'strengths'  => $strengths,
			'priorities' => $priorities,
		);
	}
}
