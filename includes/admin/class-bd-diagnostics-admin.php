<?php
/**
 * Admin screen for staff to review full (evidence-weighted) assessments business owners have
 * taken: list, and a read-only detail view of one submission with its answers and evidence.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Diagnostics_Admin {

	const CAP = 'manage_business_diagnostics';

	/**
	 * Hooks the admin menu.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Whether the current user may review diagnostics.
	 *
	 * @return bool
	 */
	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Admin menu entry, under Business Doctor.
	 */
	public static function menu() {
		if ( ! self::can() ) {
			return;
		}
		add_submenu_page(
			'business-doctor',
			__( 'Full Assessments', 'business-doctor' ),
			__( 'Full Assessments', 'business-doctor' ),
			self::CAP,
			'bd-diagnostics',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * List, or one diagnostic's detail when action=view.
	 */
	public static function page() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] && ! empty( $_GET['id'] ) ) {
			self::detail( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$table = new BD_Diagnostics_Table();
		$table->prepare_items();
		?>
		<div class="wrap bd-admin">
			<h1><?php esc_html_e( 'Full Assessments', 'business-doctor' ); ?></h1>
			<p class="description"><?php esc_html_e( 'The evidence-weighted assessment business owners take after registering and completing their organisation profile. Unlike the free assessment, every answer here carries an evidence class, and the weighting and scoring match Samakose AI\'s full assessment exactly.', 'business-doctor' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="bd-diagnostics">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Read-only detail: organisation, scores, and every answer with its evidence.
	 *
	 * @param int $id Diagnostic id.
	 */
	private static function detail( $id ) {
		$d = BD_Diagnostics::get( $id );
		if ( ! $d ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Assessment not found.', 'business-doctor' ) . '</p></div>';
			return;
		}
		$org      = BD_Organisations::get( $d['org_id'] );
		$fw       = BD_Frameworks::get( $d['framework_id'] );
		$answers  = BD_Diagnostics::answers( $d['id'] );
		$user     = get_userdata( $d['user_id'] );
		?>
		<div class="wrap bd-admin">
			<h1><?php echo esc_html( $org ? $org['name'] : __( '(no organisation name)', 'business-doctor' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bd-diagnostics' ) ); ?>">&larr; <?php esc_html_e( 'All full assessments', 'business-doctor' ); ?></a></p>
			<?php if ( isset( $_GET['bd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done.', 'business-doctor' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bd_err'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['bd_err'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>
			<div class="bd-columns">
				<div class="bd-panel">
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Owner', 'business-doctor' ); ?></th><td><?php echo $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '&ndash;'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Platform', 'business-doctor' ); ?></th><td><?php echo esc_html( BD_Frameworks::short_name( $d['platform'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Status', 'business-doctor' ); ?></th><td><?php echo esc_html( ucfirst( $d['status'] ) ); ?></td></tr>
						<?php if ( 'submitted' === $d['status'] ) : ?>
							<tr><th><?php esc_html_e( 'Overall score', 'business-doctor' ); ?></th><td><strong><?php echo esc_html( $d['overall_score'] ); ?></strong></td></tr>
							<tr><th><?php esc_html_e( 'Maturity band', 'business-doctor' ); ?></th><td><?php echo esc_html( $d['maturity'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Confidence', 'business-doctor' ); ?></th><td><?php echo esc_html( $d['confidence'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Completion', 'business-doctor' ); ?></th><td><?php echo esc_html( round( $d['completion'] * 100 ) ); ?>%</td></tr>
						<?php endif; ?>
					</table>
				</div>
				<?php if ( 'submitted' === $d['status'] ) : ?>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Dimension scores', 'business-doctor' ); ?></h2>
					<ul class="bd-bars">
						<?php foreach ( $d['dimension_scores'] as $row ) : ?>
							<li><span><?php echo esc_html( $row['dimension'] ); ?></span><span class="bd-bar"><span style="width:<?php echo esc_attr( max( 0, min( 100, (float) $row['value'] ) ) ); ?>%"></span></span><b><?php echo esc_html( $row['value'] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>
			<div class="bd-panel">
				<h2><?php esc_html_e( 'Answers and evidence', 'business-doctor' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Question', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Rating', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Evidence', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Note', 'business-doctor' ); ?></th><th><?php esc_html_e( 'File', 'business-doctor' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( ( $fw ? $fw['questions'] : array() ) as $q ) : ?>
						<?php $a = isset( $answers[ $q['code'] ] ) ? $answers[ $q['code'] ] : null; ?>
						<tr>
							<td><?php echo esc_html( $q['text'] ); ?></td>
							<td><?php echo $a && ! $a['notApplicable'] ? esc_html( $a['value'] ) : ( $a && $a['notApplicable'] ? esc_html__( 'N/A', 'business-doctor' ) : '&ndash;' ); ?></td>
							<td><?php echo $a ? esc_html( $a['evidenceClass'] ) : '&ndash;'; ?></td>
							<td><?php echo $a ? esc_html( $a['evidenceNote'] ) : ''; ?></td>
							<td><?php if ( $a && $a['attachmentId'] && get_post( $a['attachmentId'] ) ) : ?><a href="<?php echo esc_url( wp_get_attachment_url( $a['attachmentId'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View file', 'business-doctor' ); ?></a><?php else : ?>&ndash;<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( 'submitted' === $d['status'] ) : ?>
				<?php self::clinical_panels( $d, $org ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The diagnosis, prescription, action plan and KPI panels: the full Phase 4 clinical workflow
	 * for one submitted, scored assessment. Only shown once scoring has happened, since a
	 * diagnosis is grounded in the diagnostic's final scores and answers.
	 *
	 * @param array      $d   Diagnostic (submitted).
	 * @param array|null $org The diagnostic's organisation.
	 */
	private static function clinical_panels( array $d, $org ) {
		$nonce_field = function () use ( $d ) {
			wp_nonce_field( 'bd_clinical_' . $d['id'] );
		};
		$current_user_id = get_current_user_id();

		$diagnosis = BD_Case_Diagnoses::latest_for_diagnostic( $d['id'] );
		?>
		<div class="bd-panel">
			<h2><?php esc_html_e( 'Diagnosis', 'business-doctor' ); ?></h2>
			<?php if ( ! $diagnosis || 'rejected' === $diagnosis['status'] ) : ?>
				<?php if ( $diagnosis && 'rejected' === $diagnosis['status'] ) : ?>
					<p class="description"><?php esc_html_e( 'The previous draft was rejected:', 'business-doctor' ); ?> <?php echo esc_html( $diagnosis['review_note'] ); ?></p>
				<?php endif; ?>
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_request_ai_diagnosis">
						<input type="hidden" name="diagnostic_id" value="<?php echo esc_attr( $d['id'] ); ?>">
						<?php submit_button( __( 'Ask Claude to draft a diagnosis', 'business-doctor' ), 'primary', 'submit', false, BD_Claude::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<?php if ( ! BD_Claude::is_configured() ) : ?>
						<span class="description"><?php esc_html_e( '(AI drafting is not configured; see Business Doctor settings.)', 'business-doctor' ); ?></span>
					<?php endif; ?>
				</p>
				<details>
					<summary><?php esc_html_e( 'Or write one manually', 'business-doctor' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_create_diagnosis">
						<input type="hidden" name="diagnostic_id" value="<?php echo esc_attr( $d['id'] ); ?>">
						<table class="form-table" role="presentation">
							<tr><th><?php esc_html_e( 'Summary', 'business-doctor' ); ?></th><td><textarea name="summary" rows="3" class="large-text" required></textarea></td></tr>
							<tr><th><?php esc_html_e( 'Priority', 'business-doctor' ); ?></th><td>
								<select name="priority"><option value="High">High</option><option value="Medium">Medium</option><option value="Low">Low</option></select>
							</td></tr>
							<tr><th><?php esc_html_e( 'Root causes (JSON)', 'business-doctor' ); ?></th><td><textarea name="root_causes_json" rows="4" class="large-text code" placeholder='[{"cause": "...", "evidence_codes": ["SM-001"]}]'></textarea><p class="description"><?php esc_html_e( 'evidence_codes must be question codes answered on this assessment.', 'business-doctor' ); ?></p></td></tr>
							<tr><th><?php esc_html_e( 'Risks (JSON)', 'business-doctor' ); ?></th><td><textarea name="risks_json" rows="3" class="large-text code" placeholder='[{"text": "...", "severity": "Medium"}]'></textarea></td></tr>
							<tr><th><?php esc_html_e( 'Model confidence (optional)', 'business-doctor' ); ?></th><td><input type="number" name="model_confidence" step="0.01" min="0" max="1"></td></tr>
						</table>
						<?php submit_button( __( 'Save diagnosis draft', 'business-doctor' ) ); ?>
					</form>
				</details>
			<?php elseif ( 'draft' === $diagnosis['status'] ) : ?>
				<p><?php echo $diagnosis['ai_drafted'] ? esc_html__( 'AI-drafted, awaiting review.', 'business-doctor' ) : esc_html__( 'Drafted by staff, awaiting review.', 'business-doctor' ); ?></p>
				<p><strong><?php esc_html_e( 'Summary:', 'business-doctor' ); ?></strong> <?php echo esc_html( $diagnosis['summary'] ); ?></p>
				<p><strong><?php esc_html_e( 'Priority:', 'business-doctor' ); ?></strong> <?php echo esc_html( $diagnosis['priority'] ); ?></p>
				<ul>
					<?php foreach ( $diagnosis['root_causes'] as $rc ) : ?>
						<li><?php echo esc_html( $rc['cause'] ?? '' ); ?> <em>(<?php echo esc_html( implode( ', ', $rc['evidence_codes'] ?? array() ) ); ?>)</em></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( (int) $current_user_id === (int) $diagnosis['created_by'] ) : ?>
					<p class="description"><?php esc_html_e( 'You drafted this diagnosis, so another member of staff must review it (four-eyes).', 'business-doctor' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_review_diagnosis">
						<input type="hidden" name="diagnosis_id" value="<?php echo esc_attr( $diagnosis['id'] ); ?>">
						<p><textarea name="note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Note (required if rejecting)', 'business-doctor' ); ?>"></textarea></p>
						<?php submit_button( __( 'Approve', 'business-doctor' ), 'primary', 'decision_approve', false, array( 'name' => 'decision', 'value' => 'approve' ) ); ?>
						<?php submit_button( __( 'Reject', 'business-doctor' ), 'secondary', 'decision_reject', false, array( 'name' => 'decision', 'value' => 'reject' ) ); ?>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Reviewed.', 'business-doctor' ); ?></strong> <?php echo esc_html( $diagnosis['summary'] ); ?></p>
				<p><strong><?php esc_html_e( 'Priority:', 'business-doctor' ); ?></strong> <?php echo esc_html( $diagnosis['priority'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		if ( ! $diagnosis || 'reviewed' !== $diagnosis['status'] ) {
			return;
		}
		$prescription = BD_Prescriptions::latest_for_diagnosis( $diagnosis['id'] );
		?>
		<div class="bd-panel">
			<h2><?php esc_html_e( 'Prescription', 'business-doctor' ); ?></h2>
			<?php if ( ! $prescription || 'returned' === $prescription['status'] ) : ?>
				<?php if ( $prescription && 'returned' === $prescription['status'] ) : ?>
					<p class="description"><?php esc_html_e( 'The previous draft was returned:', 'business-doctor' ); ?> <?php echo esc_html( $prescription['reviewer_note'] ); ?></p>
				<?php endif; ?>
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_request_ai_prescription">
						<input type="hidden" name="diagnosis_id" value="<?php echo esc_attr( $diagnosis['id'] ); ?>">
						<?php submit_button( __( 'Ask Claude to draft a prescription', 'business-doctor' ), 'primary', 'submit', false, BD_Claude::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</p>
				<details>
					<summary><?php esc_html_e( 'Or write one manually', 'business-doctor' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_create_prescription">
						<input type="hidden" name="diagnosis_id" value="<?php echo esc_attr( $diagnosis['id'] ); ?>">
						<p class="description"><?php esc_html_e( 'A JSON array of items, each with a title, a dimension from this platform, a description, a kpi_name, and actions.', 'business-doctor' ); ?></p>
						<textarea name="items_json" rows="8" class="large-text code" placeholder='[{"title": "...", "dimension": "...", "description": "...", "kpi_name": "...", "actions": [{"text": "...", "owner_role": "owner", "deadline_days": 30}]}]'></textarea>
						<?php submit_button( __( 'Save prescription draft', 'business-doctor' ) ); ?>
					</form>
				</details>
			<?php elseif ( 'draft' === $prescription['status'] ) : ?>
				<p><?php esc_html_e( 'Draft, not yet submitted for review.', 'business-doctor' ); ?></p>
				<?php self::render_items( $prescription['items'] ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $nonce_field(); ?>
					<input type="hidden" name="action" value="bd_submit_prescription">
					<input type="hidden" name="prescription_id" value="<?php echo esc_attr( $prescription['id'] ); ?>">
					<?php submit_button( __( 'Submit for review', 'business-doctor' ) ); ?>
				</form>
			<?php elseif ( 'in_review' === $prescription['status'] ) : ?>
				<?php self::render_items( $prescription['items'] ); ?>
				<?php if ( (int) $current_user_id === (int) $prescription['created_by'] ) : ?>
					<p class="description"><?php esc_html_e( 'You drafted this prescription, so another member of staff must review it (four-eyes).', 'business-doctor' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $nonce_field(); ?>
						<input type="hidden" name="action" value="bd_review_prescription">
						<input type="hidden" name="prescription_id" value="<?php echo esc_attr( $prescription['id'] ); ?>">
						<p><textarea name="note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Note (required if returning)', 'business-doctor' ); ?>"></textarea></p>
						<?php submit_button( __( 'Approve', 'business-doctor' ), 'primary', 'decision_approve', false, array( 'name' => 'decision', 'value' => 'approve' ) ); ?>
						<?php submit_button( __( 'Return', 'business-doctor' ), 'secondary', 'decision_return', false, array( 'name' => 'decision', 'value' => 'return' ) ); ?>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Approved.', 'business-doctor' ); ?></strong></p>
				<?php self::render_items( $prescription['items'] ); ?>
			<?php endif; ?>
		</div>
		<?php
		if ( ! $prescription || 'approved' !== $prescription['status'] || ! $org ) {
			return;
		}
		$actions = BD_Actions::for_org( $org['id'] );
		$kpis    = BD_Kpis::for_org( $org['id'] );
		?>
		<div class="bd-panel">
			<h2><?php esc_html_e( 'Action plan', 'business-doctor' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Action', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Owner', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Due', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Status', 'business-doctor' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $actions as $a ) : ?>
					<tr>
						<td><?php echo esc_html( $a['text'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $a['owner_role'] ) ); ?></td>
						<td><?php echo esc_html( $a['due_date'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $a['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $actions ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No actions yet.', 'business-doctor' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="bd-panel">
			<h2><?php esc_html_e( 'KPIs', 'business-doctor' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'KPI', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Latest check-in', 'business-doctor' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $kpis as $k ) : ?>
					<tr>
						<td><?php echo esc_html( $k['name'] ); ?></td>
						<td><?php echo $k['checkins'] ? esc_html( $k['checkins'][0]['value'] . ' (' . $k['checkins'][0]['created_at'] . ')' ) : '&ndash;'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $kpis ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No KPIs yet.', 'business-doctor' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Owners log their own check-ins from the assessment plan on the front end.', 'business-doctor' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders a prescription's items as a read-only list.
	 *
	 * @param array $items Prescription items.
	 */
	private static function render_items( array $items ) {
		?>
		<ul>
			<?php foreach ( $items as $item ) : ?>
				<li>
					<strong><?php echo esc_html( $item['title'] ?? '' ); ?></strong>
					<em>(<?php echo esc_html( $item['dimension'] ?? '' ); ?>)</em>
					<?php if ( ! empty( $item['description'] ) ) : ?><br><?php echo esc_html( $item['description'] ); ?><?php endif; ?>
					<ul>
						<?php foreach ( (array) ( $item['actions'] ?? array() ) as $a ) : ?>
							<li><?php echo esc_html( $a['text'] ?? '' ); ?> &mdash; <?php echo esc_html( ucfirst( $a['owner_role'] ?? '' ) ); ?>, <?php echo esc_html( $a['deadline_days'] ?? '' ); ?> <?php esc_html_e( 'days', 'business-doctor' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
