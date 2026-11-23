<?php
/**
 * Thin wrapper around the Anthropic Messages API, used to draft a diagnosis or prescription for
 * staff to review. Holds no business logic of its own, the same separation as BD_Paystack/
 * BD_Payments: this class only knows how to ask Claude something and get text back.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Claude {

	const API_URL     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/**
	 * The configured API key, or empty if AI drafting has not been set up.
	 *
	 * @return string
	 */
	public static function api_key() {
		return (string) BD_Settings::get( 'claude_api_key' );
	}

	/**
	 * The configured model id.
	 *
	 * @return string
	 */
	public static function model() {
		return (string) BD_Settings::get( 'claude_model' );
	}

	/**
	 * Whether AI drafting is both turned on and has a key and model to use.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return (bool) BD_Settings::get( 'ai_enabled' ) && '' !== trim( self::api_key() ) && '' !== trim( self::model() );
	}

	/**
	 * Sends one request and returns the assistant's reply text.
	 *
	 * @param string $system     System prompt: the agent's standing instructions.
	 * @param string $user       The user turn: the grounded data to analyse.
	 * @param int    $max_tokens Response size cap.
	 * @return string|WP_Error
	 */
	public static function complete( $system, $user, $max_tokens = 2000 ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'bd_ai_not_configured', __( 'AI drafting is not configured yet.', 'business-doctor' ) );
		}
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => self::api_key(),
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::model(),
						'max_tokens' => (int) $max_tokens,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : __( 'The AI service could not be reached.', 'business-doctor' );
			return new WP_Error( 'bd_ai_provider_error', (string) $message, array(
				'status' => 502,
				'http'   => $code,
			) );
		}
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'bd_ai_empty', __( 'The AI service returned no text.', 'business-doctor' ), array( 'status' => 502 ) );
		}
		return $text;
	}
}
