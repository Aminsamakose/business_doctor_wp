<?php
/**
 * Evidence-weighted scoring for the full (paid-equivalent) assessment. A direct port of
 * scoreDiagnostic() and maturityBand() in Samakose AI (src/domain/logic.ts). Unlike the free
 * assessment's plain A/B/C/D scorer, every answer here is discounted by how well it is backed by
 * evidence, and every question carries its own weight rather than counting equally.
 *
 * Kept free of WordPress calls so it can be unit tested alone, same pattern as BD_Scoring.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || defined( 'BD_TESTING' ) || exit;

class BD_Diagnostic_Scoring {

	/** Default evidence multipliers; mirrors DEFAULT_RULES in the original. */
	const EVIDENCE_MULTIPLIERS = array(
		'Verified'           => 1,
		'Document-supported' => 0.95,
		'Self-reported'      => 0.8,
		'Unverified'         => 0.6,
		'Missing'            => 0.5,
	);

	const DEFAULT_THRESHOLDS = array(
		'critical_below'   => 40,
		'fragile_below'    => 60,
		'developing_below' => 75,
	);

	const CONFIDENCE_DEFAULTS = array(
		'high_share'   => 0.6,
		'medium_share' => 0.35,
	);

	/**
	 * Rounds to one decimal place exactly as JavaScript's Math.round(x * 1000) / 10 does, so PHP and
	 * the original TypeScript produce identical scores. PHP's own round() uses a different rounding
	 * mode at the halfway point and can differ from JS by 0.1 on boundary values (same fix as
	 * BD_Scoring::percent() for the free assessment).
	 *
	 * @param float $value Non-negative value to round (overall and dimension scores are always >= 0).
	 * @return float
	 */
	private static function js_round_1dp( $value ) {
		return floor( $value * 1000 + 0.5 ) / 10;
	}

	/**
	 * A question "applies" to every response unless its applies field names a specific condition.
	 *
	 * @param array $question Question with an optional 'applies' key.
	 * @return bool
	 */
	public static function is_conditional( array $question ) {
		$applies = isset( $question['applies'] ) ? trim( (string) $question['applies'] ) : '';
		return '' !== $applies && 'all' !== strtolower( $applies );
	}

	/**
	 * Band for an overall score.
	 *
	 * @param float $score      Overall score, 0 to 100.
	 * @param array $thresholds Keys critical_below, fragile_below, developing_below.
	 * @return string
	 */
	public static function maturity_band( $score, array $thresholds = array() ) {
		$t = array_merge( self::DEFAULT_THRESHOLDS, $thresholds );
		if ( $score < (float) $t['critical_below'] ) {
			return 'Critical';
		}
		if ( $score < (float) $t['fragile_below'] ) {
			return 'Fragile';
		}
		if ( $score < (float) $t['developing_below'] ) {
			return 'Developing';
		}
		return 'Strong';
	}

	/**
	 * Scores a set of responses against a question bank.
	 *
	 * @param array $responses        List of array{questionCode, value, evidenceClass, notApplicable?}.
	 *                                A question with no matching response, or notApplicable true, is
	 *                                left out of every total, exactly as the original does.
	 * @param array $questions        List of array{code, dimension, weight, applies?}, in framework order.
	 * @param array $thresholds       Maturity band thresholds.
	 * @param array $evidence_mult    Evidence multipliers, keyed by evidence class.
	 * @return array{dimensions: array, overall: float, maturity: string, evidenceShare: array}
	 */
	public static function score( array $responses, array $questions, array $thresholds = array(), array $evidence_mult = array() ) {
		$mult = array_merge( self::EVIDENCE_MULTIPLIERS, $evidence_mult );
		$by_code = array();
		foreach ( $responses as $r ) {
			$by_code[ $r['questionCode'] ] = $r;
		}

		$dims     = array();
		$share    = array();
		$total_w  = 0.0;

		foreach ( $questions as $q ) {
			if ( ! isset( $by_code[ $q['code'] ] ) ) {
				continue;
			}
			$r = $by_code[ $q['code'] ];
			if ( ! empty( $r['notApplicable'] ) ) {
				continue;
			}
			$ev = isset( $r['evidenceClass'] ) ? (string) $r['evidenceClass'] : '';
			$m  = isset( $mult[ $ev ] ) ? (float) $mult[ $ev ] : 0.8; // Unknown class falls back like NaN did in the original (0.8).
			$w  = isset( $q['weight'] ) && $q['weight'] ? (float) $q['weight'] : 1.0;
			$d  = isset( $dims[ $q['dimension'] ] ) ? $dims[ $q['dimension'] ] : array(
				'num' => 0.0,
				'den' => 0.0,
			);
			$d['num']             += $w * ( ( (float) $r['value'] ) / 4 ) * $m;
			$d['den']             += $w;
			$dims[ $q['dimension'] ] = $d;
			$share[ $ev ]          = ( isset( $share[ $ev ] ) ? $share[ $ev ] : 0.0 ) + $w;
			$total_w              += $w;
		}

		$sn = 0.0;
		$sd = 0.0;
		$dimensions = array();
		foreach ( $dims as $name => $d ) {
			$sn          += $d['num'];
			$sd          += $d['den'];
			$dimensions[] = array(
				'dimension' => (string) $name,
				'value'     => $d['den'] ? self::js_round_1dp( $d['num'] / $d['den'] ) : 0.0,
			);
		}
		$overall = $sd ? self::js_round_1dp( $sn / $sd ) : 0.0;

		$evidence_share = array();
		foreach ( $share as $k => $v ) {
			$evidence_share[ $k ] = $total_w ? $v / $total_w : 0.0;
		}

		return array(
			'dimensions'    => $dimensions,
			'overall'       => $overall,
			'maturity'      => self::maturity_band( $overall, $thresholds ),
			'evidenceShare' => $evidence_share,
		);
	}

	/**
	 * Cross-question consistency check: a strong claim (rated 3 or 4) with no evidence at all is a
	 * contradiction. Direct port of the rule check in Samakose AI's computeScore()
	 * (src/services/diagnostics.ts): `!lite.some((r) => !r.notApplicable && r.value >= 3 &&
	 * r.evidenceClass === 'Missing')`.
	 *
	 * @param array $responses List of array{value, notApplicable?, evidenceClass}.
	 * @return bool
	 */
	public static function rule_checks_passed( array $responses ) {
		foreach ( $responses as $r ) {
			if ( empty( $r['notApplicable'] ) && isset( $r['value'] ) && (int) $r['value'] >= 3 && 'Missing' === ( $r['evidenceClass'] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Confidence in a scored result, from evidence strength, rule checks and data-quality validation.
	 * The model never states a confidence itself; this is the deterministic read of the evidence.
	 *
	 * @param array $evidence_share     Share of weight per evidence class, as returned by score().
	 * @param bool  $rule_checks_passed Whether cross-question consistency checks passed.
	 * @param bool  $validation_ok      Whether the submission met the completion gate.
	 * @param array $confidence_cfg     Keys high_share, medium_share.
	 * @return string Low, Medium or High.
	 */
	public static function confidence_class( array $evidence_share, $rule_checks_passed, $validation_ok, array $confidence_cfg = array() ) {
		if ( ! $validation_ok ) {
			return 'Low';
		}
		$cfg    = array_merge( self::CONFIDENCE_DEFAULTS, $confidence_cfg );
		$strong = ( isset( $evidence_share['Verified'] ) ? $evidence_share['Verified'] : 0 )
			+ ( isset( $evidence_share['Document-supported'] ) ? $evidence_share['Document-supported'] : 0 );
		$level  = $strong >= (float) $cfg['high_share'] ? 2 : ( $strong >= (float) $cfg['medium_share'] ? 1 : 0 );
		if ( ! $rule_checks_passed ) {
			$level = max( 0, $level - 1 );
		}
		$labels = array( 'Low', 'Medium', 'High' );
		return $labels[ $level ];
	}

	/**
	 * Data-quality gate: how much of the applicable question bank was actually answered.
	 * Mirrors validateSubmission()'s completion calculation (not its full field-level validation,
	 * which belongs to the REST layer where input is first parsed).
	 *
	 * @param array $responses List of array{questionCode, notApplicable?}.
	 * @param array $questions List of array{code, applies?}.
	 * @param float $min       Minimum completion share, 0 to 1.
	 * @return array{ok: bool, completion: float}
	 */
	public static function completion( array $responses, array $questions, $min = 0.9 ) {
		$by_code = array();
		foreach ( $responses as $r ) {
			$by_code[ $r['questionCode'] ] = $r;
		}
		$answered = 0;
		$na       = 0;
		foreach ( $questions as $q ) {
			if ( ! isset( $by_code[ $q['code'] ] ) ) {
				continue;
			}
			if ( ! empty( $by_code[ $q['code'] ]['notApplicable'] ) ) {
				if ( self::is_conditional( $q ) ) {
					++$na;
				}
				continue;
			}
			++$answered;
		}
		$applicable = count( $questions ) - $na;
		$completion = $applicable > 0 ? $answered / $applicable : 0.0;
		return array(
			'ok'         => $completion >= $min,
			'completion' => floor( $completion * 1000 + 0.5 ) / 1000,
		);
	}
}
