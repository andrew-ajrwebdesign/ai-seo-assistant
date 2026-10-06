<?php
/**
 * Page_Review — generate, apply and undo one page's suggestions (mockup C1–C3).
 *
 * GENERATE. Claude gets the page's content, its real searches with clicks, impressions, position and CTR,
 * its GA4 figures, AJR Core's business details, the scan's findings and the best-practice rules, and the
 * photos that need alt text (small copies, so it describes what it can see). The reply is schema-bound
 * (structured outputs): title, description, focus keyphrase, alt text per image, and editor advice. It is
 * stored on the page's scan row; nothing on the site changes.
 *
 * APPLY (field by field, only what the agency accepted or edited): title, description and focus keyphrase
 * through the active SEO plugin's adapter, alt text to the attachment's `_wp_attachment_image_alt`. Every
 * field is logged with its before and after (Changes\Change_Log), then the page is rescanned.
 *
 * UNDO restores "before", but only while the field still holds what was applied: a value someone changed
 * since is left alone and the agency is told, so an undo can never overwrite later work.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Review;

use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\AI\Prompt_Builder;
use AJR\SEOAssistant\AI\Spend;
use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Content\Business;
use AJR\SEOAssistant\Content\Content_Extractor;
use AJR\SEOAssistant\Core\Utils;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Rules;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * One page's review.
 */
class Page_Review {

	/** Most images sent to Claude per page (each a small copy, about 100 input tokens). */
	public const MAX_IMAGES = 8;

	/** Image types Claude reads. */
	public const IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

	/**
	 * Claude client.
	 *
	 * @var Claude_Client
	 */
	protected Claude_Client $claude;

	/**
	 * Scan storage.
	 *
	 * @var Scan_Store
	 */
	protected Scan_Store $store;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	protected Change_Log $log;

	/**
	 * Active SEO adapter.
	 *
	 * @var object
	 */
	protected $adapter;

	/**
	 * Constructor.
	 *
	 * @param Claude_Client|null $claude  Claude client.
	 * @param Scan_Store|null    $store   Scan storage.
	 * @param Change_Log|null    $log     Change log.
	 * @param object|null        $adapter SEO adapter.
	 */
	public function __construct( ?Claude_Client $claude = null, ?Scan_Store $store = null, ?Change_Log $log = null, $adapter = null ) {
		$this->claude  = $claude ?? new Claude_Client();
		$this->store   = $store ?? new Scan_Store();
		$this->log     = $log ?? new Change_Log();
		$this->adapter = $adapter ?? Scanner::adapter();
	}

