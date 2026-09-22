<?php
/**
 * Core_Suggestions — feeds Search Console 404 suggestions into AJR Core's Redirects screen.
 *
 * WHY THIS EXISTS
 *
 * Redirects themselves moved to AJR Core (one feature, one plugin). The SUGGESTIONS did not
 * move with them, and should not: they need Search Console, which is this plugin's job and
 * part of what a retainer pays for. So the two halves meet at a filter — AJR Core owns the
 * screen and the rules, this plugin supplies the knowledge of which addresses Google is still
 * asking for.
 *
 * The practical result on a site with AJR Core and no AI SEO Assistant: the section does not
 * appear at all, because nothing fills it. With both: it appears, and it carries this
 * plugin's own empty and not-connected states, so "no suggestions" still tells you WHY and
 * where to fix it rather than silently showing nothing.
 *
 * Nothing here writes a redirect. Every suggestion is a prompt for a human decision — an
 * archive or term URL can appear here while being perfectly valid.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Redirects;

use AJR\SEOAssistant\GSC\GSC_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies AJR Core's redirect suggestions.
 */
class Core_Suggestions {

	/**
	 * Most suggestions to offer at once. A list longer than this is not reviewed, it is
	 * scrolled past.
	 */
	protected const MAX = 25;

	/**
	 * Search Console client.
	 *
	 * @var GSC_Client
	 */
	protected $gsc_client;

	/**
	 * Build.
	 *
	 * @param GSC_Client $gsc_client Search Console client.
	 */
	public function __construct( GSC_Client $gsc_client ) {
		$this->gsc_client = $gsc_client;
	}

	/**
	 * Register the filters AJR Core's Redirects page reads.
	 */
	public function register(): void {
		add_filter( 'ajr_core_redirect_suggestions', [ $this, 'suggestions' ] );
		add_filter( 'ajr_core_redirect_suggestions_status', [ $this, 'status' ] );
	}

	/**
	 * Addresses in Search Console data that no longer resolve on this site.
	 *
	 * @param array<int,array<string,string>> $suggestions Suggestions from other sources.
	 * @return array<int,array{from:string,url:string,reason:string}>
	 */
	public function suggestions( $suggestions ): array {
		$suggestions = is_array( $suggestions ) ? $suggestions : [];

		if ( ! $this->gsc_client->is_connected() ) {
			return $suggestions;
		}

		$data = $this->gsc_client->get_cached_data();
		if ( empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return $suggestions;
		}

		$home  = home_url();
		$seen  = [];
		$found = [];

		foreach ( array_keys( $data['pages'] ) as $url ) {
			$url = (string) $url;

			// Only this site's own URLs: a referring domain is not ours to redirect.
			if ( 0 !== strpos( $url, $home ) ) {
				continue;
			}

			$path = \AJR\Core\Redirects\Redirect_Store::normalise( $url );
			if ( '' === $path || '/' === $path || isset( $seen[ $path ] ) ) {
				continue;
			}

			// Already handled: AJR Core has a rule for it.
			if ( null !== ( new \AJR\Core\Redirects\Redirect_Store() )->find( $path ) ) {
				continue;
			}

			// Resolves to a real post or page, so it is not a 404 at all.
			if ( url_to_postid( $url ) > 0 ) {
				continue;
			}

			$seen[ $path ] = true;
			$found[]       = [
				'from'   => $path,
				'url'    => $url,
				'reason' => __( 'In Search Console data, but does not resolve to a post or page here', 'ai-seo-assistant' ),
			];

			if ( count( $found ) >= self::MAX ) {
				break;
			}
		}

		usort( $found, static fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );

		return array_merge( $suggestions, $found );
	}

	/**
	 * What to say when there is nothing to suggest.
	 *
	 * ⛔ Without this the section would simply vanish when the data is empty, and "no
	 * section" reads as "this feature does not exist" rather than "Search Console has not
	 * been synced yet". The difference is whether anyone ever syncs it.
	 *
	 * @param array<string,mixed> $status Status from other sources.
	 * @return array{active:bool,message:string,link_url:string,link_text:string}
	 */
	public function status( $status ): array {
		$status = is_array( $status ) ? $status : [];

		if ( ! $this->gsc_client->is_connected() ) {
			return [
				'active'    => true,
				'message'   => __( 'Connect Google Search Console to see the addresses Google still asks for that no longer exist on this site.', 'ai-seo-assistant' ),
				'link_url'  => admin_url( 'admin.php?page=ai-seo-assistant-gsc' ),
				'link_text' => __( 'Open Search Console settings', 'ai-seo-assistant' ),
			];
		}

		return [
			'active'    => true,
			'message'   => __( 'Search Console is connected. Suggestions come from its cached data — sync it to refresh this list.', 'ai-seo-assistant' ),
			'link_url'  => admin_url( 'admin.php?page=ai-seo-assistant-gsc' ),
			'link_text' => __( 'Sync Search Console', 'ai-seo-assistant' ),
		];
	}
}
