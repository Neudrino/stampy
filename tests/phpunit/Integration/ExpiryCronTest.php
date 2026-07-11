<?php
/**
 * Integration tests for the expiry cron.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;
use WP_UnitTestCase;

/**
 * Tests the daily purge of expired pending signups.
 */
class ExpiryCronTest extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	/**
	 * Expired pending signups should be purged.
	 *
	 * @return void
	 */
	public function test_purge_expired_removes_expired_signups(): void {
		$subscriber_repo = new SubscriberRepository();
		$pending_repo    = new PendingSignupRepository();

		$subscriber = $subscriber_repo->create_or_get( 'expired@example.com', 'pending' );

		$token      = Security::generate_token();
		$token_hash = Security::hash_token( $token );

		$pending_id = $pending_repo->create_or_refresh(
			(int) $subscriber->id,
			$token_hash,
			array(
				'attributes'     => array(),
				'list_ids'       => array( 1 ),
				'consent_version' => 1,
				'form_id'        => null,
			),
			null
		);

		global $wpdb;
		$table = \Stampy\Schema::table( 'pending_signups' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$table,
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
			array( 'id' => $pending_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		$deleted = $pending_repo->purge_expired();
		$this->assertSame( 1, $deleted );

		$this->assertNull( $pending_repo->find( $pending_id ) );
	}

	/**
	 * Non-expired pending signups should survive the purge.
	 *
	 * @return void
	 */
	public function test_purge_expired_keeps_valid_signups(): void {
		$subscriber_repo = new SubscriberRepository();
		$pending_repo    = new PendingSignupRepository();

		$subscriber = $subscriber_repo->create_or_get( 'valid@example.com', 'pending' );

		$token      = Security::generate_token();
		$token_hash = Security::hash_token( $token );

		$pending_id = $pending_repo->create_or_refresh(
			(int) $subscriber->id,
			$token_hash,
			array(
				'attributes'     => array(),
				'list_ids'       => array( 1 ),
				'consent_version' => 1,
				'form_id'        => null,
			),
			null
		);

		$deleted = $pending_repo->purge_expired();
		$this->assertSame( 0, $deleted );

		$this->assertNotNull( $pending_repo->find( $pending_id ) );
	}

	/**
	 * The Lifecycle cron callback should call purge_expired.
	 *
	 * @return void
	 */
	public function test_cron_callback_purges(): void {
		$subscriber_repo = new SubscriberRepository();
		$pending_repo    = new PendingSignupRepository();

		$subscriber = $subscriber_repo->create_or_get( 'cron@example.com', 'pending' );

		$token      = Security::generate_token();
		$token_hash = Security::hash_token( $token );

		$pending_id = $pending_repo->create_or_refresh(
			(int) $subscriber->id,
			$token_hash,
			array(
				'attributes'     => array(),
				'list_ids'       => array( 1 ),
				'consent_version' => 1,
				'form_id'        => null,
			),
			null
		);

		global $wpdb;
		$table = \Stampy\Schema::table( 'pending_signups' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$table,
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
			array( 'id' => $pending_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		\Stampy\Lifecycle::purge_expired_signups();

		$this->assertNull( $pending_repo->find( $pending_id ) );
	}

	/**
	 * The HMAC secret should be generated on activation.
	 *
	 * @return void
	 */
	public function test_hmac_secret_generated(): void {
		delete_option( 'stampy_hmac_secret' );

		$secret = Security::get_secret();

		$this->assertSame( 64, strlen( $secret ) );

		$stored = get_option( 'stampy_hmac_secret' );
		$this->assertSame( $secret, $stored );

		$secret2 = Security::get_secret();
		$this->assertSame( $secret, $secret2 );
	}

	/**
	 * Token generation should produce 64-char hex strings.
	 *
	 * @return void
	 */
	public function test_token_generation(): void {
		$token = Security::generate_token();

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * Token hashing should be deterministic.
	 *
	 * @return void
	 */
	public function test_token_hashing(): void {
		$token = 'test-token';
		$hash  = Security::hash_token( $token );

		$this->assertSame( hash( 'sha256', 'test-token' ), $hash );
		$this->assertTrue( Security::verify_token( $token, $hash ) );
		$this->assertFalse( Security::verify_token( 'wrong', $hash ) );
	}

	/**
	 * HMAC signing and verification.
	 *
	 * @return void
	 */
	public function test_sign_and_verify(): void {
		$params = array( 's' => 1, 't' => 'token' );
		$sig    = Security::sign( $params );

		$this->assertTrue( Security::verify( $params, $sig ) );
		$this->assertFalse( Security::verify( $params, 'tampered' ) );
		$this->assertFalse( Security::verify( array( 's' => 2, 't' => 'token' ), $sig ) );
	}
}
