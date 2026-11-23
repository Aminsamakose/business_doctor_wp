<?php
/**
 * Rules governing an AI-drafted diagnosis or prescription: the prompts, pulling a JSON object out of
 * a reply that may wrap it in prose or a code fence, the claims an AI draft may never make, and the
 * validators every diagnosis/prescription passes through whether it was drafted by AI or typed by
 * staff. Direct port of the AI-output section of Samakose AI's src/domain/logic.ts and the
 * diagnosis/prescription prompts in src/domain/prompts.ts, adapted to this plugin's shape: a root
 * cause's "evidence" is the full assessment's own answered questions (there is no separate evidence
 * table here), and a prescription item is freeform rather than drawn from a fixed intervention
 * library (this plugin has none yet), grounded instead to one of the platform's own dimensions.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_AI_Domain {

	/**
	 * Claims an AI draft (or a human one -- the check is the same either way) may never make.
	 * Certification and consequential decisions need a human governance gate, not a model's say-so.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_CLAIMS = array(
		'/\bcertif(y|ied|ies|ication)\b/i',
		'/investment[- ]ready/i',
		'/official score/i',
		'/guarantee/i',
		'/approved for funding/i',
	);

	/**
	 * Whether any forbidden claim appears in the text.
	 *
	 * @param string $text Text to check.
	 * @return bool
	 */
	public static function has_forbidden_claim( $text ) {
		foreach ( self::FORBIDDEN_CLAIMS as $re ) {
			if ( preg_match( $re, (string) $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pulls the first JSON object out of a reply, tolerating a ```json code fence or surrounding
	 * prose, the same tolerant extraction as the original's extractJson().
	 *
	 * @param string $text Raw model reply.
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		if ( ! $text ) {
			return null;
		}
		$t = trim( (string) $text );
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $t, $m ) ) {
			$t = trim( $m[1] );
		}
		$a = strpos( $t, '{' );
		$b = strrpos( $t, '}' );
		if ( false === $a || false === $b || $b < $a ) {
			return null;
		}
		$decoded = json_decode( substr( $t, $a, $b - $a + 1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The diagnosis agent's system prompt.
	 *
	 * @param array $fw Published full framework (for its dimension list).
	 * @return string
	 */
	public static function diagnosis_system_prompt( array $fw ) {
		$dims = implode( ', ', $fw['dimensions'] );
		return 'You are the diagnostic analyst for a business advisory firm in Ghana. You receive scored, evidence-weighted assessment data for one business, across these dimensions: ' . $dims . '. Explain the weakest areas and their root causes using ONLY the question codes given as evidence. Never invent a question code, figure or fact that is not in the data given. Reply with JSON only, no commentary: {"summary": string (at least 20 words), "root_causes": [{"cause": string, "evidence_codes": [string, question codes from the data given]}], "priority": "High"|"Medium"|"Low", "risks": [{"text": string, "severity": "High"|"Medium"|"Low"}], "model_confidence": number between 0 and 1}.';
	}

	/**
	 * The prescription agent's system prompt.
	 *
	 * @param array $fw Published full framework (for its dimension list).
	 * @param int   $min_days Minimum allowed deadline, in days.
	 * @param int   $max_days Maximum allowed deadline, in days.
	 * @return string
	 */
	public static function prescription_system_prompt( array $fw, $min_days, $max_days ) {
		$dims = implode( ', ', $fw['dimensions'] );
		return 'You are a business advisor. Given an approved diagnosis for one business, write a prescription: a short, prioritised list of interventions, each tied to one of these dimensions: ' . $dims . '. Each intervention needs concrete actions for a small business in Northern Ghana, each with a named owner. Reply with JSON only, no commentary: {"items": [{"title": string under 100 characters, "dimension": string (one of the dimensions given), "description": string, "actions": [{"text": string, "owner_role": "owner"|"staff", "deadline_days": integer between ' . (int) $min_days . ' and ' . (int) $max_days . '}], "kpi_name": string or null (one measurable indicator to track progress on this intervention)}]}.';
	}

	/**
	 * Validates a diagnosis, whether AI-drafted or typed by staff. Direct port of validateDiagnosis().
	 *
	 * @param array $o              Candidate diagnosis.
	 * @param array $evidence_codes Question codes actually answered on this diagnostic.
	 * @return string[] Problems; empty if valid.
	 */
	public static function validate_diagnosis( $o, array $evidence_codes ) {
		$p = array();
		if ( ! is_array( $o ) ) {
			return array( 'Not an object' );
		}
		if ( ! isset( $o['summary'] ) || ! is_string( $o['summary'] ) || strlen( $o['summary'] ) < 20 ) {
			$p[] = 'summary missing or too short';
		}
		if ( empty( $o['root_causes'] ) || ! is_array( $o['root_causes'] ) ) {
			$p[] = 'root_causes missing';
		} else {
			foreach ( $o['root_causes'] as $i => $rc ) {
				if ( empty( $rc['cause'] ) ) {
					$p[] = "root_causes[$i].cause missing";
				}
				if ( empty( $rc['evidence_codes'] ) || ! is_array( $rc['evidence_codes'] ) ) {
					$p[] = "root_causes[$i] has no evidence_codes";
				} else {
					foreach ( $rc['evidence_codes'] as $code ) {
						if ( ! in_array( $code, $evidence_codes, true ) ) {
							$p[] = "Unknown evidence code $code";
						}
					}
				}
			}
		}
		if ( empty( $o['priority'] ) || ! in_array( $o['priority'], array( 'High', 'Medium', 'Low' ), true ) ) {
			$p[] = 'priority must be High, Medium or Low';
		}
		if ( ! isset( $o['risks'] ) || ! is_array( $o['risks'] ) ) {
			$p[] = 'risks must be a list';
		} else {
			foreach ( $o['risks'] as $i => $r ) {
				if ( empty( $r['text'] ) || empty( $r['severity'] ) || ! in_array( $r['severity'], array( 'High', 'Medium', 'Low' ), true ) ) {
					$p[] = "risks[$i] invalid";
				}
			}
		}
		if ( isset( $o['model_confidence'] ) && ( ! is_numeric( $o['model_confidence'] ) || $o['model_confidence'] < 0 || $o['model_confidence'] > 1 ) ) {
			$p[] = 'model_confidence must be 0 to 1';
		}
		$all = array( $o['summary'] ?? '' );
		foreach ( (array) ( $o['root_causes'] ?? array() ) as $rc ) {
			$all[] = $rc['cause'] ?? '';
		}
		foreach ( (array) ( $o['risks'] ?? array() ) as $r ) {
			$all[] = $r['text'] ?? '';
		}
		if ( self::has_forbidden_claim( implode( ' ', $all ) ) ) {
			$p[] = 'The draft makes a certification, guarantee or readiness claim. Drafts may analyse and recommend only';
		}
		return $p;
	}

	/**
	 * Validates a prescription, whether AI-drafted or typed by staff. Direct port of
	 * validatePrescription(), adapted to freeform items grounded to the platform's own dimensions
	 * rather than a fixed intervention library.
	 *
	 * @param array $o         Candidate prescription.
	 * @param array $dimensions The platform's dimension names.
	 * @param int   $min_days  Minimum allowed deadline.
	 * @param int   $max_days  Maximum allowed deadline.
	 * @return string[] Problems; empty if valid.
	 */
	public static function validate_prescription( $o, array $dimensions, $min_days, $max_days ) {
		if ( empty( $o['items'] ) || ! is_array( $o['items'] ) ) {
			return array( 'items missing' );
		}
		$p = array();
		foreach ( $o['items'] as $i => $it ) {
			if ( empty( $it['title'] ) ) {
				$p[] = "items[$i].title missing";
			}
			if ( empty( $it['dimension'] ) || ! in_array( $it['dimension'], $dimensions, true ) ) {
				$p[] = "items[$i].dimension must be one of this platform's dimensions";
			}
			if ( empty( $it['actions'] ) || ! is_array( $it['actions'] ) ) {
				$p[] = "items[$i] has no actions";
				continue;
			}
			foreach ( $it['actions'] as $j => $a ) {
				if ( empty( $a['text'] ) ) {
					$p[] = "items[$i].actions[$j].text missing";
				}
				$d = isset( $a['deadline_days'] ) ? (int) $a['deadline_days'] : 0;
				if ( $d < $min_days || $d > $max_days || (string) $d !== (string) ( $a['deadline_days'] ?? '' ) ) {
					$p[] = "items[$i].actions[$j].deadline_days outside $min_days to $max_days";
				}
				if ( empty( $a['owner_role'] ) || ! in_array( $a['owner_role'], array( 'owner', 'staff' ), true ) ) {
					$p[] = "items[$i].actions[$j].owner_role invalid";
				}
			}
		}
		return $p;
	}
}
