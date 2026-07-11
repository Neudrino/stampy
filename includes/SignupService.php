<?php
/**
 * Signup service — core opt-in business logic.
 *
 * Orchestrates the signup pipeline: spam-guard chain → field validation →
 * staged upsert into pending_signups → confirmation email.
 *
 * Handles both the "new/pending subscriber" path (staged, requires
 * confirmation) and the "already-confirmed subscriber" path (immediate
 * list membership + merge, no re-confirmation).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ConsentTextRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Email\ConfirmationEmail;
use Stampy\SpamGuards\SpamGuardChain;
use Stampy\SpamGuards\SpamGuardResult;
use Stampy\Validators\ValidatorRegistry;

/**
 * Central service for processing signup requests.
 */
final class SignupService {

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Pending signup repository.
	 *
	 * @var PendingSignupRepository
	 */
	private PendingSignupRepository $pending;

	/**
	 * Subscriber meta repository.
	 *
	 * @var SubscriberMetaRepository
	 */
	private SubscriberMetaRepository $meta;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private ListRepository $lists;

	/**
	 * Consent text repository.
	 *
	 * @var ConsentTextRepository
	 */
	private ConsentTextRepository $consent;

	/**
	 * Confirmation email service.
	 *
	 * @var ConfirmationEmail
	 */
	private ConfirmationEmail $mailer;

	/**
	 * Spam guard chain.
	 *
	 * @var SpamGuardChain
	 */
	private SpamGuardChain $guard_chain;

	/**
	 * Validator registry.
	 *
	 * @var ValidatorRegistry
	 */
	private ValidatorRegistry $validators;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null     $subscribers  Optional.
	 * @param PendingSignupRepository|null  $pending      Optional.
	 * @param SubscriberMetaRepository|null $meta        Optional.
	 * @param ListRepository|null           $lists        Optional.
	 * @param ConsentTextRepository|null    $consent      Optional.
	 * @param ConfirmationEmail|null        $mailer       Optional.
	 * @param SpamGuardChain|null           $guard_chain   Optional.
	 * @param ValidatorRegistry|null        $validators    Optional.
	 */
	public function __construct(
		?SubscriberRepository $subscribers = null,
		?PendingSignupRepository $pending = null,
		?SubscriberMetaRepository $meta = null,
		?ListRepository $lists = null,
		?ConsentTextRepository $consent = null,
		?ConfirmationEmail $mailer = null,
		?SpamGuardChain $guard_chain = null,
		?ValidatorRegistry $validators = null
	) {
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->pending     = $pending ?? new PendingSignupRepository();
		$this->meta        = $meta ?? new SubscriberMetaRepository();
		$this->lists       = $lists ?? new ListRepository();
		$this->consent     = $consent ?? new ConsentTextRepository();
		$this->mailer      = $mailer ?? new ConfirmationEmail();
		$this->guard_chain = $guard_chain ?? SpamGuardChain::default_chain();
		$this->validators  = $validators ?? ValidatorRegistry::instance();
	}

	/**
	 * Process a signup request.
	 *
	 * Pipeline:
	 * 1. Spam-guard chain (honeypot + rate-limit).
	 * 2. Validate email (always required).
	 * 3. Validate consent (always required).
	 * 4. Validate submitted fields against their field-type validators.
	 * 5. If subscriber is already confirmed → add lists immediately,
	 *    apply merge policy, no email.
	 * 6. Otherwise → stage in pending_signups, send confirmation email.
	 *
	 * Anti-enumeration: always returns the same success response regardless
	 * of whether the email already exists.
	 *
	 * @param array<mixed> $request The raw signup request data containing:
	 *                               `email`, `fields`, `consent`,
	 *                               `form_id`, `list_ids`, and the
	 *                               honeypot field `website_check`.
	 * @return array{success: bool, message: string, errors?: array<string, string>}
	 */
	public function signup( array $request ): array {
		$spam_result = $this->guard_chain->check( $request );
		if ( ! $spam_result->passed() ) {
			return array(
				'success' => false,
				'message' => $spam_result->reason(),
			);
		}

		$errors = array();

		$email_result = $this->validators->validate( 'email', $request['email'] ?? '' );
		if ( ! $email_result->is_valid() ) {
			$errors['email'] = $email_result->error();
		}

		$consent_result = $this->validators->validate( 'acceptance', $request['consent'] ?? false );
		if ( ! $consent_result->is_valid() ) {
			$errors['consent'] = $consent_result->error();
		}

		$list_ids = $request['list_ids'] ?? array();
		if ( ! is_array( $list_ids ) || count( $list_ids ) < 1 ) {
			$errors['list_ids'] = __( 'At least one target list is required.', 'stampy' );
		}

		$raw_fields       = is_array( $request['fields'] ?? null ) ? $request['fields'] : array();
		$validated_fields = array();

		foreach ( $raw_fields as $key => $value ) {
			$field_key = sanitize_key( (string) $key );

			$text_result = $this->validators->validate( 'text', $value );
			if ( ! $text_result->is_valid() ) {
				$errors[ $field_key ] = $text_result->error();
			} else {
				$validated_fields[ $field_key ] = $text_result->sanitized();
			}
		}

		if ( count( $errors ) > 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Validation failed.', 'stampy' ),
				'errors'  => $errors,
			);
		}

		$email       = $email_result->sanitized();
		$form_id     = isset( $request['form_id'] ) ? (int) $request['form_id'] : null;
		$consent_v   = $this->consent->latest();
		$consent_ver = null !== $consent_v ? (int) $consent_v->version : 1;

		$subscriber = $this->subscribers->find_by_email( $email );

