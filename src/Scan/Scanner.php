<?php
/**
 * Scanner — runs the SEO scan: per-page facts, then the site-wide pass that turns facts into issues.
 *
 * TWO PHASES, so a 500-page site never has to finish in one request:
 *
 * 1. scan_page( $id ): fetch the page as served (Page_Fetcher), read its facts (Html_Parser), store them.
 *    When the fetch fails it reads the post content and the SEO plugin's fields instead and records why,
 *    so the screen can say "checked from the post content" for that page. Scheduler calls this in batches.
 * 2. finalize(): with every page's facts in hand, work out what needs the whole site (duplicate titles and
 *    descriptions, links into each page, broken links and links through AJR Core's redirects, the sitemap,
 *    each page's main search from the pushed data, whether it is a service page) and store each page's
 *    issues (Rules). No page is fetched here except at most MAX_LINK_CHECKS unknown link targets (HEAD).
 *
 * Runs in cron or on the agency's "Rescan now" requests only; nothing here runs for a visitor.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

use AJR\SEOAssistant\Adapters\RankMath_Adapter;
use AJR\SEOAssistant\Adapters\SEO_Adapter_Resolver;
use AJR\SEOAssistant\Adapters\TSF_Adapter;
use AJR\SEOAssistant\Adapters\Yoast_Adapter;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO scan.
 */
class Scanner {

	/** Most unknown internal link targets checked (HEAD) in one site-wide pass. */
	public const MAX_LINK_CHECKS = 40;

	/** Transient: link target path => HTTP status, kept a day. */
	public const LINK_CACHE = 'aisa_scan_link_status';

	/** Transient: the sitemap's URLs (bare), kept an hour. */
	public const SITEMAP_CACHE = 'aisa_scan_sitemap';

	/** Most pages scanned (the queries elsewhere cap lists at 500; the scan allows a little more). */
	public const MAX_PAGES = 1000;

	/**
	 * Storage.
	 *
	 * @var Scan_Store
	 */
	protected Scan_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Scan_Store|null $store Storage.
	 */
	public function __construct( ?Scan_Store $store = null ) {
		$this->store = $store ?? new Scan_Store();
	}

	/**
	 * Post types the scan covers: the editor box's setting (default post + page).
	 *
	 * @return array<int,string>
	 */
	public static function post_types(): array {
		$types = get_option( 'ai_seo_assistant_post_types', [ 'post', 'page' ] );
		$types = is_array( $types ) && [] !== $types ? array_values( array_filter( array_map( 'sanitize_key', $types ) ) ) : [ 'post', 'page' ];

		/**
		 * Filters the post types the SEO scan covers.
		 *
		 * @param array<int,string> $types Post types.
		 */
		return (array) apply_filters( 'ai_seo_assistant_scan_post_types', $types );
	}

