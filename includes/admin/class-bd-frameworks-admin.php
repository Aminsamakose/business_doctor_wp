<?php
/**
 * Question Banks screen: view every version, copy the live version into a draft, edit the draft,
 * publish it. Published and retired versions are read-only, so past results always match the
 * questions they were scored against.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Frameworks_Admin {

	/**
	 * Hooks form handlers.
	 */
	public static function init() {
		add_action( 'admin_post_bd_fw_draft', array( __CLASS__, 'handle_draft' ) );
		add_action( 'admin_post_bd_fw_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bd_fw_publish', array( __CLASS__, 'handle_publish' ) );
		add_action( 'admin_post_bd_fw_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Permission and nonce check for a form handler.
	 *
	 * @param string $action Nonce action.
	 * @return int Framework id.
	 */
	private static function guard( $action ) {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! BD_Admin::can() || ! check_admin_referer( $action . '_' . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'business-doctor' ) );
		}
		return $id;
	}

	/**
	 * Redirects back to the screen with a message.
	 *
	 * @param array         $args  Query args.
	 * @param WP_Error|null $error Error to show.
	 */
	private static function back( array $args, $error = null ) {
		if ( $error instanceof WP_Error ) {
			$args['bd_msg'] = 'error';
			$args['bd_err'] = rawurlencode( $error->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=bd-frameworks' ) ) );
		exit;
	}

	/**
	 * Creates a draft from a version.
	 */
	public static function handle_draft() {
		$id    = self::guard( 'bd_fw_draft' );
		$draft = BD_Frameworks::create_draft( $id );
		if ( is_wp_error( $draft ) ) {
			self::back( array(), $draft );
		}
		self::back(
			array(
				'edit'   => $draft,
				'bd_msg' => 'draft',
			)
		);
	}

	/**
	 * Saves a draft.
	 */
	public static function handle_save() {
		$id   = self::guard( 'bd_fw_save' );
		$rows = isset( $_POST['q'] ) && is_array( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		$questions = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$q = array(
				'code'      => bd_sanitize_code( isset( $row['code'] ) ? $row['code'] : '' ),
				'dimension' => sanitize_text_field( isset( $row['dimension'] ) ? $row['dimension'] : '' ),
				'text'      => sanitize_textarea_field( isset( $row['text'] ) ? $row['text'] : '' ),
				'options'   => array(),
			);
			foreach ( array( 'A', 'B', 'C', 'D' ) as $l ) {
				$q['options'][ $l ] = sanitize_textarea_field( isset( $row[ $l ] ) ? $row[ $l ] : '' );
			}
			// A completely blank "new question" row is ignored rather than rejected.
			if ( '' === $q['code'] && '' === $q['text'] && '' === $q['dimension'] && '' === implode( '', $q['options'] ) ) {
				continue;
			}
			$q['weight'] = 1;
			$questions[] = $q;
		}
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$audience = isset( $_POST['audience'] ) ? sanitize_textarea_field( wp_unslash( $_POST['audience'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$ok       = BD_Frameworks::save_draft( $id, $name, $audience, $questions, $note );
		self::back( array( 'edit' => $id ) + ( is_wp_error( $ok ) ? array() : array( 'bd_msg' => 'saved' ) ), is_wp_error( $ok ) ? $ok : null );
	}

	/**
	 * Publishes a draft.
	 */
	public static function handle_publish() {
		$id = self::guard( 'bd_fw_publish' );
		$ok = BD_Frameworks::publish( $id );
		self::back( is_wp_error( $ok ) ? array( 'edit' => $id ) : array( 'bd_msg' => 'published' ), is_wp_error( $ok ) ? $ok : null );
	}

	/**
	 * Deletes a draft.
	 */
	public static function handle_delete() {
		$id = self::guard( 'bd_fw_delete' );
		$ok = BD_Frameworks::delete_draft( $id );
		self::back( array( 'bd_msg' => 'deleted' ), is_wp_error( $ok ) ? $ok : null );
	}

	/**
	 * Number of results per framework version id.
	 *
	 * @return array<int, int>
	 */
	private static function result_counts() {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT framework_id, COUNT(*) n FROM {$t['results']} GROUP BY framework_id", ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['framework_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * A small POST button.
	 *
	 * @param string $action  admin-post action.
	 * @param int    $id      Framework id.
	 * @param string $label   Button text.
	 * @param string $class   Button class.
	 * @param string $confirm Confirmation text, if any.
	 */
	private static function button( $action, $id, $label, $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bd-inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '' ) . '>';
		wp_nonce_field( $action . '_' . $id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! BD_Admin::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		echo '<div class="wrap bd-admin">';
		if ( $edit || $view ) {
			$f = BD_Frameworks::get( $edit ? $edit : $view );
			if ( $f ) {
				self::detail( $f );
				echo '</div>';
				return;
			}
		}
		echo '<h1>' . esc_html__( 'Question Banks', 'business-doctor' ) . '</h1>';
		self::notice();
		echo '<p class="description">' . esc_html__( 'Each platform has one live (published) version. To change questions, create a draft, edit it, then publish. Earlier results keep the version they were taken under.', 'business-doctor' ) . '</p>';
		$counts = self::result_counts();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Platform', 'business-doctor' ) . '</th><th>' . esc_html__( 'Type', 'business-doctor' ) . '</th><th>' . esc_html__( 'Version', 'business-doctor' ) . '</th><th>' . esc_html__( 'Status', 'business-doctor' ) . '</th><th>' . esc_html__( 'Questions', 'business-doctor' ) . '</th><th>' . esc_html__( 'Results', 'business-doctor' ) . '</th><th>' . esc_html__( 'Actions', 'business-doctor' ) . '</th></tr></thead><tbody>';
		foreach ( BD_Frameworks::all_versions() as $f ) {
			echo '<tr><td><strong>' . esc_html( BD_Frameworks::short_name( $f['platform'] ) ) . '</strong><br><span class="description">' . esc_html( $f['name'] ) . '</span></td>';
			echo '<td>' . ( 'full' === $f['purpose'] ? esc_html__( 'Full assessment', 'business-doctor' ) : esc_html__( 'Free assessment', 'business-doctor' ) ) . '</td>';
			echo '<td>' . esc_html( $f['version'] ) . '</td>';
			echo '<td><span class="bd-status bd-fw-' . esc_attr( $f['status'] ) . '">' . esc_html( ucfirst( $f['status'] ) ) . '</span></td>';
			echo '<td>' . esc_html( count( $f['questions'] ) ) . ' / ' . esc_html( count( $f['dimensions'] ) ) . ' ' . esc_html__( 'dimensions', 'business-doctor' ) . '</td>';
			echo '<td>' . esc_html( isset( $counts[ $f['id'] ] ) ? $counts[ $f['id'] ] : 0 ) . '</td><td>';
			if ( 'draft' === $f['status'] ) {
				echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=bd-frameworks&edit=' . $f['id'] ) ) . '">' . esc_html__( 'Edit draft', 'business-doctor' ) . '</a> ';
			} else {
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=bd-frameworks&view=' . $f['id'] ) ) . '">' . esc_html__( 'View', 'business-doctor' ) . '</a> ';
			}
			if ( 'published' === $f['status'] && 'free' === $f['purpose'] ) {
				self::button( 'bd_fw_draft', $f['id'], __( 'Create draft from this', 'business-doctor' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Messages after a redirect.
	 */
	private static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['bd_msg'] ) ) {
			return;
		}
		$msg = sanitize_key( wp_unslash( $_GET['bd_msg'] ) );
		$map = array(
			'saved'     => __( 'Draft saved.', 'business-doctor' ),
			'deleted'   => __( 'Draft deleted.', 'business-doctor' ),
			'published' => __( 'Published. New assessments now use this version; earlier results keep the version they were taken under.', 'business-doctor' ),
			'draft'     => __( 'Draft ready. Edit it below, then publish when it has been reviewed.', 'business-doctor' ),
		);
		if ( 'error' === $msg ) {
			$err = isset( $_GET['bd_err'] ) ? sanitize_text_field( wp_unslash( $_GET['bd_err'] ) ) : __( 'That did not work.', 'business-doctor' );
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		} elseif ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * One version: read-only, or an editor for a draft.
	 *
	 * @param array $f Framework.
	 */
	private static function detail( array $f ) {
		$draft = 'draft' === $f['status'];
		/* translators: 1: platform, 2: version, 3: status. */
		echo '<h1>' . esc_html( sprintf( __( '%1$s, version %2$d (%3$s)', 'business-doctor' ), BD_Frameworks::short_name( $f['platform'] ), $f['version'], $f['status'] ) ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=bd-frameworks' ) ) . '">&larr; ' . esc_html__( 'All question banks', 'business-doctor' ) . '</a></p>';
		self::notice();
		if ( 'full' === $f['purpose'] ) {
			echo '<p class="description">' . esc_html__( 'This is the full, evidence-weighted assessment business owners take, imported from the approved Samakose AI question bank. It is read-only here for now: editing the full assessment banks from wp-admin is not yet built, so a correction currently needs a direct change to the source data and a re-import.', 'business-doctor' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'business-doctor' ) . '</th><th>' . esc_html__( 'Dimension', 'business-doctor' ) . '</th><th>' . esc_html__( 'Weight', 'business-doctor' ) . '</th><th>' . esc_html__( 'Criticality', 'business-doctor' ) . '</th><th>' . esc_html__( 'Question and rating anchors (0-4)', 'business-doctor' ) . '</th></tr></thead><tbody>';
			foreach ( $f['questions'] as $q ) {
				echo '<tr><td><code>' . esc_html( $q['code'] ) . '</code></td><td>' . esc_html( $q['dimension'] ) . '</td><td>' . esc_html( isset( $q['weight'] ) ? $q['weight'] : '' ) . '</td><td>' . esc_html( isset( $q['criticality'] ) ? $q['criticality'] : '' ) . '</td><td><strong>' . esc_html( $q['text'] ) . '</strong><ol start="0">';
				foreach ( (array) ( $q['anchors'] ?? array() ) as $a ) {
					echo '<li>' . esc_html( $a ) . '</li>';
				}
				echo '</ol>';
				if ( ! empty( $q['evidence']['requirement'] ) ) {
					echo '<p class="description">' . esc_html__( 'Evidence required:', 'business-doctor' ) . ' ' . esc_html( $q['evidence']['requirement'] ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			return;
		}

		if ( ! $draft ) {
			echo '<p class="description">' . esc_html__( 'This version is read-only. Create a draft from the live version to make changes.', 'business-doctor' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'business-doctor' ) . '</th><th>' . esc_html__( 'Dimension', 'business-doctor' ) . '</th><th>' . esc_html__( 'Question and answers (A=4, B=3, C=2, D=1)', 'business-doctor' ) . '</th></tr></thead><tbody>';
			foreach ( $f['questions'] as $q ) {
				echo '<tr><td><code>' . esc_html( $q['code'] ) . '</code></td><td>' . esc_html( $q['dimension'] ) . '</td><td><strong>' . esc_html( $q['text'] ) . '</strong><ol type="A">';
				foreach ( array( 'A', 'B', 'C', 'D' ) as $l ) {
					echo '<li>' . esc_html( $q['options'][ $l ] ) . '</li>';
				}
				echo '</ol></td></tr>';
			}
			echo '</tbody></table>';
			return;
		}

		echo '<div class="bd-fw-actions">';
		self::button( 'bd_fw_publish', $f['id'], __( 'Publish this draft', 'business-doctor' ), 'button button-primary', __( 'Publish this version? New assessments will use it straight away.', 'business-doctor' ) );
		self::button( 'bd_fw_delete', $f['id'], __( 'Delete draft', 'business-doctor' ), 'button-link button-link-delete', __( 'Delete this draft?', 'business-doctor' ) );
		echo '</div><p class="description">' . esc_html__( 'Save your edits before publishing. Every question needs a code, a dimension, the question and all four answers. Answers run from strongest (A, 4 points) to weakest (D, 1 point).', 'business-doctor' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bd_fw_save_' . $f['id'] );
		echo '<input type="hidden" name="action" value="bd_fw_save"><input type="hidden" name="id" value="' . esc_attr( $f['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tr><th><label for="bd-fw-name">' . esc_html__( 'Name', 'business-doctor' ) . '</label></th><td><input id="bd-fw-name" class="large-text" name="name" value="' . esc_attr( $f['name'] ) . '"></td></tr>';
		echo '<tr><th><label for="bd-fw-aud">' . esc_html__( 'Who it is for', 'business-doctor' ) . '</label></th><td><textarea id="bd-fw-aud" class="large-text" rows="2" name="audience">' . esc_textarea( (string) $f['audience'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="bd-fw-note">' . esc_html__( 'Change note', 'business-doctor' ) . '</label></th><td><textarea id="bd-fw-note" class="large-text" rows="2" name="note">' . esc_textarea( (string) $f['note'] ) . '</textarea></td></tr></table>';

		$rows   = $f['questions'];
		$rows[] = array(
			'code'      => '',
			'dimension' => '',
			'text'      => '',
			'options'   => array_fill_keys( array( 'A', 'B', 'C', 'D' ), '' ),
		);
		foreach ( $rows as $i => $q ) {
			$new = '' === $q['code'];
			echo '<fieldset class="bd-q' . ( $new ? ' bd-q-new' : '' ) . '"><legend>' . ( $new ? esc_html__( 'Add a question (optional)', 'business-doctor' ) : esc_html( sprintf( '%d. %s', $i + 1, $q['code'] ) ) ) . '</legend>';
			echo '<div class="bd-q-row"><label>' . esc_html__( 'Code', 'business-doctor' ) . '<input name="q[' . esc_attr( $i ) . '][code]" value="' . esc_attr( $q['code'] ) . '"></label>';
			echo '<label class="bd-grow">' . esc_html__( 'Dimension', 'business-doctor' ) . '<input name="q[' . esc_attr( $i ) . '][dimension]" value="' . esc_attr( $q['dimension'] ) . '" list="bd-dims"></label>';
			if ( ! $new ) {
				echo '<label class="bd-remove"><input type="checkbox" name="q[' . esc_attr( $i ) . '][remove]" value="1"> ' . esc_html__( 'Remove', 'business-doctor' ) . '</label>';
			}
			echo '</div><label>' . esc_html__( 'Question', 'business-doctor' ) . '<textarea rows="2" name="q[' . esc_attr( $i ) . '][text]">' . esc_textarea( $q['text'] ) . '</textarea></label>';
			foreach ( array( 'A', 'B', 'C', 'D' ) as $l ) {
				echo '<label>' . esc_html( $l ) . '<textarea rows="1" name="q[' . esc_attr( $i ) . '][' . esc_attr( $l ) . ']">' . esc_textarea( $q['options'][ $l ] ) . '</textarea></label>';
			}
			echo '</fieldset>';
		}
		echo '<datalist id="bd-dims">';
		foreach ( $f['dimensions'] as $d ) {
			echo '<option value="' . esc_attr( $d ) . '">';
		}
		echo '</datalist>';
		submit_button( __( 'Save draft', 'business-doctor' ) );
		echo '</form>';
	}
}
