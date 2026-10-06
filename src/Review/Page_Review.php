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
use AJR\SEOAssistant\Scan\Google_Reads;
use AJR\SEOAssistant\Scan\Html_Parser;
use AJR\SEOAssistant\Scan\Intent;
use AJR\SEOAssistant\Scan\Opportunity;
use AJR\SEOAssistant\Scan\Page_Role;
use AJR\SEOAssistant\Scan\Ranking;
use AJR\SEOAssistant\Scan\Rules;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scanner;
use AJR\SEOAssistant\Scan\Title_Width;
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
	 * ⛔ NEVER REPLACE A GOOD ALT BLIND (Andrew, 2026-10-06: Claude replaced a correct landmark alt with
	 * a guess). Every image is looked at (Claude gets the photo), and its mode decides what Claude may do:
	 * - 'write': neither the page nor the Media Library has a real alt (empty, one short word, the file
	 *   name): Claude writes one from the photo, ticked to apply.
	 * - 'sync':  the page prints something other than the Media Library's alt (a builder module with its own
	 *   alt field, e.g. Divi printing "Our Services" on a kitchen photo): Claude says which is
	 *   right for the photo, or corrects both; ticked only when what the page prints is empty or poor.
	 * - 'check': the page prints the Media Library's good alt: Claude only says whether it is WRONG for the
	 *   photo (never restyles it); a correction is shown unticked beside the old alt.
	 *
	 * @param array<string,mixed> $facts Facts (images carry `alt` as printed, `stored_alt` from the Media Library, `src`).
	 * @return array<int,array<string,mixed>>
	 */
	public static function images_needing_alt( array $facts ): array {
		$out    = [];
		$seen   = [];
		$shared = Rules::shared_alts( (array) ( $facts['images'] ?? [] ) );
		foreach ( (array) ( $facts['images'] ?? [] ) as $img ) {
			$id = (int) ( $img['id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) || ! empty( $img['decorative'] ) ) {
				continue; // role="presentation" / aria-hidden: decoration on purpose, no alt owed.
			}
			$seen[ $id ] = true;
			$file        = (string) ( $img['file'] ?? '' );
			$printed     = trim( (string) ( $img['alt'] ?? '' ) );
			$stored      = trim( (string) ( $img['stored_alt'] ?? '' ) );
			// One alt pasted onto several different photos describes at most one of them: poor for all.
			$printed_good = self::good_alt( $printed, $file ) && ! isset( $shared[ Rules::alt_key( $printed ) ] );
			$stored_good  = self::good_alt( $stored, $file ) && ! isset( $shared[ Rules::alt_key( $stored ) ] );
			if ( ! $printed_good && ! $stored_good ) {
				$mode = 'write';
			} elseif ( self::same_alt( $printed, $stored ) || ( $printed_good && '' === $stored ) ) {
				$mode = 'check';
			} else {
				$mode = 'sync';
			}
			$out[] = [
				'id'      => $id,
				'file'    => $file,
				'src'     => (string) ( $img['src'] ?? '' ),
				'printed' => $printed,
				'stored'  => $stored,
				'alt'     => $printed_good ? $printed : ( $stored_good ? $stored : ( '' !== $stored ? $stored : $printed ) ),
				'mode'    => $mode,
				'tick'    => 'write' === $mode || ( 'sync' === $mode && ! $printed_good ),
			];
		}
		// Missing alts first, then what the page shows wrong; the cap keeps a review's cost predictable.
		$rank = [
			'write' => 0,
			'sync'  => 1,
			'check' => 2,
		];
		usort( $out, static fn( $a, $b ) => $rank[ $a['mode'] ] <=> $rank[ $b['mode'] ] );

		return array_slice( $out, 0, self::MAX_IMAGES );
	}

	/**
	 * Whether two alt texts say the same thing (case, spacing and quote style ignored).
	 *
	 * @param string $a One.
	 * @param string $b Other.
	 */
	public static function same_alt( string $a, string $b ): bool {
		return Rules::alt_key( $a ) === Rules::alt_key( $b );
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
		if ( '' !== (string) $post->post_password ) {
			// A password-protected page's words are private: never sent to Claude.
			return new \WP_Error( 'aisa_review_protected', __( 'This page is password-protected, so it is not reviewed.', 'ai-seo-assistant' ) );
		}
		if ( ! $this->claude->has_api_key() ) {
			return new \WP_Error( 'aisa_review_no_key', __( 'Add the Claude API key in Settings first.', 'ai-seo-assistant' ) );
		}
		$facts = $row['facts'];
		$page  = ( new Page_Data() )->get( (string) ( $facts['url'] ?? get_permalink( $post ) ) );
		// Only images Claude can SEE are sent for alt text: one that cannot be loaded is left out, never guessed.
		$blocks  = self::image_blocks( self::images_needing_alt( $facts ) );
		$ids     = array_column( $blocks, 'id' );
		$images  = array_values( array_filter( self::images_needing_alt( $facts ), static fn( $i ) => in_array( $i['id'], $ids, true ) ) );
		$content = self::prompt_content( $post_id, $row );
		$current = [
			'title'       => (string) ( $facts['title'] ?? '' ),
			'description' => (string) ( $facts['description'] ?? '' ),
			'keyphrase'   => (string) $this->adapter->get_keyphrase( $post_id ),
		];

		if ( null !== $page ) {
			Ranking::curve(); // The site's own expected CTR, as the scan uses.
		}
		$suffix  = $this->title_suffix();
		$context = self::editor_context( $post_id, $row );
		$prompt  = ( new Prompt_Builder() )->build_review_prompt(
			[
				'post_title'          => wp_strip_all_tags( get_the_title( $post ) ),
				'permalink'           => (string) get_permalink( $post ),
				'content'             => $content,
				'current'             => $current,
				'queries'             => array_map( static fn( $q ) => $q + [ 'intent' => Intent::of( (string) ( $q['query'] ?? '' ) ) ], (array) ( $page['gsc']['queries'] ?? [] ) ),
				'main_query'          => Scanner::main_query( $page, wp_strip_all_tags( get_the_title( $post ) ) . ' ' . str_replace( [ '/', '-' ], ' ', (string) $row['path'] ) ),
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
				'issues'              => array_map( static fn( $i ) => $i['title'] . ' — ' . $i['fix'], array_filter( (array) $row['issues'], static fn( $i ) => 'schema' !== ( $i['kind'] ?? '' ) ) ),
				'page_type'           => self::page_type_label( $post_id ),
				'business_node'       => self::business_node( $facts ),
				'images'              => $images,
				'headings'            => array_map( static fn( $h ) => 'H' . $h['l'] . ' ' . $h['t'], array_slice( (array) ( $facts['headings'] ?? [] ), 0, 20 ) ),
				'inbound'             => array_map( static fn( $l ) => $l[0] . ' | ' . $l[1], array_slice( $context['inbound'], 0, 30 ) ),
				'keyphrase_supported' => (bool) $this->adapter->supports_keyphrase(),
				'seo_plugin'          => (string) $this->adapter->get_name(),
				'title_suffix'        => $suffix,
				'include_brand'       => (string) get_option( 'ai_seo_assistant_include_brand', 'no' ),
				'avoid_phrases'       => sanitize_text_field( (string) get_option( 'ai_seo_assistant_avoid_phrases', '' ) ),
				'siblings'            => $this->siblings( $post_id, (array) $row['issues'], $facts ),
				'page_notes'          => self::page_notes( $post_id ),
			]
		);

		$reply = $this->claude->generate_json( $prompt, ( new Prompt_Builder() )->review_schema(), 'review', $blocks );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		// Checked here, not trusted: a title too wide for Google or a description out of range gets ONE
		// retry, text only (no photos), and only the title and description are taken from it.
		$problems = self::listing_problems( (string) ( $reply['title']['value'] ?? '' ), (string) ( $reply['description']['value'] ?? '' ), $suffix );
		if ( [] !== $problems ) {
			$retry = $this->claude->generate_json( $prompt . "\n\nYour previous answer broke these limits: " . implode( ' ', $problems ) . ' Return the whole answer again with them fixed (alts may be empty).', ( new Prompt_Builder() )->review_schema(), 'review' );
			if ( ! is_wp_error( $retry ) && count( self::listing_problems( (string) ( $retry['title']['value'] ?? '' ), (string) ( $retry['description']['value'] ?? '' ), $suffix ) ) < count( $problems ) ) {
				$reply['title']       = $retry['title'];
				$reply['description'] = $retry['description'];
			}
		}

		$by_id = [];
		foreach ( (array) ( $reply['alts'] ?? [] ) as $alt ) {
			$by_id[ (int) ( $alt['image_id'] ?? 0 ) ] = $alt;
		}
		$alts = [];
		foreach ( $images as $img ) {
			$s     = $by_id[ $img['id'] ] ?? null;
			$value = null === $s ? '' : sanitize_text_field( (string) $s['alt'] );
			if ( 'check' === $img['mode'] && ( '' === $value || self::same_alt( $value, $img['alt'] ) ) ) {
				continue; // The existing alt matches the photo: nothing to suggest.
			}
			if ( 'sync' === $img['mode'] && ( '' === $value || self::same_alt( $value, $img['printed'] ) ) ) {
				continue; // What the page prints is right for the photo.
			}
			$alts[] = [
				'id'      => $img['id'],
				'file'    => $img['file'],
				'src'     => $img['src'],
				'now'     => $img['alt'],
				'printed' => $img['printed'],
				'stored'  => $img['stored'],
				'mode'    => $img['mode'],
				'tick'    => $img['tick'],
				'value'   => $value,
				'why'     => null === $s ? '' : sanitize_text_field( (string) $s['why'] ),
			];
		}
		$editor = self::fresh_advice( (array) ( $reply['editor'] ?? [] ), $context );
		$field  = static fn( string $key, string $now ) => [
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
					'redirection'         => 0, // Never followed: a redirect is reported as one, and cannot lead off this site.
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

		// Alt text goes where the page PRINTS it (the post content: a Divi module's alt, an Image block's or
		// classic <img>'s alt) AND to the Media Library. The content is written once, after every splice.
		$content   = (string) get_post_field( 'post_content', $post_id, 'raw' );
		$new       = $content;
		$alt_done  = [];
		$page_only = 0;
		foreach ( (array) ( $s['alts'] ?? [] ) as $alt ) {
			$id     = (int) $alt['id'];
			$choice = (array) ( $choices['alts'][ $id ] ?? [] );
			if ( empty( $choice['apply'] ) || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) ( $choice['value'] ?? $alt['value'] ) );
			if ( '' === trim( $value ) ) {
				continue;
			}
			$before = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( $value !== $before ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $value ) );
				$this->log->log( $batch, $post_id, $path, 'alt', $id, $before, $value, $user_id );
				++$count;
			} else {
				++$page_only; // The library was already right; only the page changes.
			}
			$splice          = Alt_Writer::splice( $new, $id, (string) ( $alt['src'] ?? $alt['file'] ?? '' ), $value );
			$new             = $splice['content'];
			$alt_done[ $id ] = [
				'value'   => $value,
				'matches' => $splice['matches'],
			];
		}
		$content_saved = null;
		if ( $new !== $content ) {
			// Log FIRST: a content change that could not be logged could not be undone, so it is not made.
			$log_id        = $this->log->log( $batch, $post_id, $path, 'content', $post_id, $content, $new, $user_id, Change_Log::content_summary( $content, $new ) );
			$content_saved = 0 === $log_id ? 'not-logged' : $this->write_content( $post_id, $content, $new );
			if ( true === $content_saved ) {
				$count += $page_only;
			} elseif ( $log_id > 0 ) {
				$this->log->discard( $log_id ); // Nothing changed on the page: no row to undo.
			}
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

		// Verify on the RENDERED page (the rescan just fetched it): an alt the page does not print is
		// reported as not visible, with the reason, never as applied.
		$s['applied']['alts'] = $this->verify_alts( $post_id, $alt_done, $content_saved );
		$this->store->save_suggestions( $post_id, $s );

		return [
			'batch'   => $batch,
			'applied' => $count,
		];
	}

	/**
	 * Save new post content, guarded: the agency user must be allowed unfiltered HTML (so kses cannot
	 * rewrite a builder's markup), and the stored content must read back byte-identical, else the original
	 * is put back.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $before  Content before.
	 * @param string $after   Content after.
	 * @return bool|string true when saved; 'not-allowed' | 'changed' | 'restore-failed' when not.
	 */
	protected function write_content( int $post_id, string $before, string $after ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return 'not-allowed';
		}
		if ( (string) get_post_field( 'post_content', $post_id, 'raw' ) !== $before ) {
			return 'changed';
		}
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $after ),
			]
		);
		clean_post_cache( $post_id );
		if ( (string) get_post_field( 'post_content', $post_id, 'raw' ) !== $after ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $before ),
				]
			);
			clean_post_cache( $post_id );
			// The put-back is checked too: if even that does not read back, say so (the revisions hold it).
			return (string) get_post_field( 'post_content', $post_id, 'raw' ) === $before ? 'changed' : 'restore-failed';
		}

		return true;
	}

	/**
	 * Whether each applied alt now shows on the page, and if not, why.
	 *
	 * @param int                                        $post_id Post ID.
	 * @param array<int,array{value:string,matches:int}> $done    Alts applied, by attachment ID.
	 * @param bool|string|null                           $saved   write_content()'s result (null: no content change).
	 * @return array<int,array{visible:bool,reason:string}>
	 */
	protected function verify_alts( int $post_id, array $done, $saved ): array {
		$row     = $this->store->get( $post_id );
		$printed = [];
		foreach ( (array) ( $row['facts']['images'] ?? [] ) as $img ) {
			$printed[ (int) ( $img['id'] ?? 0 ) ] = (string) ( $img['alt'] ?? '' );
		}
		$out = [];
		foreach ( $done as $id => $alt ) {
			$visible = isset( $printed[ $id ] ) && self::same_alt( $printed[ $id ], $alt['value'] );
			$reason  = '';
			if ( ! $visible ) {
				if ( 0 === $alt['matches'] ) {
					$reason = __( 'Saved in the Media Library, but the image could not be found in the page content (a theme or global module prints it): set its alt there.', 'ai-seo-assistant' );
				} elseif ( $alt['matches'] > 1 ) {
					$reason = __( 'The image appears more than once in the content, so the page was left alone: set the alt on each in the editor.', 'ai-seo-assistant' );
				} elseif ( 'not-allowed' === $saved ) {
					$reason = __( 'Your account may not save raw HTML on this site, so the page content was left alone.', 'ai-seo-assistant' );
				} elseif ( 'changed' === $saved ) {
					$reason = __( 'The page content changed while applying, so it was left alone. Try again.', 'ai-seo-assistant' );
				} elseif ( 'not-logged' === $saved ) {
					$reason = __( 'The change could not be saved in the change log (so it could not be undone), so the page content was left alone.', 'ai-seo-assistant' );
				} elseif ( 'restore-failed' === $saved ) {
					$reason = __( 'Saving the page went wrong and putting it back could not be confirmed: check the page, and restore it from Revisions in the editor if needed.', 'ai-seo-assistant' );
				} else {
					$reason = __( 'The page still shows a different alt (a cache, or a module printing its own).', 'ai-seo-assistant' );
				}
			}
			$out[ $id ] = [
				'visible' => $visible,
				'reason'  => $reason,
			];
		}

		return $out;
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
			if ( 'content' === $row['field'] ) {
				// The post content is restored only while it is exactly what the apply left (byte for byte).
				$restored = $this->write_content( $row['post_id'], (string) $row['after_value'], (string) $row['before_value'] );
				if ( true !== $restored ) {
					$kept[] = 'restore-failed' === $restored ? 'content-restore-failed' : $row['field'];
					continue;
				}
				$this->log->mark_undone( $row['id'], $user_id );
				$pages[ $row['post_id'] ] = true;
				++$undone;
				continue;
			}
			if ( \AJR\SEOAssistant\Scan\Auto_Types::FIELD === $row['field'] ) {
				// Back to the type before (usually none). AJR Core's set() marks the page manual, so an automatic
				// type that was undone is never set again.
				if ( Page_Role::type_of( (int) $row['post_id'] ) !== (string) $row['after_value'] || ! Page_Role::set_type( (int) $row['post_id'], (string) $row['before_value'] ) ) {
					$kept[] = $row['field'];
					continue;
				}
				$this->log->mark_undone( $row['id'], $user_id );
				++$undone; // No rescan here: a batch of 126 would mean 126 page fetches in one request.
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
		$scanner->finalize( false ); // No link checks or sitemap fetch in the agency's request.
		set_transient( self::RESCAN_FLAG . $post_id, 1, HOUR_IN_SECONDS ); // The review rescans once more on its next load (Yoast's indexable is rebuilt at shutdown).
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
		$scanner->finalize( false );
	}

	/**
	 * What is wrong with a suggested title and description against Google's limits (none: []). The title is
	 * measured with what the SEO plugin appends to it.
	 *
	 * @param string $title       Suggested title.
	 * @param string $description Suggested description.
	 * @param string $suffix      What the SEO plugin appends ('' when nothing).
	 * @return array<int,string> One sentence per problem, for the retry prompt.
	 */
	public static function listing_problems( string $title, string $description, string $suffix = '' ): array {
		$out = [];
		$px  = Title_Width::px( $title . $suffix );
		if ( $px > Title_Width::LIMIT_PX ) {
			$out[] = sprintf( 'The title%s is %d px wide; it must fit %d px: shorten it.', '' !== $suffix ? ' (with "' . $suffix . '" added)' : '', $px, Title_Width::LIMIT_PX );
		}
		$len = mb_strlen( $description );
		if ( $len > Rules::DESC_MAX ) {
			$out[] = sprintf( 'The description is %d characters; it must be at most %d.', $len, Rules::DESC_MAX );
		} elseif ( $len > 0 && $len < Rules::DESC_MIN ) {
			$out[] = sprintf( 'The description is %d characters; it must be at least %d.', $len, Rules::DESC_MIN );
		}

		return $out;
	}

	/**
	 * What the page is now, for checking editor advice: its latest facts, its text (and whether the scan
	 * cut it short), and the links to it from the other scanned pages ([ from path, link text ]), as the
	 * last site-wide pass stored them for this page alone (scan.inbound): never every page's facts on an
	 * editor load. Before the first pass has stored them the list is empty, so a link to-do stays a
	 * manual tick (never marked done by guess).
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $row     Its scan row (Scan_Store::get(), with text).
	 * @return array{facts:array<string,mixed>,text:string,truncated:bool,inbound:array<int,array{0:string,1:string}>,inbound_complete:bool}
	 */
	public static function editor_context( int $post_id, array $row ): array {
		$facts   = (array) ( $row['facts'] ?? [] );
		$inbound = [];
		foreach ( (array) ( $row['inbound'] ?? [] ) as $link ) {
			if ( is_array( $link ) ) {
				$inbound[] = [ (string) ( $link[0] ?? '' ), (string) ( $link[1] ?? '' ) ];
			}
		}

		return [
			'facts'            => $facts,
			'text'             => self::page_text( $post_id, $row ),
			'truncated'        => ! empty( $facts[ Html_Parser::TRUNCATED ] ),
			'inbound'          => array_slice( $inbound, 0, Scanner::MAX_INBOUND ),
			// Not built yet, or more links than the pass keeps: a link not in the list may still exist.
			'inbound_complete' => is_array( $row['inbound'] ?? null ) && count( $inbound ) <= Scanner::MAX_INBOUND,
		];
	}

	/**
	 * The page's words as sent to Claude for the review: page_text(), cut to 8,000 characters.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $row     Its scan row.
	 */
	public static function prompt_content( int $post_id, array $row ): string {
		return Utils::cut_words( self::page_text( $post_id, $row ), 8000 ); // Plain already: never through wp_strip_all_tags() again.
	}

	/**
	 * The page's visible words, plain: the scan's rendered text (a page builder's modules ran, so a Divi
	 * text module's body and a blurb's title= are there), or, for a row scanned before 5.0 stored it, the
	 * title and content through Utils::visible_text(). Never strip_shortcodes(): with Divi's modules
	 * registered it deletes every module's text and the page reads as empty.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $row     Its scan row.
	 */
	public static function page_text( int $post_id, array $row ): string {
		$text = (string) ( $row['body_text'] ?? '' );

		return '' !== trim( $text ) ? $text : (string) ( new Content_Extractor() )->get_content( $post_id );
	}

	/**
	 * The reply's editor advice to keep: headings, links and content only (never schema), cleaned, and
	 * nothing that is already true on the page (Claude can suggest the H1 the page already has).
	 *
	 * @param array<int,mixed>    $items   The reply's editor items.
	 * @param array<string,mixed> $context editor_context().
	 * @return array<int,array<string,string>>
	 */
	public static function fresh_advice( array $items, array $context ): array {
		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! in_array( $item['area'] ?? '', [ 'headings', 'links', 'content' ], true ) || '' === trim( (string) ( $item['advice'] ?? '' ) ) ) {
				continue;
			}
			$advice = [
				'area'   => (string) $item['area'],
				'advice' => sanitize_text_field( (string) $item['advice'] ),
				'check'  => in_array( $item['check'] ?? '', Editor_Check::CHECKS, true ) ? (string) $item['check'] : 'none',
				'target' => sanitize_text_field( (string) ( $item['target'] ?? '' ) ),
				'source' => sanitize_text_field( (string) ( $item['source'] ?? '' ) ),
			];
			if ( self::advice_in_place( $advice, $context ) ) {
				continue; // Already true on the page: not shown.
			}
			$out[] = $advice;
		}

		return $out;
	}

	/**
	 * Whether one piece of editor advice is already in place (Editor_Check).
	 *
	 * @param array<string,mixed> $advice  { area, advice, check?, target?, source? }.
	 * @param array<string,mixed> $context editor_context().
	 */
	public static function advice_in_place( array $advice, array $context ): bool {
		return Editor_Check::YES === self::advice_verdict( $advice, $context );
	}

	/**
	 * Editor_Check::verdict() for one piece of advice on the page as last scanned.
	 *
	 * @param array<string,mixed> $advice  Advice.
	 * @param array<string,mixed> $context editor_context().
	 */
	public static function advice_verdict( array $advice, array $context ): string {
		return Editor_Check::verdict( Editor_Check::of( $advice ), (array) $context['facts'], (string) $context['text'], (array) $context['inbound'], ! empty( $context['truncated'] ), (bool) ( $context['inbound_complete'] ?? true ) );
	}

	/**
	 * Why a to-do cannot be checked automatically, for the editor panel and the review's editor box ('' when
	 * it can be, or there is nothing to say).
	 *
	 * @param array<string,mixed> $advice  Advice.
	 * @param array<string,mixed> $context editor_context().
	 */
	public static function unknown_note( array $advice, array $context ): string {
		if ( Editor_Check::UNKNOWN !== self::advice_verdict( $advice, $context ) ) {
			return '';
		}
		$check = Editor_Check::of( $advice )['check'];
		if ( 'phrase' === $check && ! empty( $context['truncated'] ) ) {
			return __( 'This page is too long to check automatically: tick Done once it is there.', 'ai-seo-assistant' );
		}
		if ( 'link' === $check && empty( $context['inbound_complete'] ) ) {
			return __( 'Not every link to this page could be checked: tick Done once the link is there.', 'ai-seo-assistant' );
		}

		return '';
	}

	/**
	 * The page's notes from the 4.x editor box ("Local SEO Focus": service, places, intent, notes), as
	 * lines for the prompt. No longer shown or saved, but read until someone clears them: what a person
	 * typed is never thrown away.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,string>
	 */
	public static function page_notes( int $post_id ): array {
		$labels = [
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_SERVICE_FOCUS       => 'Service focus',
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_PRIMARY_LOCATION    => 'Main place',
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_SECONDARY_LOCATIONS => 'Other places',
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_SEARCH_INTENT       => 'Search intent',
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_PRIORITY            => 'Priority',
			\AJR\SEOAssistant\Content\Local_SEO_Context::META_PAGE_NOTES          => 'Notes',
		];
		$out    = [];
		foreach ( $labels as $key => $label ) {
			$value = trim( sanitize_textarea_field( (string) get_post_meta( $post_id, $key, true ) ) );
			if ( '' !== $value ) {
				$out[] = $label . ': ' . mb_substr( preg_replace( '/\s+/u', ' ', $value ), 0, 300 );
			}
		}

		return $out;
	}

	/**
	 * What the SEO plugin appends to every title by itself ('' when nothing): The SEO Framework adds the
	 * site name unless its "remove site title" setting is on.
	 */
	protected function title_suffix(): string {
		if ( 'The SEO Framework' !== (string) $this->adapter->get_name() ) {
			return '';
		}
		$tsf = get_option( 'autodescription-site-settings', [] );
		if ( is_array( $tsf ) && ! empty( $tsf['title_rem_additions'] ) ) {
			return '';
		}
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		return '' === $name ? '' : ' | ' . $name;
	}

	/**
	 * For a duplicate title or description: the other pages that share it ("title — /path/"), at most 5.
	 *
	 * @param int                            $post_id This page.
	 * @param array<int,array<string,mixed>> $issues  Its scan issues.
	 * @param array<string,mixed>            $facts   Its facts.
	 * @return array<int,string>
	 */
	protected function siblings( int $post_id, array $issues, array $facts ): array {
		$codes = array_column( $issues, 'code' );
		if ( ! in_array( 'title_duplicate', $codes, true ) && ! in_array( 'desc_duplicate', $codes, true ) ) {
			return [];
		}
		$title = mb_strtolower( (string) ( $facts['title'] ?? '' ) );
		$desc  = mb_strtolower( (string) ( $facts['description'] ?? '' ) );
		$out   = [];
		foreach ( $this->store->all_facts() as $id => $row ) {
			if ( (int) $id === $post_id ) {
				continue;
			}
			$same_t = '' !== $title && mb_strtolower( (string) ( $row['facts']['title'] ?? '' ) ) === $title;
			$same_d = '' !== $desc && mb_strtolower( (string) ( $row['facts']['description'] ?? '' ) ) === $desc;
			if ( $same_t || $same_d ) {
				$out[] = wp_strip_all_tags( (string) get_the_title( (int) $id ) ) . ' — ' . (string) $row['path'] . ( $same_t ? ' (same title)' : ' (same description)' );
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The page's AJR Core page type in words for the prompt ('' when not set).
	 *
	 * @param int $post_id Post ID.
	 */
	protected static function page_type_label( int $post_id ): string {
		$type = Page_Role::type_of( $post_id );
		if ( '' === $type ) {
			return '';
		}
		$types = Page_Role::types();

		return (string) ( $types[ $type ]['label'] ?? $type );
	}

	/**
	 * The business node the page prints, in one line (type and name), for the prompt; '' when none.
	 *
	 * @param array<string,mixed> $facts Scan facts.
	 */
	protected static function business_node( array $facts ): string {
		foreach ( (array) ( $facts['schema_nodes'] ?? [] ) as $node ) {
			if ( in_array( $node['type'], Google_Reads::BUSINESS_TYPES, true ) || str_ends_with( (string) $node['id'], '#organization' ) ) {
				return trim( $node['type'] . ' ' . $node['name'] );
			}
		}

		return '';
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
