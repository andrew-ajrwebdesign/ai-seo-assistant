<?php
/**
 * Push_Key — the per-site key that signs weekly report pushes.
 *
 * HOW A PUSH IS TRUSTED. `retainer-scan` signs every push with this key: the header
 * `X-AISA-Signature: t=<unix time>,v1=<hex>` carries HMAC-SHA256 over "<t>.<raw body>". The site
 * recomputes it and compares in constant time, and refuses a signature more than WINDOW seconds from its
 * own clock, so a captured request cannot be replayed later. No WordPress user, cookie or application
 * password is involved: the key does one thing only — let this site's reports in.
 *
 * WHERE THE KEY LIVES. Generated on the site (random_bytes, shown ONCE on the report screen, then never
 * printed again) and stored in a non-autoloaded option; a wp-config constant AI_SEO_ASSISTANT_REPORT_KEY
 * wins when set, for sites that keep secrets out of the database. Andrew copies it into the keys file
 * retainer-scan reads (API Keys/report-push.env) — it is never sent anywhere by the site.
 *
 * verify() is pure so its rules are unit-tested without WordPress.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Report push key: generate, read, verify.
 */
class Push_Key {

	/** Option holding the key (autoload off). */
	public const OPTION = 'ai_seo_assistant_report_key';

	/** The wp-config constant that overrides the option. */
	public const CONSTANT = 'AI_SEO_ASSISTANT_REPORT_KEY';

	/** Request header carrying the signature. */
	public const HEADER = 'X-AISA-Signature';

	/** Largest clock difference accepted, in seconds. */
	public const WINDOW = 600;

	/** Shortest key accepted (a generated key is 43 characters). */
	public const MIN_LENGTH = 32;

	/**
	 * The key in force, or '' when push is not set up.
	 */
	public static function get(): string {
		if ( defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) ) {
			return trim( (string) constant( self::CONSTANT ) );
		}
		$key = get_option( self::OPTION, '' );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether the key comes from wp-config (the screen then cannot replace it).
	 */
	public static function from_constant(): bool {
		return defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ); // The same test get() uses.
	}

	/**
	 * Once the key lives in wp-config, delete any copy left in the database, so removing the constant
	 * later cannot quietly bring an old key back into force.
	 */
	public static function retire_stored(): void {
		if ( self::from_constant() && false !== get_option( self::OPTION, false ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Create and store a new key, replacing any old one, and return it (the only time it is shown).
	 */
	public static function generate(): string {
		$key = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding random bytes into a copyable key, not obfuscation.
		update_option( self::OPTION, $key, false );

		return $key;
	}

	/**
	 * Why a signature fails, or '' when it is good.
	 *
	 * @param string $key    The site's key.
	 * @param string $body   Raw request body, exactly as received.
	 * @param string $header Value of the X-AISA-Signature header.
	 * @param int    $now    Current unix time.
	 * @return string '' | 'not-set-up' | 'malformed' | 'expired' | 'mismatch'
	 */
	public static function verify( string $key, string $body, string $header, int $now ): string {
		if ( strlen( $key ) < self::MIN_LENGTH ) {
			return 'not-set-up';
		}
		if ( ! preg_match( '/^t=(\d{9,11}),v1=([a-f0-9]{64})$/', trim( $header ), $m ) ) {
			return 'malformed';
		}
		if ( abs( $now - (int) $m[1] ) > self::WINDOW ) {
			return 'expired';
		}

		return hash_equals( self::sign( $key, $body, (int) $m[1] ), $m[2] ) ? '' : 'mismatch';
	}

	/**
	 * The signature for a body at a time (what retainer-scan computes).
	 *
	 * @param string $key  Key.
	 * @param string $body Raw body.
	 * @param int    $time Unix time.
	 */
	public static function sign( string $key, string $body, int $time ): string {
		return hash_hmac( 'sha256', $time . '.' . $body, $key );
	}
}
