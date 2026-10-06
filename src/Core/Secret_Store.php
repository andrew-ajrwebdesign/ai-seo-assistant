<?php
/**
 * Secret_Store — API keys and tokens, encrypted at rest.
 *
 * WHY (Andrew, 2026-10-06: "i need to make sure the keys are fully secure"): until 4.4.0 the Claude key,
 * the Google OAuth credentials and tokens, and the report push key sat in wp_options as plain text. Every
 * database backup, every staging copy and anyone with read access to the database (a plugin with a SQL
 * injection, a support tech with phpMyAdmin) could lift them. Now each one is sealed before it is stored.
 *
 * HOW. libsodium's secretbox (XSalsa20-Poly1305: encrypted AND authenticated, so a changed byte is
 * detected, not silently decrypted into garbage), with a fresh random 24-byte nonce on every write. The
 * key is derived from the site's auth salts, which live in wp-config.php, so the database alone never
 * holds enough to open a secret: a stolen backup without wp-config is useless. If the salts are NOT in
 * wp-config (WordPress then keeps them in the database), the secrets are only obfuscated, and
 * salts_in_config() lets the admin screen warn the agency.
 *
 * WHAT IS STORED. A non-autoloaded option holding [ 'v' => 1, 'n' => base64 nonce, 'c' => base64
 * ciphertext ]. An array, deliberately: core's options.php shows arrays as "SERIALIZED DATA" and never
 * prints them.
 *
 * FAILURE. When a secret cannot be opened (salts rotated, row tampered with) it is treated as absent:
 * never fatal, never logged. failed() names the options so an agency user can be told to re-enter them.
 *
 * The same scheme lives in AJR Core as \AJR\Core\Framework\Secret_Store; each plugin carries its own copy
 * so neither depends on the other being installed. Pure static helpers on top of get_option/update_option,
 * so the rules are unit-tested without WordPress.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt, store, read and migrate the plugin's secrets.
 */
class Secret_Store {

	/** Envelope format version. */
	public const VERSION = 1;

	/** Domain-separation prefix for the derived key: this plugin's secrets, scheme v1. */
	public const CONTEXT = 'ai-seo-assistant|secrets|v1';

	/**
	 * Every option that holds a secret, keyed by option name => human label (used in notices).
	 *
	 * The list lives in Secret_Guard (loaded on every request for the write filters) and is re-exported
	 * here, so there is still exactly one list.
	 */
	public const OPTIONS = Secret_Guard::OPTIONS;

	/**
	 * Options that failed to open during this request.
	 *
	 * @var array<string,true>
	 */
	protected static array $failed = [];

	/**
	 * Derived key, memoised for the request (the salts do not change mid-request).
	 *
	 * @var string|null
	 */
	protected static ?string $key = null;

	/**
	 * Option listing secrets get() found in plain text after the upgrade and sealed (non-autoloaded; read
	 * by Admin\Secret_Notices on its screens, then deleted).
	 */
	public const RESEALED_OPTION = 'ai_seo_assistant_secrets_resealed';

	/**
	 * True while this class itself writes a secret option, so guard_write() lets its own writes through.
	 *
	 * @var bool
	 */
	protected static bool $writing = false;

	/**
	 * Behave as on a server without libsodium (seal() returns null). Nothing in the plugin sets it: the
	 * test suite does, through a subclass, so the "could not be encrypted" paths are exercised for real.
	 *
	 * @var bool
	 */
	protected static bool $no_sodium = false;

	/**
	 * The plaintext secret stored in an option, or '' when there is none (or it cannot be opened).
	 *
	 * A value still in plain text is returned as is, so the plugin keeps working; it is never written back
	 * in that form. Once the site is at upgrade level 4.4.0 a plain value can only have been left by
	 * something that bypassed the upgrade (an update run from cron or WP-CLI, a restored backup, a direct
	 * database edit), so it is sealed here, on the spot, when the request is one that may write (wp-admin,
	 * cron, WP-CLI; never a visitor's page view), and the agency is told.
	 *
	 * @param string $option Option name.
	 */
	public static function get( string $option ): string {
		$stored = get_option( $option, '' );

		if ( self::is_envelope( $stored ) ) {
			$plain = self::open( $stored );
			if ( null === $plain ) {
				self::$failed[ $option ] = true;
				return '';
			}
			return $plain;
		}

		if ( ! is_string( $stored ) ) {
			return '';
		}
		if ( '' !== $stored ) {
			self::maybe_reseal( $option, $stored );
		}

		return $stored;
	}