	/**
	 * Every published page the scan covers, newest change first.
	 *
	 * @return array<int,int>
	 */
	public static function published_ids(): array {
		$query = new \WP_Query(
			[
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::MAX_PAGES,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The active SEO plugin's adapter (The SEO Framework when none is detected).
	 *
	 * @return object
	 */
	public static function adapter() {
		$tsf = new TSF_Adapter();

		return ( new SEO_Adapter_Resolver( $tsf, new Yoast_Adapter(), new RankMath_Adapter() ) )->get_adapter() ?? $tsf;
	}

	/**
	 * Phase 1: read and store one page's facts.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $known   Title / description known to be stored now, which replace the
	 *                                      fetched ones (Page_Review: Yoast prints a changed title only from
	 *                                      the next request on).
	 * @return string 'rendered' | 'content' | 'skipped'
	 */
	public function scan_page( int $post_id, array $known = [] ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			$this->store->delete( $post_id );
			return 'skipped';
		}
		$url     = (string) get_permalink( $post );
		$fetched = Page_Fetcher::fetch( $url );
		if ( $fetched['ok'] ) {
			$facts  = Html_Parser::parse( $fetched['html'], home_url( '/' ) );
			$source = 'rendered';
		} else {
			$facts                = $this->from_content( $post );
			$facts['fetch_error'] = $fetched['error'];
			$source               = 'content';
		}
		foreach ( [ 'title', 'description' ] as $field ) {
			if ( isset( $known[ $field ] ) && '' !== $known[ $field ] ) {
				$facts[ $field ] = Html_Parser::clean( $known[ $field ] );
			}
		}
		$facts['url']      = $url;
		$facts['images']   = $this->resolve_images( (array) $facts['images'] );
		$facts['modified'] = (string) $post->post_modified_gmt;

		$this->store->save_facts( $post_id, Page_Data::path_of( $url ), $post->post_type, $source, $facts );

		return $source;
	}

	/**
	 * Facts from the post content and the SEO plugin's fields, when the rendered page cannot be fetched.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	protected function from_content( \WP_Post $post ): array {
		$adapter = self::adapter();
		$html    = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, rendering the content as the theme would.
		$facts   = Html_Parser::parse( '<html><head></head><body><main><h1>' . esc_html( get_the_title( $post ) ) . '</h1>' . $html . '</main></body></html>', home_url( '/' ) );

		$title = (string) $adapter->get_title( $post->ID );
		$desc  = (string) $adapter->get_description( $post->ID );
		// SEO plugins store templates such as "%%title%% %%sep%% %%sitename%%"; the scan cannot expand them
		// without rendering, so it uses what WordPress would print by default instead.
		if ( '' === $title || false !== strpos( $title, '%%' ) ) {
			$title = wp_strip_all_tags( get_the_title( $post ) ) . ' - ' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		$facts['title']       = Html_Parser::clean( $title );
		$facts['description'] = false !== strpos( $desc, '%%' ) ? '' : Html_Parser::clean( $desc );
		$facts['noindex']     = method_exists( $adapter, 'is_noindex' ) && (bool) $adapter->is_noindex( $post->ID );
		$facts['og_image']    = has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'full' ) : '';
		$facts['schema']      = [ 'unknown' ]; // Schema is printed by plugins on the rendered page only: not judged from content.

		return $facts;
	}

	/**
	 * Attachment IDs and file weight for each content image.
	 *
	 * @param array<int,array<string,mixed>> $images Images from the parser.
	 * @return array<int,array<string,mixed>>
	 */
	protected function resolve_images( array $images ): array {
		$uploads = wp_get_upload_dir();
		$base    = (string) $uploads['baseurl'];
		foreach ( $images as $i => $img ) {
			$src = (string) $img['src'];
			if ( 0 === strpos( $src, '//' ) ) {
				$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
			}
			if ( empty( $img['id'] ) && false !== strpos( $src, '/uploads/' ) ) {
				$full               = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', strtok( $src, '?' ) );
				$id                 = attachment_url_to_postid( $full );
				$images[ $i ]['id'] = $id ? $id : (int) attachment_url_to_postid( strtok( $src, '?' ) );
			}
			// The served file's weight: the uploads URL maps onto the uploads folder.
			$kb  = 0;
			$rel = '' !== $base && 0 === strpos( preg_replace( '#^https?:#', '', $src ), preg_replace( '#^https?:#', '', $base ) ) ? substr( preg_replace( '#^https?:#', '', strtok( $src, '?' ) ), strlen( preg_replace( '#^https?:#', '', $base ) ) ) : '';
			if ( '' !== $rel && false === strpos( $rel, '..' ) ) {
				$file = $uploads['basedir'] . $rel;
				$kb   = is_readable( $file ) ? (int) round( filesize( $file ) / 1024 ) : 0;
			}
			$images[ $i ]['kb'] = $kb;
			// The Media Library's own alt, so a good one is never replaced and a builder that does not print it is flagged.
			$images[ $i ]['stored_alt'] = ! empty( $images[ $i ]['id'] ) ? mb_substr( trim( (string) get_post_meta( (int) $images[ $i ]['id'], '_wp_attachment_image_alt', true ) ), 0, 200 ) : '';
		}

		return $images;
	}

	/**
	 * Phase 2: every page's issues, from every page's facts.
	 *
	 * @return array{pages:int,issues:int,rendered:int,fallback:int}
	 */
	public function finalize(): array {
		$rows   = $this->store->all_facts();
		$data   = ( new Page_Data() )->all();
		$titles = [];
		$descs  = [];
		$in     = [];
		$links  = [];
		foreach ( $rows as $id => $row ) {
			$f = $row['facts'];
			$t = mb_strtolower( (string) ( $f['title'] ?? '' ) );
			$d = mb_strtolower( (string) ( $f['description'] ?? '' ) );
			if ( '' !== $t ) {
				$titles[ $t ][] = $id;
			}
			if ( '' !== $d ) {
				$descs[ $d ][] = $id;
			}
			foreach ( array_unique( array_column( (array) ( $f['links'] ?? [] ), 'p' ) ) as $path ) {
				$path = self::norm_path( (string) $path );
				if ( $path !== self::norm_path( $row['path'] ) ) {
					$in[ $path ][]  = $row['path'];
					$links[ $path ] = true;
				}
			}
		}

		$known   = [];
		$by_path = [];
		foreach ( $rows as $id => $row ) {
			$known[ self::norm_path( $row['path'] ) ]   = true;
			$by_path[ self::norm_path( $row['path'] ) ] = $id;
		}
		$redirects  = self::redirect_map();
		$link_state = $this->check_unknown_links( array_keys( $links ), $known, $redirects );
		$sitemap    = $this->sitemap();
		$services   = self::service_names();
		$schema_on  = self::custom_schema_on();
		$front      = (int) get_option( 'page_on_front' );
		$discourage = '0' === (string) get_option( 'blog_public', '1' );
		$popular    = self::popular_paths( $data );

		$total    = 0;
		$rendered = 0;
		foreach ( $rows as $id => $row ) {
			$f    = $row['facts'];
			$path = self::norm_path( $row['path'] );
			$page = $data[ $row['path'] ] ?? $data[ trailingslashit( $row['path'] ) ] ?? $data[ untrailingslashit( $row['path'] ) ] ?? null;
			$ctx  = [
				'post_type'        => $row['post_type'],
				'title_dupes'      => count( $titles[ mb_strtolower( (string) ( $f['title'] ?? '' ) ) ] ?? [] ) - 1,
				'desc_dupes'       => '' === (string) ( $f['description'] ?? '' ) ? 0 : count( $descs[ mb_strtolower( (string) $f['description'] ) ] ?? [] ) - 1,
				'inbound'          => count( array_unique( $in[ $path ] ?? [] ) ),
				'suggest_from'     => array_slice( array_values( array_diff( $popular, $in[ $path ] ?? [], [ $row['path'] ] ) ), 0, 2 ),
				'broken'           => [],
				'redirected'       => [],
				'in_sitemap'       => null === $sitemap ? null : isset( $sitemap[ Rules::bare_url( (string) ( $f['url'] ?? '' ) ) ] ),
				'self_url'         => (string) ( $f['url'] ?? '' ),
				'impressions'      => (int) ( $page['gsc']['impressions'] ?? 0 ),
				'top_query'        => self::main_query( $page ),
				'is_front'         => $id === $front || '/' === $path,
				'is_utility'       => (bool) preg_match( '#/(contact|privacy|terms|cookie|thank|accessibility|sitemap|login|account|cart|checkout)#i', $path ),
				'is_service'       => 'page' === $row['post_type'] && self::matches_service( $f, $path, $services ),
				'custom_schema_on' => $schema_on,
				'discouraged'      => $discourage,
				'heavy'            => [],
			];
			foreach ( array_unique( array_column( (array) ( $f['links'] ?? [] ), 'p' ) ) as $target ) {
				$norm = self::norm_path( (string) $target );
				if ( isset( $redirects[ $norm ] ) ) {
					$ctx['redirected'][] = [ $target, $redirects[ $norm ] ];
				} elseif ( isset( $link_state[ $norm ] ) && in_array( $link_state[ $norm ], [ 404, 410 ], true ) ) {
					$ctx['broken'][] = $target;
				}
			}
			foreach ( (array) ( $f['images'] ?? [] ) as $img ) {
				if ( (int) ( $img['kb'] ?? 0 ) > Rules::HEAVY_KB ) {
					$ctx['heavy'][] = [ (string) $img['file'], (int) $img['kb'] ];
				}
			}
			$issues = Rules::evaluate( $f, $ctx );
			$this->store->save_issues( $id, $issues );
			$total    += count( $issues );
			$rendered += 'rendered' === $row['source'] ? 1 : 0;
		}

		$summary = [
			'finished_at' => time(),
			'pages'       => count( $rows ),
			'issues'      => $total,
			'rendered'    => $rendered,
			'fallback'    => count( $rows ) - $rendered,
			'sitemap'     => null !== $sitemap,
			'discouraged' => $discourage,
		];
		update_option( Scan_Store::META_OPTION, $summary, false );

		return [
			'pages'    => $summary['pages'],
			'issues'   => $total,
			'rendered' => $rendered,
			'fallback' => $summary['fallback'],
		];
	}

	/**
	 * A path for comparison: lower case, one trailing slash.
	 *
	 * @param string $path Path.
	 */
	public static function norm_path( string $path ): string {
		return '/' === $path ? '/' : trailingslashit( strtolower( $path ) );
	}

	/**
	 * The page's main search: most clicks, else most impressions; '' without data.
	 *
	 * @param array<string,mixed>|null $page Page data.
	 */
	public static function main_query( ?array $page ): string {
		$queries = (array) ( $page['gsc']['queries'] ?? [] );
		if ( [] === $queries ) {
			return '';
		}
		usort( $queries, static fn( $a, $b ) => [ $b['clicks'], $b['impressions'] ] <=> [ $a['clicks'], $a['impressions'] ] );

		return (string) $queries[0]['query'];
	}

	/**
	 * The site's most-seen pages (by impressions), to suggest as places to link from.
	 *
	 * @param array<string,array<string,mixed>> $data Page data.
	 * @return array<int,string>
	 */
	protected static function popular_paths( array $data ): array {
		uasort( $data, static fn( $a, $b ) => (int) ( $b['gsc']['impressions'] ?? 0 ) <=> (int) ( $a['gsc']['impressions'] ?? 0 ) );
		$data = array_filter( $data, static fn( $p ) => (int) ( $p['gsc']['impressions'] ?? 0 ) > 0 );

		return array_slice( array_keys( $data ), 0, 10 );
	}

	/**
	 * AJR Core's redirect map, normalised path => target.
	 *
	 * @return array<string,string>
	 */
	public static function redirect_map(): array {
		$map = get_option( 'ajr_core_redirect_map', [] );
		$out = [];
		foreach ( is_array( $map ) ? $map : [] as $source => $rule ) {
			$target = is_array( $rule ) ? (string) ( $rule['target'] ?? '' ) : (string) $rule;
			if ( '' !== $target ) {
				$out[ self::norm_path( (string) $source ) ] = $target;
			}
		}

		return $out;
	}

	/**
	 * Status of internal link targets that are not a scanned page or a redirect (cached a day).
	 *
	 * @param array<int,string>    $targets   Normalised link paths.
	 * @param array<string,true>   $known     Scanned page paths.
	 * @param array<string,string> $redirects Redirect map.
	 * @return array<string,int> Path => status.
	 */
	protected function check_unknown_links( array $targets, array $known, array $redirects ): array {
		$cache   = get_transient( self::LINK_CACHE );
		$cache   = is_array( $cache ) ? $cache : [];
		$checked = 0;
		foreach ( $targets as $path ) {
			if ( isset( $known[ $path ] ) || isset( $redirects[ $path ] ) || isset( $cache[ $path ] ) || '/' === $path ) {
				continue;
			}
			if ( $checked >= self::MAX_LINK_CHECKS ) {
				break;
			}
			++$checked;
			$cache[ $path ] = Page_Fetcher::fetch( home_url( $path ), 'HEAD' )['status'];
		}
		set_transient( self::LINK_CACHE, $cache, DAY_IN_SECONDS );

		return $cache;
	}

	/**
	 * Every URL in the site's sitemap (bare form), or null when there is none to read.
	 *
	 * Reads the SEO plugin's index (Yoast /sitemap_index.xml, Rank Math the same, The SEO Framework and core
	 * /wp-sitemap.xml) over loopback and follows its child sitemaps (up to 30). Cached for an hour.
	 *
	 * @return array<string,true>|null
	 */
	protected function sitemap(): ?array {
		$cached = get_transient( self::SITEMAP_CACHE );
		if ( is_array( $cached ) ) {
			return $cached['urls'] ?? null;
		}
		$urls = null;
		foreach ( [ '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ] as $index ) {
			$got = self::fetch_xml( home_url( $index ) );
			if ( '' === $got['html'] || false === stripos( $got['html'], '<loc>' ) ) {
				continue;
			}
			$locs = self::locs( $got['html'] );
			$urls = [];
			$kids = 0;
			foreach ( $locs as $loc ) {
				if ( preg_match( '/\.xml(\?|$)/i', $loc ) && $kids < 30 ) {
					++$kids;
					$child = self::fetch_xml( $loc );
					foreach ( self::locs( $child['html'] ) as $page ) {
						$urls[ Rules::bare_url( $page ) ] = true;
					}
				} else {
					$urls[ Rules::bare_url( $loc ) ] = true;
				}
			}
			break;
		}
		set_transient( self::SITEMAP_CACHE, [ 'urls' => $urls ], HOUR_IN_SECONDS );

		return $urls;
	}

	/**
	 * Fetch an XML document from this site (sitemaps answer with an XML content type).
	 *
	 * @param string $url URL.
	 * @return array{ok:bool,status:int,html:string,error:string}
	 */
	protected static function fetch_xml( string $url ): array {
		if ( ! Page_Fetcher::is_own( $url ) ) {
			return [
				'ok'     => false,
				'status' => 0,
				'html'   => '',
				'error'  => '',
			];
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => Page_Fetcher::TIMEOUT,
				'redirection'         => 2,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => Page_Fetcher::MAX_BYTES,
			]
		);
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		return [
			'ok'     => 200 === $status,
			'status' => $status,
			'html'   => 200 === $status ? (string) wp_remote_retrieve_body( $response ) : '',
			'error'  => '',
		];
	}

	/**
	 * The <loc> values of a sitemap document.
	 *
	 * @param string $xml XML.
	 * @return array<int,string>
	 */
	protected static function locs( string $xml ): array {
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>#i', $xml, $m );

		return array_map( 'html_entity_decode', $m[1] ?? [] );
	}

	/**
	 * AJR Core's service names (business.services, one per line, "Name | description").
	 *
	 * @return array<int,string>
	 */
	public static function service_names(): array {
		$config = 'AJR\Core\Framework\Config';
		if ( ! class_exists( $config ) ) {
			return [];
		}
		try {
			$raw = (string) $config::get( 'business.services', '' );
		} catch ( \Throwable $e ) {
			return [];
		}
		$names = [];
		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$name = trim( explode( '|', (string) $line, 2 )[0] );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Whether AJR Core's Custom schema module is switched on.
	 */
	public static function custom_schema_on(): bool {
		$modules = 'AJR\Core\Framework\Modules';
		if ( ! class_exists( $modules ) || ! method_exists( $modules, 'enabled' ) ) {
			return false;
		}
		try {
			return (bool) $modules::enabled( 'custom_schema' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether a page is about one of the business's services (its title, H1 or address names one).
	 *
	 * @param array<string,mixed> $facts    Facts.
	 * @param string              $path     Path.
	 * @param array<int,string>   $services Service names.
	 */
	protected static function matches_service( array $facts, string $path, array $services ): bool {
		$hay = mb_strtolower( (string) ( $facts['title'] ?? '' ) . ' ' . implode( ' ', (array) ( $facts['h1'] ?? [] ) ) . ' ' . str_replace( '-', ' ', $path ) );
		foreach ( $services as $service ) {
			if ( Rules::contains_query( $hay, $service ) ) {
				return true;
			}
		}

		return false;
	}
}
