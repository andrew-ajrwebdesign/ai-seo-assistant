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
	 * Images on the page that need alt text (missing or weak), with an attachment to write to.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,array<string,mixed>>
	 */
	public static function images_needing_alt( array $facts ): array {
		$out  = [];
		$seen = [];
		foreach ( (array) ( $facts['images'] ?? [] ) as $img ) {
			$id  = (int) ( $img['id'] ?? 0 );
			$alt = $img['alt'] ?? null;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( null === $alt || '' === trim( (string) $alt ) || Rules::weak_alt( (string) $alt, (string) ( $img['file'] ?? '' ) ) ) {
				$seen[ $id ] = true;
				$out[]       = [
					'id'   => $id,
					'file' => (string) ( $img['file'] ?? '' ),
					'alt'  => null === $alt ? '' : (string) $alt,
				];
			}
		}

		return array_slice( $out, 0, self::MAX_IMAGES );
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
		$images  = self::images_needing_alt( $facts );
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

		$reply = $this->claude->generate_json( $prompt, ( new Prompt_Builder() )->review_schema(), 'review', self::image_blocks( $images ) );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$by_id = [];
		foreach ( (array) ( $reply['alts'] ?? [] ) as $alt ) {
			$by_id[ (int) ( $alt['image_id'] ?? 0 ) ] = $alt;
		}
		$alts = [];
		foreach ( $images as $img ) {
			$s      = $by_id[ $img['id'] ] ?? null;
			$alts[] = [
				'id'    => $img['id'],
				'file'  => $img['file'],
				'now'   => $img['alt'],
				'value' => null === $s ? '' : sanitize_text_field( (string) $s['alt'] ),
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

	/**
	 * Small base64 copies of the images Claude should describe (the "medium" size when there is one).
	 *
	 * @param array<int,array<string,mixed>> $images images_needing_alt().
	 * @return array<int,array<string,mixed>>
	 */
	protected static function image_blocks( array $images ): array {
		$blocks = [];
		foreach ( $images as $img ) {
			$file = self::small_file( (int) $img['id'] );
			if ( '' === $file ) {
				continue;
			}
			$type = (string) wp_check_filetype( $file )['type'];
			$size = (int) filesize( $file );
			if ( ! in_array( $type, self::IMAGE_TYPES, true ) || $size <= 0 || $size > 1048576 ) {
				continue;
			}
			$blocks[] = [
				'id'         => (int) $img['id'],
				'media_type' => $type,
				'data'       => base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the API takes images as base64; a local upload, not a URL.
			];
		}

		return $blocks;
	}

	/**
	 * Path of an attachment's medium (or smallest available) size, '' when none is on disk.
	 *
	 * @param int $id Attachment ID.
	 */
	protected static function small_file( int $id ): string {
		$full = (string) get_attached_file( $id );
		if ( '' === $full || ! is_readable( $full ) ) {
			return '';
		}
		$meta = wp_get_attachment_metadata( $id );
		foreach ( [ 'medium', 'medium_large', 'thumbnail' ] as $size ) {
			$name = is_array( $meta ) ? ( $meta['sizes'][ $size ]['file'] ?? '' ) : '';
			if ( '' !== $name ) {
				$path = path_join( dirname( $full ), $name );
				if ( is_readable( $path ) ) {
					return $path;
				}
			}
		}

		return $full;
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
		$this->rescan( $post_id );

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
			$this->rescan( (int) $post_id );
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

	/**
	 * Rescan a page right after an apply or undo, so its issues reflect the change.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function rescan( int $post_id ): void {
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

		return 0.03 * $price / $opus;
	}
}