	/**
	 * Seal a plain-text value found by get()/get_array() when the request may write and the site is past
	 * the 4.4.0 upgrade (before it, Upgrade::run() does the sealing and reports it itself).
	 *
	 * @param string $option Option name.
	 * @param string $plain  The plain value as stored (JSON for structured data).
	 */
	protected static function maybe_reseal( string $option, string $plain ): void {
		if ( ! self::may_write_here() || ! Upgrade::is_current() ) {
			return;
		}
		if ( ! self::set( $option, $plain ) ) {
			return; // No sodium: Upgrade's notice already covers it; never fatal on a read.
		}
		$resealed = get_option( self::RESEALED_OPTION, [] );
		$resealed = is_array( $resealed ) ? $resealed : [];
		if ( ! in_array( $option, $resealed, true ) ) {
			$resealed[] = $option;
			update_option( self::RESEALED_OPTION, $resealed, false );
		}
	}

	/**
	 * Whether this request may write to the database on a read: a logged-in wp-admin request (screens,
	 * admin-ajax, admin-post), cron or WP-CLI. A visitor's page view, a REST request and a logged-out
	 * admin-ajax.php request never write because a secret was read.
	 */
	protected static function may_write_here(): bool {
		// Logged-out admin-ajax.php is is_admin() too: anyone can reach it, so it never seals or writes a
		// secret. Kept identical to AJR Core's Secret_Store::may_seal_now().
		return ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'is_user_logged_in' ) && is_user_logged_in() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The rule every write to a secret option passes (Core\Secret_Guard hooks it on every request).
	 *
	 * Reached through guard_sanitize() on `sanitize_option_{$option}` (the only filter add_option()
	 * applies; update_option() applies it too) and guard_update() on `pre_update_option_{$option}`, both
	 * via Secret_Guard's callbacks. Two rules:
	 *
	 * 1. WHO. A write is refused (the stored value is returned, so nothing changes) unless it comes from
	 *    this class, from WP-CLI (whoever runs it already holds wp-config.php), or from a user with the
	 *    agency's tools capability. Without this, options.php's generic form
	 *    (`option_page=options&page_options=ai_seo_assistant_report_key`) let any Administrator, the client
	 *    included, replace the agency's keys: the settings-group capability filter only covers our own
	 *    group, and the push key has no registered setting at all.
	 * 2. WHAT. A plain-text value from an allowed writer is sealed before it is stored; when it cannot be
	 *    sealed, the write is refused rather than storing plain text.
	 *
	 * @param mixed $value     The value about to be stored.
	 * @param mixed $old_value The value stored now (what a refused write leaves in place).
	 * @return mixed What WordPress stores.
	 */
	public static function guard_write( $value, $old_value ) {
		if ( self::$writing ) {
			return $value;
		}
		if ( ! self::write_allowed() ) {
			return $old_value;
		}
		if ( self::is_envelope( $value ) || null === $value || false === $value || '' === $value || [] === $value ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$plain = (string) wp_json_encode( $value );
		} elseif ( is_scalar( $value ) ) {
			$plain = (string) $value;
		} else {
			return $old_value;
		}
		$envelope = self::seal( $plain );

		return null === $envelope ? $old_value : $envelope;
	}

	/**
	 * `sanitize_option_{$option}` callback: guard_write() with the stored value read here (this filter is
	 * not handed it). The add_option() path; on update_option() it runs before pre_update_option.
	 *
	 * @param mixed  $value  The value about to be stored.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function guard_sanitize( $value, $option = '' ) {
		if ( self::$writing ) {
			return $value;
		}

		return self::guard_write( $value, get_option( (string) $option, '' ) );
	}

	/**
	 * `pre_update_option_{$option}` callback: guard_write() with the old value WordPress hands it.
	 *
	 * @param mixed $value     The value about to be stored.
	 * @param mixed $old_value The stored value.
	 * @return mixed
	 */
	public static function guard_update( $value, $old_value ) {
		return self::guard_write( $value, $old_value );
	}

