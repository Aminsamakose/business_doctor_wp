<?php
/**
 * Stand-alone checks for the full (evidence-weighted) assessment scorer: php tests/run-diagnostic.php
 *
 * 1. Scoring parity: 456 response sets scored by the original Samakose AI TypeScript scorer
 *    (scoreDiagnostic() in src/domain/logic.ts) against the real SME360/AgriFood360/ESO360 full
 *    question banks pulled from production, must score identically in PHP.
 * 2. Maturity band boundaries.
 * 3. Question bank structure: 100 questions per platform, 8 dimensions, every question's dimension
 *    is one the platform declares.
 *
 * @package BusinessDoctor
 */

define( 'BD_TESTING', true );
require __DIR__ . '/../includes/class-bd-diagnostic-scoring.php';

$fails  = 0;
$checks = 0;

/**
 * Records one check.
 *
 * @param bool   $ok  Passed.
 * @param string $msg Message on failure.
 */
function bd_check( $ok, $msg ) {
	global $fails, $checks;
	$checks++;
	if ( ! $ok ) {
		$fails++;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/diagnostic-scoring-cases.json' ), true );
$banks   = json_decode( file_get_contents( __DIR__ . '/../data/full-frameworks.json' ), true );

bd_check( isset( $fixture['cases'] ) && count( $fixture['cases'] ) > 0, 'Fixture has no cases' );

foreach ( $fixture['cases'] as $i => $c ) {
	$platform_key = str_replace( '360', '360', $c['platform'] ); // SME360 / AGRIFOOD360 / ESO360, matches bank keys verbatim.
	$bank         = $banks[ $platform_key ] ?? null;
	bd_check( null !== $bank, "No local bank for platform {$c['platform']} (case $i)" );
	if ( null === $bank ) {
		continue;
	}
	$qs = array_map(
		static function ( $q ) {
			return array(
				'code'      => $q['code'],
				'dimension' => $q['dimension'],
				'weight'    => $q['weight'],
				'applies'   => $q['applies'] ?? 'All',
			);
		},
		$bank['questions']
	);

	$got = BD_Diagnostic_Scoring::score( $c['responses'], $qs );

	bd_check( abs( $got['overall'] - $c['result']['overall'] ) < 0.05, "Case $i ({$c['platform']}): overall {$got['overall']} expected {$c['result']['overall']}" );
	bd_check( $got['maturity'] === $c['result']['maturity'], "Case $i ({$c['platform']}): maturity {$got['maturity']} expected {$c['result']['maturity']}" );
	bd_check( count( $got['dimensions'] ) === count( $c['result']['dimensions'] ), "Case $i ({$c['platform']}): dimension count mismatch" );

	$expected_by_dim = array();
	foreach ( $c['result']['dimensions'] as $d ) {
		$expected_by_dim[ $d['dimension'] ] = $d['value'];
	}
	foreach ( $got['dimensions'] as $d ) {
		$exp = $expected_by_dim[ $d['dimension'] ] ?? null;
		bd_check( null !== $exp, "Case $i ({$c['platform']}): unexpected dimension {$d['dimension']}" );
		if ( null !== $exp ) {
			bd_check( abs( $d['value'] - $exp ) < 0.05, "Case $i ({$c['platform']}) dimension {$d['dimension']}: {$d['value']} expected {$exp}" );
		}
	}

	foreach ( $c['result']['evidenceShare'] as $klass => $share ) {
		$got_share = $got['evidenceShare'][ $klass ] ?? 0.0;
		bd_check( abs( $got_share - $share ) < 0.001, "Case $i ({$c['platform']}) evidenceShare.$klass: $got_share expected $share" );
	}
}

/* Maturity band boundaries, per DEFAULT_RULES (critical_below 40, fragile_below 60, developing_below 75). */
bd_check( 'Critical' === BD_Diagnostic_Scoring::maturity_band( 0 ), 'Band at 0' );
bd_check( 'Critical' === BD_Diagnostic_Scoring::maturity_band( 39.9 ), 'Band at 39.9' );
bd_check( 'Fragile' === BD_Diagnostic_Scoring::maturity_band( 40 ), 'Band at 40' );
bd_check( 'Fragile' === BD_Diagnostic_Scoring::maturity_band( 59.9 ), 'Band at 59.9' );
bd_check( 'Developing' === BD_Diagnostic_Scoring::maturity_band( 60 ), 'Band at 60' );
bd_check( 'Developing' === BD_Diagnostic_Scoring::maturity_band( 74.9 ), 'Band at 74.9' );
bd_check( 'Strong' === BD_Diagnostic_Scoring::maturity_band( 75 ), 'Band at 75' );
bd_check( 'Strong' === BD_Diagnostic_Scoring::maturity_band( 100 ), 'Band at 100' );

/* Question bank structure. */
foreach ( array( 'SME360', 'AGRIFOOD360', 'ESO360' ) as $platform ) {
	$bank = $banks[ $platform ];
	bd_check( 100 === count( $bank['questions'] ), "$platform has " . count( $bank['questions'] ) . ' questions, expected 100' );
	bd_check( 8 === count( $bank['dimensions'] ), "$platform has " . count( $bank['dimensions'] ) . ' dimensions, expected 8' );
	$dimset = array_flip( $bank['dimensions'] );
	$codes  = array();
	foreach ( $bank['questions'] as $q ) {
		bd_check( isset( $dimset[ $q['dimension'] ] ), "$platform question {$q['code']} has an undeclared dimension: {$q['dimension']}" );
		bd_check( ! isset( $codes[ $q['code'] ] ), "$platform has a duplicate question code: {$q['code']}" );
		$codes[ $q['code'] ] = true;
		bd_check( isset( $q['weight'] ) && $q['weight'] >= 1 && $q['weight'] <= 5, "$platform question {$q['code']} has an invalid weight" );
		bd_check( isset( $q['anchors'] ) && 5 === count( $q['anchors'] ), "$platform question {$q['code']} does not have 5 anchors" );
	}
}

/* Confidence classification. */
bd_check( 'Low' === BD_Diagnostic_Scoring::confidence_class( array( 'Verified' => 1.0 ), true, false ), 'Confidence: validation gate wins' );
bd_check( 'High' === BD_Diagnostic_Scoring::confidence_class( array( 'Verified' => 0.7 ), true, true ), 'Confidence: high share' );
bd_check( 'Medium' === BD_Diagnostic_Scoring::confidence_class( array( 'Verified' => 0.4 ), true, true ), 'Confidence: medium share' );
bd_check( 'Low' === BD_Diagnostic_Scoring::confidence_class( array( 'Verified' => 0.1 ), true, true ), 'Confidence: low share' );
bd_check( 'Medium' === BD_Diagnostic_Scoring::confidence_class( array( 'Verified' => 0.7 ), false, true ), 'Confidence: failed rule checks demote by one level' );

/* Rule check: a strong claim (3 or 4) with no evidence at all is a contradiction. Port of the check
 * in Samakose AI's computeScore() (src/services/diagnostics.ts). */
bd_check( true === BD_Diagnostic_Scoring::rule_checks_passed( array() ), 'Rule check: no responses passes' );
bd_check( true === BD_Diagnostic_Scoring::rule_checks_passed( array( array( 'value' => 2, 'evidenceClass' => 'Missing' ) ) ), 'Rule check: weak claim with no evidence is fine' );
bd_check( true === BD_Diagnostic_Scoring::rule_checks_passed( array( array( 'value' => 4, 'evidenceClass' => 'Self-reported' ) ) ), 'Rule check: strong claim with some evidence is fine' );
bd_check( false === BD_Diagnostic_Scoring::rule_checks_passed( array( array( 'value' => 3, 'evidenceClass' => 'Missing' ) ) ), 'Rule check: strong claim (3) with no evidence fails' );
bd_check( false === BD_Diagnostic_Scoring::rule_checks_passed( array( array( 'value' => 4, 'evidenceClass' => 'Missing' ) ) ), 'Rule check: strong claim (4) with no evidence fails' );
bd_check( true === BD_Diagnostic_Scoring::rule_checks_passed( array( array( 'value' => 4, 'evidenceClass' => 'Missing', 'notApplicable' => true ) ) ), 'Rule check: not-applicable answers are ignored' );

fwrite( STDOUT, "$checks checks, $fails failed\n" );
exit( $fails ? 1 : 0 );