	/**
	 * Images on the page that need alt text, with an attachment to write to.
	 *
	 * ⛔ NEVER REPLACE A GOOD ALT (Andrew, 2026-10-06: Claude replaced "Boise's Capitol Building" with a
	 * guess). An image qualifies only when BOTH what the page prints and what the Media Library holds are
	 * missing or poor (empty, one short word, the file name). A page that does not print a good Media
	 * Library alt (a builder module with its own empty alt field) is a "do in the editor" finding, not a
	 * rewrite: see Rules::images().
	 *
	 * @param array<string,mixed> $facts Facts (images carry `alt` as printed and `stored_alt` from the Media Library).
	 * @return array<int,array<string,mixed>>
	 */
	public static function images_needing_alt( array $facts ): array {
		$out  = [];
		$seen = [];
		foreach ( (array) ( $facts['images'] ?? [] ) as $img ) {
			$id = (int) ( $img['id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$file        = (string) ( $img['file'] ?? '' );
			$printed     = trim( (string) ( $img['alt'] ?? '' ) );
			$stored      = trim( (string) ( $img['stored_alt'] ?? '' ) );
			$good        = self::good_alt( $stored, $file ) ? $stored : ( self::good_alt( $printed, $file ) ? $printed : '' );
			// 'write': no real alt anywhere, Claude writes one from the photo. 'check': a descriptive alt exists;
			// Claude only looks at the photo to say whether it is WRONG (never to restyle it), and a correction
			// is shown unticked beside the old alt, for the agency to decide.
			$out[] = [
				'id'   => $id,
				'file' => $file,
				'alt'  => '' !== $good ? $good : ( '' !== $stored ? $stored : $printed ),
				'mode' => '' !== $good ? 'check' : 'write',
			];
		}
		// Missing alts first; the cap keeps the cost of a page review predictable.
		usort( $out, static fn( $a, $b ) => ( 'write' === $a['mode'] ? 0 : 1 ) <=> ( 'write' === $b['mode'] ? 0 : 1 ) );

		return array_slice( $out, 0, self::MAX_IMAGES );
	}

	/**
	 * Whether an alt text is a real description (not empty, not one short word, not the file name).
	 *
	 * @param string $alt  Alt text.
	 * @param string $file File name.
	 */
	public static function good_alt( string $alt, string $file ): bool {
		return '' !== trim( $alt ) && ! Rules::weak_alt( $alt, $file );
	}

	/**
	 * Ask Claude for this page's suggestions and store them.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|\WP_Error The stored suggestions.
	 */
	public function generate( int $post_id ) {
		$row  = $this->store->get( $post_id );
		$post = get_post( $post_id );
		if ( null === $row || ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'aisa_review_not_scanned', __( 'Scan this page first.', 'ai-seo-assistant' ) );
		}
		if ( ! $this->claude->has_api_key() ) {
			return new \WP_Error( 'aisa_review_no_key', __( 'Add the Claude API key in Settings first.', 'ai-seo-assistant' ) );
		}
		$facts   = $row['facts'];
		$page    = ( new Page_Data() )->get( (string) ( $facts['url'] ?? get_permalink( $post ) ) );
		// Only images Claude can SEE are sent for alt text: one that cannot be loaded is left out, never guessed.
		$blocks  = self::image_blocks( self::images_needing_alt( $facts ) );
		$ids     = array_column( $blocks, 'id' );
		$images  = array_values( array_filter( self::images_needing_alt( $facts ), static fn( $i ) => in_array( $i['id'], $ids, true ) ) );
		$content = Utils::trim_to_length( (string) ( new Content_Extractor() )->get_content( $post_id ), 8000 );
		$current = [
			'title'       => (string) ( $facts['title'] ?? '' ),
			'description' => (string) ( $facts['description'] ?? '' ),
			'keyphrase'   => (string) $this->adapter->get_keyphrase( $post_id ),
		];

		$prompt = ( new Prompt_Builder() )->build_review_prompt(
			[
				'post_title'          => wp_strip_all_tags( get_the_title( $post ) ),
				'permalink'           => (string) get_permalink( $post ),
				'content'             => $content,
				'current'             => $current,
				'queries'             => (array) ( $page['gsc']['queries'] ?? [] ),
				'totals'              => null === $page ? [] : [
					'clicks'       => (int) $page['gsc']['clicks'],
					'impressions'  => (int) $page['gsc']['impressions'],
					'ctr'          => (string) ( $page['gsc']['ctr'] ?? '' ),
					'position'     => (string) ( $page['gsc']['position'] ?? '' ),
					'expected_ctr' => (string) Opportunity::expected_ctr( (float) ( $page['gsc']['position'] ?? 0 ) ),
				],
				'ga4'                 => $page['ga4'] ?? [],
				'business'            => Business::prompt_lines(),
				'tone'                => Business::tone(),
				'issues'              => array_map( static fn( $i ) => $i['title'] . ' — ' . $i['fix'], (array) $row['issues'] ),
				'images'              => $images,
				'headings'            => array_map( static fn( $h ) => 'H' . $h['l'] . ' ' . $h['t'], array_slice( (array) ( $facts['headings'] ?? [] ), 0, 20 ) ),
				'keyphrase_supported' => (bool) $this->adapter->supports_keyphrase(),
				'seo_plugin'          => (string) $this->adapter->get_name(),
			]
		);

		$reply = $this->claude->generate_json( $prompt, ( new Prompt_Builder() )->review_schema(), 'review', $blocks );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$by_id = [];
		foreach ( (array) ( $reply['alts'] ?? [] ) as $alt ) {
			$by_id[ (int) ( $alt['image_id'] ?? 0 ) ] = $alt;
		}
		$alts = [];
		foreach ( $images as $img ) {
			$s     = $by_id[ $img['id'] ] ?? null;
			$value = null === $s ? '' : sanitize_text_field( (string) $s['alt'] );
			if ( 'check' === $img['mode'] && ( '' === $value || $value === $img['alt'] ) ) {
				continue; // The existing alt matches the photo: nothing to suggest.
			}
			$alts[] = [
				'id'    => $img['id'],
				'file'  => $img['file'],
				'now'   => $img['alt'],
				'mode'  => $img['mode'],
				'value' => $value,
				'why'   => null === $s ? '' : sanitize_text_field( (string) $s['why'] ),
			];
		}
		$editor = [];
		foreach ( (array) ( $reply['editor'] ?? [] ) as $item ) {
			if ( in_array( $item['area'] ?? '', [ 'headings', 'links', 'content', 'schema' ], true ) && '' !== trim( (string) $item['advice'] ) ) {
				$editor[] = [
					'area'   => $item['area'],
					'advice' => sanitize_text_field( (string) $item['advice'] ),
				];
			}
		}
		$field = static fn( string $key, string $now ) => [
			'now'   => $now,
			'value' => sanitize_text_field( (string) ( $reply[ $key ]['value'] ?? '' ) ),
			'why'   => sanitize_text_field( (string) ( $reply[ $key ]['why'] ?? '' ) ),
		];

		$suggestions = [
			'generated_at' => time(),
			'model'        => $this->claude->get_last_model(),
			'cost'         => round( $this->claude->get_last_cost(), 4 ),
			'title'        => $field( 'title', $current['title'] ),
			'description'  => $field( 'description', $current['description'] ),
			'keyphrase'    => $field( 'keyphrase', $current['keyphrase'] ),
			'alts'         => $alts,
			'editor'       => $editor,
			'applied'      => null,
		];
		$this->store->save_suggestions( $post_id, $suggestions );

		return $suggestions;
	}

	/** Largest image sent to Claude, in bytes. */
	public const MAX_IMAGE_BYTES = 1572864;

	/**
	 * Base64 copies of the images Claude should describe, at a size it can read the scene in (the
	 * "medium_large" size, 768px, about 500 input tokens on Opus 5; then "large", then "medium").
	 *
	 * Read from the uploads folder; when the file is not on disk (a local copy whose uploads are served from
	 * the live site, an offloaded media library) it is fetched from the site's OWN address only. An image
	 * that cannot be loaded is left out, so it is never described blind.
	 *
	 * @param array<int,array<string,mixed>> $images images_needing_alt().
	 * @return array<int,array<string,mixed>> [ id, media_type, data ].
	 */
	protected static function image_blocks( array $images ): array {
		$blocks = [];
		foreach ( $images as $img ) {
			$bytes = self::image_bytes( (int) $img['id'] );
			if ( null === $bytes ) {
				continue;
			}
			$blocks[] = [
				'id'         => (int) $img['id'],
				'media_type' => $bytes['type'],
				'data'       => base64_encode( $bytes['body'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes images as base64.
			];
		}

		return $blocks;
	}

	/**
	 * One attachment's image, at the size Claude reads it: from disk, else from this site's own URL.
	 *
	 * @param int $id Attachment ID.
	 * @return array{type:string,body:string}|null
	 */
	protected static function image_bytes( int $id ): ?array {
		foreach ( [ 'medium_large', 'large', 'medium' ] as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( ! is_array( $src ) || empty( $src[0] ) ) {
				continue;
			}
			$url  = (string) $src[0];
			$type = (string) wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) )['type'];
			if ( ! in_array( $type, self::IMAGE_TYPES, true ) ) {
				return null;
			}
			$path = self::upload_path( $url );
			if ( '' !== $path && is_readable( $path ) && filesize( $path ) <= self::MAX_IMAGE_BYTES ) {
				return [
					'type' => $type,
					'body' => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload, not a URL.
				];
			}
			if ( ! \AJR\SEOAssistant\Scan\Page_Fetcher::is_own( $url ) ) {
				continue; // Never fetch an address off this site.
			}
			$response = wp_remote_get(
				$url,
				[
					'timeout'             => 8,
					'redirection'         => 2,
					'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'limit_response_size' => self::MAX_IMAGE_BYTES,
				]
			);
			$got      = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && 0 === strpos( $got, 'image/' ) ) {
				$body = (string) wp_remote_retrieve_body( $response );
				if ( '' !== $body && strlen( $body ) < self::MAX_IMAGE_BYTES ) {
					return [
						'type' => in_array( strtok( $got, ';' ), self::IMAGE_TYPES, true ) ? (string) strtok( $got, ';' ) : $type,
						'body' => $body,
					];
				}
			}
		}

		return null;
	}

