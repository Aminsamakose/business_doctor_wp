<?php
/**
 * Paystack checkout for the full assessment: starting a payment, verifying it when the owner
 * returns from Paystack's hosted page, settling it from the webhook, and the entitlement a settled
 * payment grants. Direct port of the shape in Samakose AI's finance.ts (startPayment/verifyPayment/
 * paystackWebhook) and entitlements.ts (grantEntitlement), simplified to one entitlement per
 * organisation+platform rather than the original's generic resource entitlements, since here there
 * is exactly one thing to unlock: that platform's full assessment for that organisation.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Payments {

	/**
	 * The GHS price to unlock a platform's full assessment, or null if payments are off or the
	 * platform has no price set (treated as "not for sale").
	 *
	 * @param string $platform Platform code.
	 * @return float|null
	 */
	public static function price_for_platform( $platform ) {
		if ( ! BD_Settings::get( 'payments_enabled' ) ) {
			return null;
		}
		$prices = (array) BD_Settings::get( 'platform_prices_ghs' );
		$price  = isset( $prices[ $platform ] ) ? (float) $prices[ $platform ] : 0.0;
		return $price > 0 ? $price : null;
	}

	/**
	 * Whether an organisation currently holds a live (not revoked) entitlement for a platform.
	 *
	 * @param int    $org_id   Organisation id.
	 * @param string $platform Platform code.
	 * @return bool
	 */
	public static function has_entitlement( $org_id, $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t['entitlements']} WHERE org_id = %d AND platform = %s AND status = 'active'",
				$org_id,
				$platform
			)
		);
		return (bool) $row;
	}

	/**
	 * Grants (or re-activates) an entitlement. Idempotent: granting twice for the same organisation
	 * and platform never creates a duplicate row.
	 *
	 * @param array $args {
	 *   @type int         $org_id      Organisation id.
	 *   @type int         $user_id    Owner's user id.
	 *   @type string      $platform   Platform code.
	 *   @type string      $granted_via 'purchase' or 'admin_grant'.
	 *   @type int|null    $payment_id Source payment, if any.
	 *   @type int|null    $granted_by Staff user id, for an admin grant.
	 * }
	 * @return int Entitlement id.
	 */
	public static function grant_entitlement( array $args ) {
		global $wpdb;
		$t   = BD_Install::tables();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t['entitlements']} WHERE org_id = %d AND platform = %s",
				$args['org_id'],
				$args['platform']
			)
		);
		if ( $existing && 'active' === $existing->status ) {
			return (int) $existing->id;
		}
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$t['entitlements'],
				array(
					'status'      => 'active',
					'granted_via' => $args['granted_via'],
					'payment_id'  => $args['payment_id'] ?? null,
					'granted_by'  => $args['granted_by'] ?? null,
					'granted_at'  => $now,
					'revoked_at'  => null,
				),
				array( 'id' => $existing->id )
			);
			return (int) $existing->id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['entitlements'],
			array(
				'org_id'      => $args['org_id'],
				'user_id'     => $args['user_id'],
				'platform'    => $args['platform'],
				'status'      => 'active',
				'granted_via' => $args['granted_via'],
				'payment_id'  => $args['payment_id'] ?? null,
				'granted_by'  => $args['granted_by'] ?? null,
				'granted_at'  => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Revokes an entitlement (administrator action, e.g. a refund).
	 *
	 * @param int $org_id   Organisation id.
	 * @param string $platform Platform code.
	 * @return bool
	 */
	public static function revoke_entitlement( $org_id, $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$t['entitlements'],
			array(
				'status'     => 'revoked',
				'revoked_at' => current_time( 'mysql', true ),
			),
			array(
				'org_id'   => $org_id,
				'platform' => $platform,
			)
		);
		return false !== $updated;
	}

	/**
	 * A payment row by id, decoded.
	 *
	 * @param int $id Payment id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['payments']} WHERE id = %d", (int) $id ) );
		return $row ? (array) $row : null;
	}

	/**
	 * Paginated, filterable payments list for the admin screen, joined to the organisation name.
	 *
	 * @param array $filters  { platform?, status? }.
	 * @param int   $per_page Rows per page, 0 for all.
	 * @param int   $page     1-based page number.
	 * @return array { items, total }
	 */
	public static function query( array $filters = array(), $per_page = 20, $page = 1 ) {
		global $wpdb;
		$t     = BD_Install::tables();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['platform'] ) ) {
			$where[] = 'p.platform = %s';
			$args[]  = $filters['platform'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'p.status = %s';
			$args[]  = $filters['status'];
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t['payments']} p WHERE $sql_where";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$sql = "SELECT p.*, o.name AS org_name FROM {$t['payments']} p LEFT JOIN {$t['organisations']} o ON o.id = p.org_id WHERE $sql_where ORDER BY p.id DESC";
		if ( $per_page > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $per_page;
			$args[] = (int) max( 0, ( $page - 1 ) * $per_page );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		$items = array();
		foreach ( $rows ?: array() as $row ) {
			$items[] = (array) $row;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * A payment row by Paystack reference.
	 *
	 * @param string $reference Reference.
	 * @return array|null
	 */
	public static function get_by_reference( $reference ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['payments']} WHERE reference = %s", $reference ) );
		return $row ? (array) $row : null;
	}

	/**
	 * A short, URL- and Paystack-safe reference. Collision odds are astronomically low, but the
	 * unique index on the reference column is the real guarantee.
	 *
	 * @param string $platform Platform code, for a human-legible prefix.
	 * @return string
	 */
	private static function generate_reference( $platform ) {
		return 'BD-' . $platform . '-' . strtoupper( substr( wp_generate_password( 12, false, false ), 0, 10 ) );
	}

	/**
	 * Starts a Paystack checkout for an organisation to unlock a platform's full assessment.
	 *
	 * @param array  $org        Organisation (see BD_Organisations).
	 * @param object $user       wp_user-shaped object with at least 'ID' and 'user_email'.
	 * @param string $return_url The page to send the owner back to after checkout (their own page,
	 *                           the one they clicked "Pay" from); the reference is appended to it as
	 *                           a query argument. Falls back to the site home page if not a URL on
	 *                           this site, so a client cannot redirect a payer somewhere untrusted.
	 * @return array|WP_Error { reference, authorizationUrl, amountGhs }
	 */
	public static function start_checkout( array $org, $user, $return_url = '' ) {
		$platform = $org['platform'];
		if ( ! array_key_exists( $platform, BD_Frameworks::platforms() ) ) {
			return new WP_Error( 'bd_unavailable', __( 'That platform is not recognised.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		if ( self::has_entitlement( $org['id'], $platform ) ) {
			return new WP_Error( 'bd_already_entitled', __( 'This organisation already has access to the full assessment.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		$price = self::price_for_platform( $platform );
		if ( null === $price ) {
			return new WP_Error( 'bd_not_for_sale', __( 'This assessment is not available for online purchase right now.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		if ( ! BD_Paystack::is_configured() ) {
			return new WP_Error( 'bd_not_configured', __( 'Online payments are not configured yet. Please contact us to arrange access.', 'business-doctor' ), array( 'status' => 503 ) );
		}

		global $wpdb;
		$t         = BD_Install::tables();
		$now       = current_time( 'mysql', true );
		$reference = self::generate_reference( $platform );

		$safe_return = $return_url && wp_http_validate_url( $return_url ) && 0 === strpos( $return_url, home_url() ) ? $return_url : home_url( '/' );
		$callback    = add_query_arg( 'bd_payment_ref', $reference, $safe_return );

		$init = BD_Paystack::initialize(
			$user->user_email,
			$price,
			$reference,
			$callback,
			array(
				'org_id'   => $org['id'],
				'platform' => $platform,
			)
		);
		if ( is_wp_error( $init ) ) {
			return $init;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['payments'],
			array(
				'user_id'    => $user->ID,
				'org_id'     => $org['id'],
				'platform'   => $platform,
				'reference'  => $reference,
				'amount_ghs' => $price,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return array(
			'reference'        => $reference,
			'authorizationUrl' => $init['authorization_url'],
			'amountGhs'        => $price,
		);
	}

	/**
	 * Generates the next invoice number. Simple and sequential enough for a single site: the invoice
	 * prefix from Settings, the year and month, and the payment's own id (which is already a
	 * monotonically increasing, unique sequence), e.g. INV-202611-00042.
	 *
	 * @param int $payment_id Payment id.
	 * @return string
	 */
	private static function generate_invoice_number( $payment_id ) {
		$prefix = (string) BD_Settings::get( 'invoice_prefix' );
		return sprintf( '%s-%s-%05d', $prefix ?: 'INV', gmdate( 'Ym' ), $payment_id );
	}

	/**
	 * Marks a pending payment successful, issues its invoice number and grants the entitlement.
	 * Idempotent: settling an already-settled payment just returns it unchanged.
	 *
	 * @param array $payment Payment row (array).
	 * @param array $meta    Extra detail to merge into provider_payload for the audit trail.
	 * @return array Updated payment row.
	 */
	private static function settle( array $payment, array $meta = array() ) {
		if ( 'success' === $payment['status'] ) {
			return $payment;
		}
		global $wpdb;
		$t              = BD_Install::tables();
		$invoice_number = self::generate_invoice_number( $payment['id'] );
		$now            = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['payments'],
			array(
				'status'          => 'success',
				'invoice_number'  => $invoice_number,
				'provider_payload' => wp_json_encode( $meta ),
				'updated_at'      => $now,
			),
			array( 'id' => $payment['id'] )
		);
		self::grant_entitlement(
			array(
				'org_id'      => $payment['org_id'],
				'user_id'     => $payment['user_id'],
				'platform'    => $payment['platform'],
				'granted_via' => 'purchase',
				'payment_id'  => $payment['id'],
			)
		);
		/**
		 * Fires once a payment settles and its entitlement is granted. Mirrors bd_diagnostic_submitted:
		 * notify staff of a sale, push to a CRM, and so on.
		 *
		 * @param array $payment The settled payment row, with its new status and invoice number.
		 */
		do_action( 'bd_payment_settled', array_merge( $payment, array(
			'status'         => 'success',
			'invoice_number' => $invoice_number,
		) ) );
		return self::get( $payment['id'] );
	}

	/**
	 * Marks a payment failed.
	 *
	 * @param array $payment Payment row.
	 * @param array $meta    Reason, for the stored payload.
	 */
	private static function mark_failed( array $payment, array $meta = array() ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['payments'],
			array(
				'status'           => 'failed',
				'provider_payload' => wp_json_encode( $meta ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $payment['id'] )
		);
	}

	/**
	 * Confirms the expected amount and currency against what Paystack reports, so a tampered client
	 * amount (or a stale cached page) can never settle a payment for more access than was paid for.
	 *
	 * @param array $payment Our payment row.
	 * @param array $data    Paystack's transaction data.
	 * @return bool
	 */
	private static function amount_matches( array $payment, array $data ) {
		$expected_pesewas = (int) round( (float) $payment['amount_ghs'] * 100 );
		return isset( $data['currency'] ) && 'GHS' === $data['currency']
			&& isset( $data['amount'] ) && (int) $data['amount'] === $expected_pesewas;
	}

	/**
	 * Asks Paystack what happened, for the owner returning from the payment page. Used by the
	 * owner-facing "verify this reference" route; the webhook is still the authoritative settlement
	 * path (see handle_webhook()), this just lets the UI show an up-to-date status without waiting
	 * for the webhook to arrive.
	 *
	 * @param array $payment Our payment row.
	 * @return array Updated payment row.
	 */
	public static function verify_and_settle( array $payment ) {
		if ( 'pending' !== $payment['status'] ) {
			return $payment;
		}
		$data = BD_Paystack::verify( $payment['reference'] );
		if ( is_wp_error( $data ) ) {
			return $payment; // Leave it pending; the owner (or the webhook) can try again shortly.
		}
		$status = $data['status'] ?? '';
		if ( 'success' === $status ) {
			if ( ! self::amount_matches( $payment, $data ) ) {
				self::mark_failed( $payment, array(
					'mismatch' => true,
					'amount'   => $data['amount'] ?? null,
					'currency' => $data['currency'] ?? null,
				) );
				return self::get( $payment['id'] );
			}
			return self::settle( $payment, array(
				'verified' => true,
				'id'       => $data['id'] ?? null,
				'channel'  => $data['channel'] ?? null,
			) );
		}
		if ( in_array( $status, array( 'failed', 'abandoned' ), true ) ) {
			self::mark_failed( $payment, array( 'status' => $status ) );
			return self::get( $payment['id'] );
		}
		return $payment;
	}

	/**
	 * Processes a verified Paystack webhook event. The signature must already have been checked by
	 * the caller (BD_Rest_Webhooks); this only handles the business logic, and is idempotent per
	 * event id so a redelivered webhook never double-settles or double-notifies.
	 *
	 * @param array $event Decoded webhook payload.
	 * @return array { ok: bool, duplicate?, ignored?, mismatch? }
	 */
	public static function handle_webhook_event( array $event ) {
		global $wpdb;
		$t         = BD_Install::tables();
		$event_id  = ( $event['event'] ?? '' ) . ':' . ( $event['data']['id'] ?? $event['data']['reference'] ?? wp_generate_uuid4() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t['webhook_events']} (provider, event_id, type, payload, created_at) VALUES ('paystack', %s, %s, %s, %s)",
				$event_id,
				(string) ( $event['event'] ?? '' ),
				wp_json_encode( $event ),
				current_time( 'mysql', true )
			)
		);
		if ( ! $inserted ) {
			return array(
				'ok'        => true,
				'duplicate' => true,
			);
		}

		$reference = $event['data']['reference'] ?? null;
		$payment   = $reference ? self::get_by_reference( $reference ) : null;
		if ( ! $payment ) {
			return array(
				'ok'      => true,
				'ignored' => true,
			);
		}

		if ( 'charge.success' === ( $event['event'] ?? '' ) ) {
			if ( ! self::amount_matches( $payment, $event['data'] ) ) {
				self::mark_failed( $payment, array( 'mismatch' => true ) );
				return array(
					'ok'       => true,
					'mismatch' => true,
				);
			}
			self::settle( $payment, array(
				'webhook' => true,
				'id'      => $event['data']['id'] ?? null,
				'channel' => $event['data']['channel'] ?? null,
			) );
		} elseif ( 'charge.failed' === ( $event['event'] ?? '' ) && 'pending' === $payment['status'] ) {
			self::mark_failed( $payment, array( 'webhook' => true ) );
		}
		return array( 'ok' => true );
	}
}