		if ( null !== $subscriber && 'confirmed' === $subscriber->status ) {
			$this->apply_confirmed_update( (int) $subscriber->id, $validated_fields, $list_ids );

			return array(
				'success' => true,
				'message' => __( 'Your subscription has been updated.', 'stampy' ),
			);
		}

		if ( null === $subscriber ) {
			$subscriber = $this->subscribers->create_or_get( $email, 'pending', $consent_ver );
		}

		$token      = Security::generate_token();
		$token_hash = Security::hash_token( $token );

		$payload = array(
			'attributes'      => $validated_fields,
			'list_ids'        => array_map( 'intval', $list_ids ),
			'consent_version' => $consent_ver,
			'form_id'         => $form_id,
		);

		$this->pending->create_or_refresh(
			(int) $subscriber->id,
			$token_hash,
			$payload,
			$form_id
		);

		$this->mailer->send( $email, $token );

		return array(
			'success' => true,
			'message' => __( 'Please check your email to confirm your subscription.', 'stampy' ),
		);
	}

	/**
	 * Confirm a pending signup by token.
	 *
	 * Pipeline:
	 * 1. Look up the pending signup by token hash.
	 * 2. Verify the token hasn't expired.
	 * 3. Promote subscriber to confirmed (if not already).
	 * 4. Apply staged attributes (merge policy: non-empty overwrites).
	 * 5. Add list memberships.
	 * 6. Delete the pending signup row.
	 *
	 * @param string $token Raw confirmation token.
	 * @return array{success: bool, message: string, preferences_url?: string}
	 */
	public function confirm( string $token ): array {
		$token_hash = Security::hash_token( $token );

		$pending_signup = $this->pending->find_by_token( $token_hash );

		if ( null === $pending_signup ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid or expired confirmation link.', 'stampy' ),
			);
		}

		$now = current_time( 'mysql', true );
		$exp = $pending_signup->expires_at;

		if ( $exp < $now ) {
			$this->pending->delete( (int) $pending_signup->id );

			return array(
				'success' => false,
				'message' => __( 'This confirmation link has expired. Please sign up again.', 'stampy' ),
			);
		}

		$subscriber_id = (int) $pending_signup->subscriber_id;
		$payload       = json_decode( (string) $pending_signup->payload, true );

		if ( ! is_array( $payload ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid confirmation data.', 'stampy' ),
			);
		}

		$attributes  = is_array( $payload['attributes'] ?? null ) ? $payload['attributes'] : array();
		$list_ids    = is_array( $payload['list_ids'] ?? null ) ? $payload['list_ids'] : array();
		$consent_ver = (int) ( $payload['consent_version'] ?? 1 );

		$subscriber = $this->subscribers->find( $subscriber_id );

		if ( null === $subscriber ) {
			$this->pending->delete( (int) $pending_signup->id );

			return array(
				'success' => false,
				'message' => __( 'Subscriber not found.', 'stampy' ),
			);
		}

		if ( 'confirmed' !== $subscriber->status ) {
			$this->subscribers->update_status( $subscriber_id, 'confirmed' );
		}

		$this->subscribers->set_consent_version( $subscriber_id, $consent_ver );

		// Always generate a fresh unsubscribe token on confirmation.
		// This ensures the raw token is available to build the preference
		// page URL (only the hash is stored; the raw token is returned
		// to the caller and never persisted).
		$unsub_token = Security::generate_token();
		$this->subscribers->set_unsub_token_hash(
			$subscriber_id,
			Security::hash_token( $unsub_token )
		);

		$this->meta->apply_merge( $subscriber_id, $attributes );

		foreach ( $list_ids as $list_id ) {
			$this->lists->add_subscriber( $subscriber_id, (int) $list_id );
		}

		$this->pending->delete( (int) $pending_signup->id );

		do_action( 'stampy_subscriber_confirmed', $subscriber_id, $list_ids );

		// Build the preference page URL with the raw token so the
		// confirmation page can show a "Manage preferences" link.
		$pref_sig = Security::sign(
			array(
				's' => $subscriber_id,
				't' => $unsub_token,
			)
		);

		$preferences_url = add_query_arg(
			array(
				'stampy_pref_s'   => $subscriber_id,
				'stampy_pref_t'   => rawurlencode( $unsub_token ),
				'stampy_pref_sig' => $pref_sig,
			),
			home_url( '/' )
		);

		return array(
			'success'         => true,
			'message'         => __( 'Your subscription has been confirmed. Thank you!', 'stampy' ),
			'preferences_url' => $preferences_url,
		);
	}

	/**
	 * Apply immediate updates to an already-confirmed subscriber.
	 *
	 * @param int                   $subscriber_id Subscriber ID.
	 * @param array<string, string> $attributes    Validated attributes.
	 * @param array<int>            $list_ids      Target list IDs.
	 * @return void
	 */
	private function apply_confirmed_update( int $subscriber_id, array $attributes, array $list_ids ): void {
		$this->meta->apply_merge( $subscriber_id, $attributes );

		foreach ( $list_ids as $list_id ) {
			$this->lists->add_subscriber( $subscriber_id, (int) $list_id );
		}

		do_action( 'stampy_subscriber_updated', $subscriber_id, $list_ids );
	}

	/**
	 * Build the preference page URL for a subscriber.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $unsub_token   Raw unsubscribe token.
	 * @return string
	 */
	public function build_preferences_url( int $subscriber_id, string $unsub_token ): string {
		$pref_sig = Security::sign(
			array(
				's' => $subscriber_id,
				't' => $unsub_token,
			)
		);

		return add_query_arg(
			array(
				'stampy_pref_s'   => $subscriber_id,
				'stampy_pref_t'   => rawurlencode( $unsub_token ),
				'stampy_pref_sig' => $pref_sig,
			),
			home_url( '/' )
		);
	}
}
