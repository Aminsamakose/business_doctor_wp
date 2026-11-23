<?php
/**
 * Payments list table, for staff to see who has paid for the full assessment.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BD_Payments_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payment',
				'plural'   => 'payments',
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
		foreach ( array( 'platform', 'status' ) as $k ) {
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
			'org_name'       => __( 'Organisation', 'business-doctor' ),
			'platform'       => __( 'Platform', 'business-doctor' ),
			'amount_ghs'     => __( 'Amount', 'business-doctor' ),
			'status'         => __( 'Status', 'business-doctor' ),
			'invoice_number' => __( 'Invoice', 'business-doctor' ),
			'reference'      => __( 'Reference', 'business-doctor' ),
			'created_at'     => __( 'Started', 'business-doctor' ),
		);
	}

	/**
	 * Loads the current page.
	 */
	public function prepare_items() {
		$per_page              = 20;
		$data                  = BD_Payments::query( $this->current_filters(), $per_page, $this->get_pagenum() );
		$this->items           = $data['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Filter dropdowns.
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
		echo '</select><select name="status"><option value="">' . esc_html__( 'All statuses', 'business-doctor' ) . '</option>';
		foreach ( array( 'pending', 'success', 'failed' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( isset( $f['status'] ) ? $f['status'] : '', $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'business-doctor' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Organisation column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_org_name( $item ) {
		$name = $item['org_name'] ? $item['org_name'] : __( '(no organisation name)', 'business-doctor' );
		return '<strong>' . esc_html( $name ) . '</strong>';
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
			case 'amount_ghs':
				return esc_html( 'GHS ' . number_format_i18n( (float) $item['amount_ghs'], 2 ) );
			case 'status':
				$cls = 'success' === $item['status'] ? 'bd-band-strong' : ( 'failed' === $item['status'] ? 'bd-band-critical' : '' );
				return '<span class="' . esc_attr( $cls ) . '">' . esc_html( ucfirst( $item['status'] ) ) . '</span>';
			case 'invoice_number':
				return $item['invoice_number'] ? esc_html( $item['invoice_number'] ) : '&ndash;';
			case 'reference':
				return esc_html( $item['reference'] );
			case 'created_at':
				return esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $item['created_at'] . ' UTC' ) ) );
		}
		return '';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No payments yet.', 'business-doctor' );
	}
}
