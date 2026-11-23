<?php
/**
 * Business-owner accounts: registration and sign-in using ordinary WordPress users with the
 * `business_owner` role (created in BD_Install::add_business_owner_role()). Not used by the free
 * assessment, which stays fully anonymous.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Accounts {

	/**
	 * Hooks.
	 */
	public static function init() {
		// Nothing to hook at boot; registration and login go through the REST API (BD_Rest_Owner)
		// and the [bd_owner_*] shortcodes. Reserved for future hooks (e.g. custom login redirects).
	}

	/**
	 * Whether a user holds the business_owner role (or is an administrator, who can act as one).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_owner( $user_id ) {
		$user = get_userdata( $user_id );
		return $user && ( in_array( 'business_owner', (array) $user->roles, true ) || user_can( $user_id, 'manage_business_diagnostics' ) );
	}

	/**
	 * Registers a new business-owner account and logs them in.
	 *
	 * @param string $full_name Full name.
	 * @param string $email     Email.
	 * @param string $password  Chosen password.
	 * @return WP_User|WP_Error
	 */
	public static function register( $full_name, $email, $password ) {
		$full_name = sanitize_text_field( $full_name );
		$email     = sanitize_email( $email );
		if ( mb_strlen( $full_name ) < 2 || mb_strlen( $full_name ) > 120 ) {
			return new WP_Error( 'bd_invalid', __( 'Enter your full name.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bd_invalid', __( 'Enter a valid email address.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'bd_exists', __( 'An account already exists for this email address. Please sign in instead.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'bd_invalid', __( 'Choose a password of at least 8 characters.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$username = self::unique_username( $email );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $full_name,
				'first_name'   => $full_name,
				'role'         => 'business_owner',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'bd_db', __( 'Your account could not be created. Please try again.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		do_action( 'bd_owner_registered', $user_id );
		return get_userdata( $user_id );
	}

	/**
	 * A username guaranteed not to collide, derived from the email's local part.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function unique_username( $email ) {
		$base = sanitize_user( strtok( $email, '@' ), true );
		$base = '' === $base ? 'owner' : $base;
		$try  = $base;
		$i    = 1;
		while ( username_exists( $try ) ) {
			++$i;
			$try = $base . $i;
		}
		return $try;
	}

	/**
	 * Signs a business owner in by email and password.
	 *
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @return WP_User|WP_Error
	 */
	public static function login( $email, $password ) {
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'bd_login', __( 'That email and password do not match an account.', 'business-doctor' ), array( 'status' => 401 ) );
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		return $user;
	}
}
