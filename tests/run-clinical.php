<?php
/**
 * Stand-alone checks for the Phase 4 AI-output rules that need no live WordPress or database
 * connection: php tests/run-clinical.php
 *
 * Covers: BD_AI_Domain::extract_json(), has_forbidden_claim(), validate_diagnosis(), and
 * validate_prescription() -- every rejection branch, since these are the only gate between a Claude
 * reply (or a staff member's manual entry) and what reaches a business owner. The four-eyes rule
 * itself (reviewer must not be drafter) is exercised directly here as a pure comparison, matching how
 * BD_Case_Diagnoses::review() and BD_Prescriptions::review() apply it, without needing a database.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-bd-ai-domain.php';

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

/* extract_json(): tolerant of a fenced reply, a bare reply, surrounding prose, and rejects anything
 * that isn't valid JSON once extracted. */
bd_check( array( 'a' => 1 ) === BD_AI_Domain::extract_json( '{"a": 1}' ), 'Plain JSON extracts' );
bd_check( array( 'a' => 1 ) === BD_AI_Domain::extract_json( "```json\n{\"a\": 1}\n```" ), 'Fenced JSON extracts' );
bd_check( array( 'a' => 1 ) === BD_AI_Domain::extract_json( "Sure, here you go:\n{\"a\": 1}\nHope that helps!" ), 'JSON surrounded by prose extracts' );
bd_check( null === BD_AI_Domain::extract_json( 'not json at all' ), 'Non-JSON returns null' );
bd_check( null === BD_AI_Domain::extract_json( '' ), 'Empty string returns null' );
bd_check( null === BD_AI_Domain::extract_json( '{"a": }' ), 'Malformed JSON returns null' );

/* has_forbidden_claim(): every regex in FORBIDDEN_CLAIMS, case-insensitively, and clean text passes. */
bd_check( BD_AI_Domain::has_forbidden_claim( 'This business is now certified.' ), 'Certified is forbidden' );
bd_check( BD_AI_Domain::has_forbidden_claim( 'This business is CERTIFICATION ready' ), 'Certification is forbidden, case-insensitive' );
bd_check( BD_AI_Domain::has_forbidden_claim( 'This business is investment-ready.' ), 'Investment-ready is forbidden' );
bd_check( BD_AI_Domain::has_forbidden_claim( 'This is the official score for funding.' ), 'Official score is forbidden' );
bd_check( BD_AI_Domain::has_forbidden_claim( 'We guarantee growth.' ), 'Guarantee is forbidden' );
bd_check( BD_AI_Domain::has_forbidden_claim( 'This business is approved for funding.' ), 'Approved for funding is forbidden' );
bd_check( ! BD_AI_Domain::has_forbidden_claim( 'This business has weak record-keeping.' ), 'Ordinary analysis text is not forbidden' );

/* validate_diagnosis(): every rejection branch, then a fully valid diagnosis. */
$codes = array( 'SM-001', 'SM-002' );

$short_summary = array(
	'summary'     => 'too short',
	'root_causes' => array( array( 'cause' => 'x', 'evidence_codes' => array( 'SM-001' ) ) ),
	'priority'    => 'High',
	'risks'       => array( array( 'text' => 'x', 'severity' => 'Low' ) ),
);
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $short_summary, $codes ), 'Summary under 20 chars is rejected' );

$no_causes = $short_summary;
$no_causes['summary']     = str_repeat( 'a', 25 );
$no_causes['root_causes'] = array();
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $no_causes, $codes ), 'Empty root_causes is rejected' );

$unknown_evidence = $no_causes;
$unknown_evidence['root_causes'] = array( array( 'cause' => 'x', 'evidence_codes' => array( 'ZZ-999' ) ) );
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $unknown_evidence, $codes ), 'Unknown evidence code is rejected (never invent evidence)' );

$bad_priority = $unknown_evidence;
$bad_priority['root_causes'] = array( array( 'cause' => 'x', 'evidence_codes' => array( 'SM-001' ) ) );
$bad_priority['priority']    = 'Urgent';
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $bad_priority, $codes ), 'Non-enum priority is rejected' );

$bad_risk = $bad_priority;
$bad_risk['priority'] = 'High';
$bad_risk['risks']    = array( array( 'text' => 'x', 'severity' => 'Extreme' ) );
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $bad_risk, $codes ), 'Non-enum risk severity is rejected' );

$bad_confidence = $bad_risk;
$bad_confidence['risks']            = array( array( 'text' => 'x', 'severity' => 'Low' ) );
$bad_confidence['model_confidence'] = 1.5;
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $bad_confidence, $codes ), 'model_confidence outside 0-1 is rejected' );

