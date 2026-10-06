<?php
/**
 * Tools_Actions — every write the agency screens make: scan steps, generate, apply, undo.
 *
 * Each handler checks the nonce AND Access::TOOLS_CAP (a nonce proves intent, not permission) before it
 * does anything, and each one also works without JavaScript through admin-post.php (a plain form), with
 * admin-ajax.php used by the screens' script for progress (scan steps, bulk generate).
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\Changes\Change_Log;
use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Review\Page_Review;
use AJR\SEOAssistant\Scan\Scan_Store;
use AJR\SEOAssistant\Scan\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-post and AJAX handlers for the tool screens.
 */
class Tools_Actions {

	/** Rescan now. */
	public const RESCAN = 'aisa_rescan';

	/** Write suggestions for one page. */
	public const GENERATE = 'aisa_generate';

	/** Apply accepted suggestions. */
	public const APPLY = 'aisa_apply';

	/** Undo changes. */
	public const UNDO = 'aisa_undo';

	/** Clear a page's (fully undone) review so it can be generated again. */
	public const CLEAR = 'aisa_clear_review';

	/** Confirm the 4.x redirects are in AJR Core, so the old table can go. */
	public const REDIRECTS = 'aisa_redirects_moved';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::RESCAN, [ $this, 'rescan' ] );
		add_action( 'admin_post_' . self::GENERATE, [ $this, 'generate' ] );
		add_action( 'admin_post_' . self::APPLY, [ $this, 'apply' ] );
		add_action( 'admin_post_' . self::UNDO, [ $this, 'undo' ] );
		add_action( 'admin_post_' . self::CLEAR, [ $this, 'clear' ] );
		add_action( 'admin_post_' . self::REDIRECTS, [ $this, 'redirects_moved' ] );
		Changes_Page::register_export();
		add_action( 'wp_ajax_aisa_scan_start', [ $this, 'ajax_scan_start' ] );
		add_action( 'wp_ajax_aisa_scan_step', [ $this, 'ajax_scan_step' ] );
		add_action( 'wp_ajax_aisa_generate', [ $this, 'ajax_generate' ] );
	}

	/**
	 * Refuse anyone without the tools capability or a valid nonce.
	 *
	 * @param string $action Nonce action.
	 */
	protected function guard( string $action ): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/**
	 * The same for AJAX (JSON errors).
	 */
	protected function guard_ajax(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'ai-seo-assistant' ) ], 403 );
		}
		check_ajax_referer( Ui::NONCE, 'nonce' );
	}

	/**
	 * Rescan now (no-JS path): queue every page; cron works through it.
	 */
	public function rescan(): void {
		$this->guard( self::RESCAN );
		Scheduler::start_full();
		$this->back( [ 'aisa' => 'rescan' ] );
	}

	/**
	 * AJAX: queue every page and report the queue.
	 */
	public function ajax_scan_start(): void {
		$this->guard_ajax();
		if ( null === Scheduler::queue() ) {
			Scheduler::start_full();
		}
		$queue = (array) Scheduler::queue();
		wp_send_json_success(
			[
				'state' => 'working',
				'done'  => (int) ( $queue['done'] ?? 0 ),
				'total' => (int) ( $queue['total'] ?? 0 ),
			]
		);
	}

	/**
	 * AJAX: scan the next batch (about 15 seconds of work).
	 */
	public function ajax_scan_step(): void {
		$this->guard_ajax();
		wp_send_json_success( Scheduler::step( 15 ) );
	}

	/**
	 * AJAX: write suggestions for one page (the bulk bar and the review's Generate both use it).
	 */
	public function ajax_generate(): void {
		$this->guard_ajax();
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard_ajax().
		ignore_user_abort( true ); // "You can leave this screen": the suggestions are still saved.
		$this->long_request();
		$result = ( new Page_Review() )->generate( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				'aisa_spend_cap' === $result->get_error_code() ? 402 : 400
			);
		}
		wp_send_json_success( [ 'cost' => $result['cost'] ] );
	}

	/**
	 * Write suggestions (no-JS path).
	 */
	public function generate(): void {
		$this->guard( self::GENERATE );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		ignore_user_abort( true );
		$this->long_request();
		$result = ( new Page_Review() )->generate( $post_id );
		$code   = is_wp_error( $result ) ? ( 'aisa_spend_cap' === $result->get_error_code() ? 'capped' : 'genfail' ) : '';
		$this->back(
			array_filter(
				[
					'post' => $post_id,
					'aisa' => $code,
				]
			)
		);
	}

	/**
	 * Apply the accepted fields.
	 */
	public function apply(): void {
		$this->guard( self::APPLY );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$choices = [];
		foreach ( [ 'title', 'description', 'keyphrase' ] as $field ) {
			$action = isset( $_POST['choice'][ $field ] ) ? sanitize_key( wp_unslash( $_POST['choice'][ $field ] ) ) : 'skip';
			$value  = isset( $_POST['value'][ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'][ $field ] ) ) : '';
			// The text box always carries what will be written: "Accept" leaves Claude's text, "Edit" changes it.
			$choices[ $field ] = [
				'action' => in_array( $action, [ 'accept', 'edit', 'skip' ], true ) ? ( 'skip' === $action ? 'skip' : 'edit' ) : 'skip',
				'value'  => $value,
			];
		}
		$choices['alts'] = [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.
		$alts = isset( $_POST['alt'] ) && is_array( $_POST['alt'] ) ? wp_unslash( $_POST['alt'] ) : [];
		foreach ( $alts as $id => $alt ) {
			$choices['alts'][ absint( $id ) ] = [
				'apply' => ! empty( $alt['apply'] ),
				'value' => sanitize_text_field( (string) ( $alt['value'] ?? '' ) ),
			];
		}
		$result = ( new Page_Review() )->apply( $post_id, $choices, get_current_user_id() );
		$code   = is_wp_error( $result ) || 0 === $result['applied'] ? 'nothing' : '';
		$this->back(
			array_filter(
				[
					'post' => $post_id,
					'aisa' => $code,
				]
			)
		);
	}

	/**
	 * Undo one change or a batch.
	 */
	public function undo(): void {
		$this->guard( self::UNDO );
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : [];
		$result = ( new Page_Review() )->undo( $ids, get_current_user_id() );
		$first  = [] === $ids ? null : ( new Change_Log() )->get( (int) reset( $ids ) );
		$args   = [ 'aisa' => [] !== $result['kept'] ? 'kept' : 'undone' ];
		$ref    = wp_get_referer();
		if ( $ref && false !== strpos( $ref, 'page=' . Changes_Page::SLUG ) ) {
			wp_safe_redirect( add_query_arg( $args, $ref ) );
			exit;
		}
		if ( null !== $first ) {
			$args['post'] = $first['post_id'];
		}
		$this->back( $args );
	}

	/**
	 * Clear a fully undone review so the page can be reviewed afresh.
	 */
	public function clear(): void {
		$this->guard( self::CLEAR );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		( new Scan_Store() )->save_suggestions( $post_id, null );
		$this->back( [ 'post' => $post_id ] );
	}

	/**
	 * The agency confirms the old redirects are in AJR Core: the table goes only if every one is found.
	 */
	public function redirects_moved(): void {
		$this->guard( self::REDIRECTS );
		$missing = \AJR\SEOAssistant\Core\Upgrade::confirm_redirects_moved();
		$ref     = wp_get_referer();
		$url     = $ref ? $ref : admin_url( 'admin.php?page=' . Settings_Page::SLUG );
		wp_safe_redirect( [] === $missing ? remove_query_arg( 'aisa_missing', $url ) : add_query_arg( 'aisa_missing', rawurlencode( implode( ', ', array_slice( $missing, 0, 20 ) ) ), $url ) );
		exit;
	}

	/**
	 * Room for a Claude call (up to 90 s) on hosts with a short PHP limit.
	 */
	protected function long_request(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it; best effort.
		}
	}

	/**
	 * Back to the SEO scan screen.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 */
	protected function back( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( [ 'page' => Scan_Page::SLUG ], $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
