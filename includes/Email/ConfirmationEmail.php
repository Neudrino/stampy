<?php
/**
 * Confirmation email service.
 *
 * Sends the double opt-in confirmation email with a signed link.
 * Translatable defaults + `apply_filters` customization points.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Email;

use Stampy\Security;

/**
 * Sends transactional confirmation emails.
 */
final class ConfirmationEmail {

	/**
	 * Send a confirmation email to a subscriber.
	 *
	 * The email contains a link to the virtual confirm endpoint with the
	 * raw token. The link is signed so tampering is detected.
	 *
	 * @param string $email       Recipient email.
	 * @param string $token       Raw confirmation token.
	 * @param int    $signup_id   Pending signup ID (for logging only).
	 * @return bool True if the email was sent successfully.
	 */
	public function send( string $email, string $token, int $signup_id = 0 ): bool {
		$confirm_url = $this->build_confirm_url( $token );

		$subject = __( 'Please confirm your subscription', 'stampy' );

		$message = sprintf(
			/* translators: %s: confirmation URL */
			__( "Hello!\n\nPlease confirm your subscription by clicking the link below:\n\n%s\n\nIf you did not sign up, you can safely ignore this email.\n\nThank you!", 'stampy' ),
			$confirm_url
		);

		$subject = apply_filters( 'stampy_confirmation_email_subject', $subject, $email );
		$message = apply_filters( 'stampy_confirmation_email_body', $message, $email, $confirm_url );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
		);

		$sent = wp_mail( $email, $subject, $message, $headers );

		do_action( 'stampy_confirmation_email_sent', $email, $signup_id, $sent );

		return $sent;
	}

	/**
	 * Build the confirmation URL (virtual endpoint via rewrite rule).
	 *
	 * @param string $token Raw confirmation token.
	 * @return string
	 */
	private function build_confirm_url( string $token ): string {
		$params = array(
			'token' => $token,
		);

		$signature = Security::sign( $params );

		return add_query_arg(
			array(
				'stampy_confirm' => rawurlencode( $token ),
				'sig'            => $signature,
			),
			home_url( '/' )
		);
	}

	/**
	 * Verify a confirmation URL's signature.
	 *
	 * @param string $token Raw token from the URL.
	 * @param string $sig   Signature from the URL.
	 * @return bool
	 */
	public function verify_signature( string $token, string $sig ): bool {
		return Security::verify(
			array( 'token' => $token ),
			$sig
		);
	}
}
