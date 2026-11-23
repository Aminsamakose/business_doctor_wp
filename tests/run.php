<?php
/**
 * Stand-alone checks that need no WordPress install:  php tests/run.php
 *
 * 1. Scoring parity: 912 answer sets scored by the original Samakose AI TypeScript scorer
 *    (tests/fixtures/scoring-cases.json) must score identically in PHP.
 * 2. Band boundaries.
 * 3. Question banks: 25 questions per platform, 6 dimensions, four answers each.
 * 4. Invalid input is rejected.
 *
 * @package BusinessDoctor
 */

define( 'BD_TESTING', true );
require __DIR__ . '/../includes/class-bd-scoring.php';

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

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/scoring-cases.json' ), true );
$banks   = json_decode( file_get_contents( __DIR__ . '/../data/frameworks.json' ), true );

foreach ( $fixture['cases'] as $i => $c ) {
	$qs  = $banks[ $c['platform'] ]['questions'];
	$got = BD_Scoring::score( $c['answers'], $qs );
	bd_check( $got['overall'] === (float) $c['expected']['overall'], "case $i overall {$got['overall']} vs {$c['expected']['overall']}" );
	bd_check( $got['classification'] === $c['expected']['classification'], "case $i band {$got['classification']} vs {$c['expected']['classification']}" );
	foreach ( $c['expected']['dimensions'] as $k => $d ) {
		bd_check( $got['dimensions'][ $k ]['dimension'] === $d['dimension'] && $got['dimensions'][ $k ]['score'] === (float) $d['score'], "case $i dimension $k" );
	}
	$f = BD_Scoring::findings( $got['dimensions'] );
	bd_check( $f['strengths'] === $c['expected']['strengths'], "case $i strengths" );
	bd_check( $f['priorities'] === $c['expected']['priorities'], "case $i priorities" );
}

foreach ( $fixture['bands'] as $b ) {
	bd_check( BD_Scoring::classify( $b['score'] ) === $b['band'], "band at {$b['score']}" );
}

foreach ( $banks as $p => $fw ) {
	bd_check( 25 === count( $fw['questions'] ), "$p has 25 questions" );
	bd_check( 6 === count( $fw['dimensions'] ), "$p has 6 dimensions" );
	foreach ( $fw['questions'] as $q ) {
		bd_check( 4 === count( array_filter( $q['options'] ) ), "$p {$q['code']} has four answers" );
		bd_check( in_array( $q['dimension'], $fw['dimensions'], true ), "$p {$q['code']} dimension is listed" );
	}
}

$qs = $banks['SME360']['questions'];
$ok = array_fill_keys( array_column( $qs, 'code' ), 'A' );
foreach ( array( 'missing' => array_slice( $ok, 1, null, true ), 'bad letter' => array_merge( $ok, array( 'SME_Q01' => 'E' ) ) ) as $label => $answers ) {
	try {
		BD_Scoring::score( $answers, $qs );
		bd_check( false, "rejects $label answers" );
	} catch ( InvalidArgumentException $e ) {
		bd_check( true, '' );
	}
}
bd_check( 100.0 === BD_Scoring::score( $ok, $qs )['overall'], 'all A scores 100' );
bd_check( 25.0 === BD_Scoring::score( array_fill_keys( array_column( $qs, 'code' ), 'd' ), $qs )['overall'], 'lower-case d accepted, all D scores 25' );

printf( "%d checks, %d failed\n", $checks, $fails );
exit( $fails ? 1 : 0 );