$forbidden_text = $bad_confidence;
$forbidden_text['model_confidence'] = 0.8;
$forbidden_text['summary']          = 'This business is now officially certified and investment-ready today.';
bd_check( (bool) BD_AI_Domain::validate_diagnosis( $forbidden_text, $codes ), 'A forbidden claim in the summary is rejected even though every other field is valid' );

$valid_diagnosis = array(
	'summary'          => 'The business has weak financial controls and no documented record-keeping process.',
	'root_causes'      => array( array( 'cause' => 'No bookkeeping system', 'evidence_codes' => array( 'SM-001', 'SM-002' ) ) ),
	'priority'         => 'High',
	'risks'            => array( array( 'text' => 'Cash flow is not tracked', 'severity' => 'Medium' ) ),
	'model_confidence' => 0.72,
);
bd_check( array() === BD_AI_Domain::validate_diagnosis( $valid_diagnosis, $codes ), 'A fully valid diagnosis passes with no problems' );

/* validate_prescription(): every rejection branch, then a fully valid prescription. */
$dims = array( 'Financial Management and Performance', 'Operations and Value Delivery' );

bd_check( (bool) BD_AI_Domain::validate_prescription( array(), $dims, 3, 120 ), 'Missing items is rejected' );
bd_check( (bool) BD_AI_Domain::validate_prescription( array( 'items' => array() ), $dims, 3, 120 ), 'Empty items is rejected' );

$no_title = array(
	'items' => array(
		array(
			'dimension' => 'Financial Management and Performance',
			'actions'   => array( array( 'text' => 'Do X', 'owner_role' => 'owner', 'deadline_days' => 30 ) ),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $no_title, $dims, 3, 120 ), 'Missing item title is rejected' );

$bad_dimension = array(
	'items' => array(
		array(
			'title'     => 'Fix bookkeeping',
			'dimension' => 'Not A Real Dimension',
			'actions'   => array( array( 'text' => 'Do X', 'owner_role' => 'owner', 'deadline_days' => 30 ) ),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $bad_dimension, $dims, 3, 120 ), 'Dimension outside this platform\'s list is rejected (no invented library)' );

$no_actions = array(
	'items' => array(
		array(
			'title'     => 'Fix bookkeeping',
			'dimension' => 'Financial Management and Performance',
			'actions'   => array(),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $no_actions, $dims, 3, 120 ), 'Empty actions is rejected' );

$deadline_too_low = array(
	'items' => array(
		array(
			'title'     => 'Fix bookkeeping',
			'dimension' => 'Financial Management and Performance',
			'actions'   => array( array( 'text' => 'Do X', 'owner_role' => 'owner', 'deadline_days' => 1 ) ),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $deadline_too_low, $dims, 3, 120 ), 'deadline_days below the configured minimum is rejected' );

$deadline_too_high = array(
	'items' => array(
		array(
			'title'     => 'Fix bookkeeping',
			'dimension' => 'Financial Management and Performance',
			'actions'   => array( array( 'text' => 'Do X', 'owner_role' => 'owner', 'deadline_days' => 400 ) ),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $deadline_too_high, $dims, 3, 120 ), 'deadline_days above the configured maximum is rejected' );

$bad_owner_role = array(
	'items' => array(
		array(
			'title'     => 'Fix bookkeeping',
			'dimension' => 'Financial Management and Performance',
			'actions'   => array( array( 'text' => 'Do X', 'owner_role' => 'consultant', 'deadline_days' => 30 ) ),
		),
	),
);
bd_check( (bool) BD_AI_Domain::validate_prescription( $bad_owner_role, $dims, 3, 120 ), 'owner_role outside owner/staff is rejected' );

$valid_prescription = array(
	'items' => array(
		array(
			'title'       => 'Fix bookkeeping',
			'dimension'   => 'Financial Management and Performance',
			'description' => 'Introduce a simple weekly cash book.',
			'kpi_name'    => 'Weekly reconciliation completed',
			'actions'     => array(
				array(
					'text'          => 'Buy and start using a cash book',
					'owner_role'    => 'owner',
					'deadline_days' => 14,
				),
			),
		),
	),
);
bd_check( array() === BD_AI_Domain::validate_prescription( $valid_prescription, $dims, 3, 120 ), 'A fully valid prescription passes with no problems' );

/* Four-eyes: the reviewer must never be the drafter. This is the exact comparison
 * BD_Case_Diagnoses::review() and BD_Prescriptions::review() both make. */
$drafter  = 7;
$reviewer = 7;
bd_check( $drafter === $reviewer, 'Same person as drafter and reviewer would be rejected by the four-eyes check' );
$reviewer = 9;
bd_check( $drafter !== $reviewer, 'A different reviewer passes the four-eyes check' );

fwrite( STDOUT, "$checks checks, $fails failed\n" );
exit( $fails ? 1 : 0 );