	/**
	 * Whether the current writer may change a secret option (see guard_write()).
	 */
	protected static function write_allowed(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( \AJR\SEOAssistant\Report\Access::TOOLS_CAP );
	}

	/**
	 * A structured secret (e.g. OAuth token data) stored as encrypted JSON.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	public static function get_array( string $option ): array {
		$stored = get_option( $option, [] );

		// Plain array (pre-4.4.0, or left by an update that bypassed the upgrade): usable, and sealed when
		// the request may write (see get()).
		if ( is_array( $stored ) && ! self::is_envelope( $stored ) ) {
			if ( [] !== $stored ) {
				self::maybe_reseal( $option, (string) wp_json_encode( $stored ) );
			}
			return $stored;
		}

		$json = self::get( $option );
		if ( '' === $json ) {
			return [];
		}
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Seal and store a secret; an empty value deletes it.
	 *
	 * @param string $option Option name.
	 * @param string $plain  Plaintext.
	 * @return bool Whether the stored value is now the sealed form of $plain.
	 */
	public static function set( string $option, string $plain ): bool {
		if ( '' === $plain ) {
			delete_option( $option );
			return true;
		}
		$envelope = self::seal( $plain );
		if ( null === $envelope ) {
			return false;
		}
		unset( self::$failed[ $option ] );

		/*
		 * One write, never delete-then-add: between those two calls the secret did not exist (a parallel
		 * request read "no key"), and a failed add lost it for good. update_option() DOES apply its
		 * $autoload argument to an existing row whenever the value changes, and a sealed value always
		 * changes (fresh nonce on every seal), so the row ends up non-autoloaded either way.
		 */
		self::$writing = true;
		try {
			$stored = update_option( $option, $envelope, false );
		} finally {
			self::$writing = false;
		}

		return (bool) $stored;
	}

	/**
	 * Seal and store structured data as JSON.
	 *
	 * @param string              $option Option name.
	 * @param array<string,mixed> $data   Data.
	 */
	public static function set_array( string $option, array $data ): bool {
		return self::set( $option, [] === $data ? '' : (string) wp_json_encode( $data ) );
	}

	/**
	 * Whether a secret is stored (sealed or still plain), without opening it.
	 *
	 * @param string $option Option name.
	 */
	public static function has( string $option ): bool {
		$stored = get_option( $option, '' );

		return self::is_envelope( $stored ) || ( is_string( $stored ) && '' !== $stored ) || ( is_array( $stored ) && [] !== $stored );
	}

	/**
	 * The last four characters of a stored secret, for "Saved · ends …XXXX". '' when none or unreadable.
	 *
	 * @param string $option Option name.
	 */
	public static function last4( string $option ): string {
		$plain = self::get( $option );

		return strlen( $plain ) >= 8 ? substr( $plain, -4 ) : '';
	}

	/**
	 * Encrypt one plain-text option in place. Idempotent.
	 *
	 * @param string $option Option name.
	 * @return string 'migrated' | 'sealed' (already) | 'absent' | 'failed'
	 */
	public static function migrate( string $option ): string {
		$stored = get_option( $option, null );
		if ( null === $stored || '' === $stored || [] === $stored ) {
			return 'absent';
		}
		if ( self::is_envelope( $stored ) ) {
			return 'sealed';
		}
		if ( is_array( $stored ) ) {
			$ok = self::set_array( $option, $stored );
		} elseif ( is_string( $stored ) ) {
			$ok = self::set( $option, $stored );
		} else {
			return 'failed';
		}
		if ( ! $ok ) {
			return 'failed';
		}

		// Prove the row now holds the sealed form; set() overwrote the plain value in the same row.
		return self::is_envelope( get_option( $option, null ) ) ? 'migrated' : 'failed';
	}

	/**
	 * Encrypt every plain-text secret option.
	 *
	 * @return array<string,string> Option name => migrate() result.
	 */
	public static function migrate_all(): array {
		$results = [];
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			$results[ $option ] = self::migrate( $option );
		}

