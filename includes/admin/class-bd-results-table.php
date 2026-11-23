<?php
/**
 * Results and Leads list table.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BD_Results_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'result',
				'plural'   => 'results',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Filters from the query string.
	 *
	 * @return array
	 */
	public function current_filters() {
		$out = array();
		foreach ( array( 'platform', 'classification', 'status', 's' ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$out[ $k ] = isset( $_REQUEST[ $k ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) ) : '';
		}
		return array_filter( $out );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'             => '<input type="checkbox">',
			'full_name'      => __( 'Name', 'business-doctor' ),
			'platform'       => __( 'Platform', 'business-doctor' ),
			'overall_score'  => __( 'Score', 'business-doctor' ),
			'classification' => __( 'Band', 'business-doctor' ),
			'status'         => __( 'Lead status', 'business-doctor' ),
			'completed_at'   => __( 'Completed', 'business-doctor' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'full_name'      => array( 'full_name', false ),
			'platform'       => array( 'platform', false ),
			'overall_score'  => array( 'overall_score', false ),
			'classification' => array( 'classification', false ),
			'status'         => array( 'status', false ),
			'completed_at'   => array( 'completed_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array();
		foreach ( BD_Results::status_labels() as $k => $label ) {
			/* translators: %s: lead status. */
			$actions[ 'mark_' . $k ] = sprintf( __( 'Mark as %s', 'business-doctor' ), $label );
		}
		$actions['delete'] = __( 'Delete permanently', 'business-doctor' );
		return $actions;
	}

	/**
	 * Applies a bulk action.
	 */
	public function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}
		check_admin_referer( 'bulk-results' );
		if ( ! BD_Admin::can() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'business-doctor' ) );
		}
		$ids = isset( $_REQUEST['result'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['result'] ) ) : array();
		if ( ! $ids ) {
			return;
		}
		if ( 'delete' === $action ) {
			BD_Results::delete( $ids );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Deleted.', 'business-doctor' ) . '</p></div>';
			return;
		}
		if ( 0 === strpos( $action, 'mark_' ) ) {
			$status = substr( $action, 5 );
			foreach ( $ids as $id ) {
				BD_Results::update_lead( $id, $status );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Updated.', 'business-doctor' ) . '</p></div>';
		}
	}

	/**
	 * Loads the current page of results.
	 */
	public function prepare_items() {
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'completed_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		$data                  = BD_Results::query( $this->current_filters(), $orderby, $order, $per_page, $this->get_pagenum() );
		$this->items           = $data['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'full_name' );
		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Filter dropdowns above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = $this->current_filters();
		echo '<div class="alignleft actions">';
		echo '<select name="platform"><option value="">' . esc_html__( 'All platforms', 'business-doctor' ) . '</option>';
		foreach ( BD_Frameworks::platforms() as $code => $p ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( isset( $f['platform'] ) ? $f['platform'] : '', $code, false ) . '>' . esc_html( $p['short'] ) . '</option>';
		}
		echo '</select><select name="classification"><option value="">' . esc_html__( 'All bands', 'business-doctor' ) . '</option>';
		foreach ( BD_Scoring::BANDS as $b ) {
			echo '<option value="' . esc_attr( $b ) . '"' . selected( isset( $f['classification'] ) ? $f['classification'] : '', $b, false ) . '>' . esc_html( $b ) . '</option>';
		}
		echo '</select><select name="status"><option value="">' . esc_html__( 'All statuses', 'business-doctor' ) . '</option>';
		foreach ( BD_Results::status_labels() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( isset( $f['status'] ) ? $f['status'] : '', $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'business-doctor' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="result[]" value="' . esc_attr( $item['id'] ) . '">';
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_full_name( $item ) {
		$url = admin_url( 'admin.php?page=bd-results&action=view&id=' . $item['id'] );
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $item['full_name'] ) . '</a></strong><br><span class="description">' . esc_html( $item['email'] ) . '</span>'
			. $this->row_actions(
				array(
					'view'   => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'business-doctor' ) . '</a>',
					'report' => '<a target="_blank" rel="noopener" href="' . esc_url( BD_Report::url( $item['token'] ) ) . '">' . esc_html__( 'Report', 'business-doctor' ) . '</a>',
				)
			);
	}

	/**
	 * Other columns.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	protected function column_default( $item, $column ) {
		switch ( $column ) {
			case 'platform':
				return esc_html( BD_Frameworks::short_name( $item['platform'] ) );
			case 'overall_score':
				return '<strong>' . esc_html( $item['overall_score'] ) . '</strong>';
			case 'classification':
				return '<span class="bd-pill bd-band-' . esc_attr( sanitize_title( $item['classification'] ) ) . '">' . esc_html( $item['classification'] ) . '</span>';
			case 'status':
				$labels = BD_Results::status_labels();
				return '<span class="bd-status bd-status-' . esc_attr( $item['status'] ) . '">' . esc_html( isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'] ) . '</span>';
			case 'completed_at':
				return esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $item['completed_at'] . ' UTC' ) ) );
		}
		return '';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No results yet. Put the assessment on a page with the [business_doctor_assessment] shortcode.', 'business-doctor' );
	}
}