	/**
	 * The uploads-folder path of an uploads URL ('' when it is not one).
	 *
	 * @param string $url Image URL.
	 */
	protected static function upload_path( string $url ): string {
		$uploads = wp_get_upload_dir();
		$base    = (string) preg_replace( '#^https?:#', '', (string) $uploads['baseurl'] );
		$plain   = (string) preg_replace( '#^https?:#', '', strtok( $url, '?' ) );
		if ( '' === $base || 0 !== strpos( $plain, $base ) ) {
			return '';
		}
		$rel = substr( $plain, strlen( $base ) );

		return false !== strpos( $rel, '..' ) ? '' : $uploads['basedir'] . $rel;
	}

	/**
	 * Apply the accepted fields.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $choices title/description/keyphrase => [ 'action' => accept|edit|skip, 'value' => … ],
	 *                                     alts => [ attachment ID => [ 'apply' => bool, 'value' => … ] ].
	 * @param int                 $user_id Who applies.
	 * @return array{batch:string,applied:int}|\WP_Error
	 */
	public function apply( int $post_id, array $choices, int $user_id ) {
		$row = $this->store->get( $post_id );
		if ( null === $row || null === $row['suggestions'] ) {
			return new \WP_Error( 'aisa_review_nothing', __( 'There are no suggestions for this page.', 'ai-seo-assistant' ) );
		}
		$s     = $row['suggestions'];
		$path  = (string) $row['path'];
		$batch = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
		$count = 0;

		foreach ( [ 'title', 'description', 'keyphrase' ] as $field ) {
			$choice = (array) ( $choices[ $field ] ?? [] );
			$action = (string) ( $choice['action'] ?? 'skip' );
			if ( 'skip' === $action || ( 'keyphrase' === $field && ! $this->adapter->supports_keyphrase() ) ) {
				continue;
			}
			$value  = 'edit' === $action ? (string) ( $choice['value'] ?? '' ) : (string) ( $s[ $field ]['value'] ?? '' );
			$value  = 'description' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			$before = $this->read( $field, $post_id );
			if ( '' === trim( $value ) || $value === $before ) {
				continue;
			}
			$this->write( $field, $post_id, $value );
			$this->log->log( $batch, $post_id, $path, $field, $post_id, $before, $value, $user_id );
			++$count;
		}

		foreach ( (array) ( $s['alts'] ?? [] ) as $alt ) {
			$id     = (int) $alt['id'];
			$choice = (array) ( $choices['alts'][ $id ] ?? [] );
			if ( empty( $choice['apply'] ) || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$value  = sanitize_text_field( (string) ( $choice['value'] ?? $alt['value'] ) );
			$before = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( $value ) || $value === $before ) {
				continue;
			}
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $value ) );
			$this->log->log( $batch, $post_id, $path, 'alt', $id, $before, $value, $user_id );
			++$count;
		}

		$s['applied'] = [
			'batch' => $batch,
			'at'    => time(),
			'user'  => $user_id,
			'count' => $count,
		];
		$this->store->save_suggestions( $post_id, $s );
		$this->rescan(
			$post_id,
			[
				'title'       => $this->read( 'title', $post_id ),
				'description' => $this->read( 'description', $post_id ),
			]
		);

		return [
			'batch'   => $batch,
			'applied' => $count,
		];
	}

	/**
	 * Undo one logged change, or a whole batch.
	 *
	 * @param array<int,int> $ids     Change IDs.
	 * @param int            $user_id Who undoes.
	 * @return array{undone:int,kept:array<int,string>}
	 */
	public function undo( array $ids, int $user_id ): array {
		$undone = 0;
		$kept   = [];
		$pages  = [];
		foreach ( $ids as $id ) {
			$row = $this->log->get( (int) $id );
			if ( null === $row || null !== $row['undone_at'] ) {
				continue;
			}
			$now = 'alt' === $row['field'] ? (string) get_post_meta( $row['object_id'], '_wp_attachment_image_alt', true ) : $this->read( $row['field'], $row['post_id'] );
			if ( $now !== $row['after_value'] ) {
				$kept[] = $row['field'];
				continue; // Changed since: never overwrite later work.
			}
			if ( 'alt' === $row['field'] ) {
				update_post_meta( $row['object_id'], '_wp_attachment_image_alt', wp_slash( (string) $row['before_value'] ) );
			} else {
				$this->write( $row['field'], $row['post_id'], (string) $row['before_value'] );
			}
			$this->log->mark_undone( $row['id'], $user_id );
			$pages[ $row['post_id'] ] = true;
			++$undone;
		}
		foreach ( array_keys( $pages ) as $post_id ) {
			$this->rescan(
				(int) $post_id,
				[
					'title'       => $this->read( 'title', (int) $post_id ),
					'description' => $this->read( 'description', (int) $post_id ),
				]
			);
		}

		return [
			'undone' => $undone,
			'kept'   => $kept,
		];
	}

	/**
	 * A field's current stored value through the adapter.
	 *
	 * @param string $field   title | description | keyphrase.
	 * @param int    $post_id Post ID.
	 */
	protected function read( string $field, int $post_id ): string {
		switch ( $field ) {
			case 'title':
				return (string) $this->adapter->get_title( $post_id );
			case 'description':
				return (string) $this->adapter->get_description( $post_id );
			default:
				return (string) $this->adapter->get_keyphrase( $post_id );
		}
	}

	/**
	 * Write a field through the adapter.
	 *
	 * @param string $field   title | description | keyphrase.
	 * @param int    $post_id Post ID.
	 * @param string $value   Value ('' restores an empty field).
	 */
	protected function write( string $field, int $post_id, string $value ): void {
		switch ( $field ) {
			case 'title':
				'' === $value ? delete_post_meta( $post_id, $this->adapter::TITLE_FIELD ) : $this->adapter->save_title( $post_id, $value );
				break;
			case 'description':
				'' === $value ? delete_post_meta( $post_id, $this->adapter::DESCRIPTION_FIELD ) : $this->adapter->save_description( $post_id, $value );
				break;
			default:
				$this->adapter->save_keyphrase( $post_id, $value );
		}
		clean_post_cache( $post_id );
	}

	/** Transient prefix: a page whose rescan is due on its next review-screen load. */
	public const RESCAN_FLAG = 'aisa_rescan_';

	/**
	 * Rescan a page straight after an apply or undo, in this request (WP-Cron is off or slow on many hosts,
	 * and the review must not show the old issues after an Apply).
	 *
	 * One page, short loopback. Yoast rebuilds what it prints in the title tag from the changed meta only at
	 * the END of this request, so the page fetched now still shows the old title and description: the values
	 * just written are known, so they replace the fetched ones ($known). The page is also flagged, so its
	 * review screen's next load rescans it plainly from the fresh render, and a deferred cron event covers a
	 * fetch that failed.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $known   title / description values now stored ('' = not known).
	 */
	protected function rescan( int $post_id, array $known = [] ): void {
		$scanner = new Scanner( $this->store );
		$scanner->scan_page( $post_id, array_filter( $known, static fn( $v ) => '' !== $v && false === strpos( $v, '%%' ) ) );
		$scanner->finalize();
		set_transient( self::RESCAN_FLAG . $post_id, 1, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, \AJR\SEOAssistant\Scan\Scheduler::POST_HOOK, [ $post_id ] );
	}

	/**
	 * Run a flagged rescan (called by the review screen on load, a new request).
	 *
	 * @param int $post_id Post ID.
	 */
	public function rescan_if_flagged( int $post_id ): void {
		if ( false === get_transient( self::RESCAN_FLAG . $post_id ) ) {
			return;
		}
		delete_transient( self::RESCAN_FLAG . $post_id );
		$scanner = new Scanner( $this->store );
		$scanner->scan_page( $post_id );
		$scanner->finalize();
	}

	/**
	 * Estimated cost of generating one page with the current model: the average of this site's own past
	 * reviews (from 3 on), else the measured Opus 5 figure (about 3¢) scaled by the model's price.
	 *
	 * @param array<int,float> $past Past review costs.
	 * @param string           $model Model ID.
	 */
	public static function estimate( array $past, string $model ): float {
		$past = array_values( array_filter( $past, static fn( $c ) => $c > 0 ) );
		if ( count( $past ) >= 3 ) {
			return array_sum( $past ) / count( $past );
		}
		$opus  = Spend::PRICES['claude-opus-5']['out'];
		$price = Spend::PRICES[ Spend::base_model( $model ) ]['out'] ?? $opus;

		return 0.04 * $price / $opus; // Measured Opus 5 page review with photos, 2026-10-06: about 4–5¢.
	}
}