		return $results;
	}

	/**
	 * Every stored secret that is sealed but cannot be opened with this site's salts.
	 *
	 * @return array<int,string> Option names.
	 */
	public static function unreadable(): array {
		$bad = [];
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			$stored = get_option( $option, '' );
			if ( self::is_envelope( $stored ) && null === self::open( $stored ) ) {
				$bad[] = $option;
			}
		}

		return array_values( array_unique( array_merge( $bad, array_keys( self::$failed ) ) ) );
	}

	/**
	 * Whether the four salts the key is derived from are real wp-config.php constants.
	 *
	 * When they are not, WordPress generates them and keeps them in the database, beside the sealed
	 * secrets, so the encryption protects nothing against someone holding the database.
	 */
	public static function salts_in_config(): bool {
		$values = [];
		foreach ( self::SALT_CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				$values[ $constant ] = (string) constant( $constant );
			}
		}

		return self::salts_usable( $values );
	}

	/**
	 * Every salt constant wp_salt() compares for duplicates (wp-includes/pluggable.php: AUTH, SECURE_AUTH,
	 * LOGGED_IN, NONCE and SECRET, each _KEY and _SALT).
	 */
	public const SALT_CONSTANTS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'SECRET_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'SECRET_SALT' ];

	/**
	 * The rule behind salts_in_config(), on plain values so it is testable without constants.
	 *
	 * The same tests wp_salt() applies before it falls back to values it keeps in the database: each of
	 * the four constants the key is made from exists, is not empty, is not the sample phrase, and is not
	 * shared with ANY other salt constant (wp_salt() treats a duplicated value as unset, so a wp-config.php
	 * with one phrase pasted eight times has, in effect, no salts in it). Same logic as AJR Core's
	 * Framework\Secret_Store::salts_in_config(); the shared fixture tests/fixtures/secret-store-cases.json
	 * holds both plugins to it.
	 *
	 * @param array<string,string> $values Salt constant name => value, for the constants that are defined.
	 */
	public static function salts_usable( array $values ): bool {
		$values = array_map( 'strval', $values );
		$counts = array_count_values( $values );

		foreach ( [ 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ] as $constant ) {
			$value = $values[ $constant ] ?? '';
			if ( '' === $value || 'put your unique phrase here' === $value || ( $counts[ $value ] ?? 0 ) > 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a stored value is a sealed envelope.
	 *
	 * @param mixed $value Stored option value.
	 */
	public static function is_envelope( $value ): bool {
		return is_array( $value )
			&& isset( $value['v'], $value['n'], $value['c'] )
			&& self::VERSION === (int) $value['v']
			&& is_string( $value['n'] )
			&& is_string( $value['c'] );
	}

	/**
	 * Encrypt a plaintext into an envelope.
	 *
	 * @param string $plain Plaintext.
	 * @return array{v:int,n:string,c:string}|null Null when libsodium is unavailable.
	 */
	public static function seal( string $plain ): ?array {
		if ( self::$no_sodium || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null; // WordPress ships sodium_compat, so this means a broken install; never store plaintext instead.
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary nonce and ciphertext made storable, not obfuscation.
		return [
			'v' => self::VERSION,
			'n' => base64_encode( $nonce ),
			'c' => base64_encode( sodium_crypto_secretbox( $plain, $nonce, self::key() ) ),
		];
		// phpcs:enable
	}

	/**
	 * Decrypt an envelope.
	 *
	 * @param mixed $envelope Stored value.
	 * @return string|null Plaintext, or null when it is not an envelope or fails authentication.
	 */
	public static function open( $envelope ): ?string {
		if ( ! self::is_envelope( $envelope ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see seal().
		$nonce  = base64_decode( $envelope['n'], true );
		$cipher = base64_decode( $envelope['c'], true );
		// phpcs:enable
		if ( false === $nonce || false === $cipher || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return null; // Malformed input (e.g. shorter than the MAC): the same as a failed check.
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Forget the per-request state (tests; after salts change).
	 */
	public static function reset(): void {
		self::$key    = null;
		self::$failed = [];
	}

	/**
	 * The 32-byte key: a hash of this plugin's context string and the site's auth salts.
	 */
	protected static function key(): string {
		if ( null === self::$key ) {
			self::$key = sodium_crypto_generichash(
				self::CONTEXT . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ),
				'',
				SODIUM_CRYPTO_SECRETBOX_KEYBYTES
			);
		}

		return self::$key;
	}
}
