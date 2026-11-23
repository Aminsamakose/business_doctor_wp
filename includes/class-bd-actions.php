<?php
/**
 * The action-plan items materialised from an approved prescription: concrete, owned, dated tasks an
 * owner or staff member works through.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Actions {

	/**
	 * Decodes a row.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$a                    = (array) $row;
		$a['id']              = (int) $a['id'];
		$a['org_id']          = (int) $a['org_id'];
		$a['prescription_id'] = (int) $a['prescription_id'];
		$a['item_index']      = (int) $a['item_index'];
		$a['assignee_id']     = $a['assignee_id'] ? (int) $a['assignee_id'] : null;
		return $a;
	}

	/**
	 * One action by id.
	 *
	 * @param int $id Action id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['actions']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Every action for an organisation, newest due date first.
	 *
	 * @param int $org_id Organisation id.
	 * @return array[]
	 */
	public static function for_org( $org_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['actions']} WHERE org_id = %d ORDER BY (status = 'open') DESC, due_date ASC", (int) $org_id ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Marks an action done. Only the person it is assigned to, or an organisation's own owner for an
	 * owner-role action, may mark it done; this is enforced by the caller passing the acting user id.
	 *
	 * @param array $action  Action.
	 * @param int   $user_id Acting user.
	 * @return array|WP_Error
	 */
	public static function mark_done( array $action, $user_id ) {
		if ( 'open' !== $action['status'] ) {
			return new WP_Error( 'bd_not_open', __( 'This action is not open.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		if ( $action['assignee_id'] && (int) $action['assignee_id'] !== (int) $user_id ) {
			return new WP_Error( 'bd_forbidden', __( 'You can only mark your own actions done.', 'business-doctor' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$t   = BD_Install::tables();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['actions'],
			array(
				'status'     => 'done',
				'done_at'    => $now,
				'updated_at' => $now,
			),
			array( 'id' => $action['id'] )
		);
		return self::get( $action['id'] );
	}

	/**
	 * The fields an owner may see about one of their own actions.
	 *
	 * @param array $a Action.
	 * @return array
	 */
	public static function to_public( array $a ) {
		return array(
			'id'        => $a['id'],
			'itemTitle' => $a['item_title'],
			'text'      => $a['text'],
			'ownerRole' => $a['owner_role'],
			'dueDate'   => $a['due_date'],
			'status'    => $a['status'],
			'doneAt'    => $a['done_at'],
		);
	}
}
