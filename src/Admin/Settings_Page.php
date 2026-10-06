<?php
/**
 * Settings_Page — AI SEO Assistant → Settings (mockup G1). Agency only.
 *
 * Claude: the API key (sealed by Core\Secret_Store, write-only: replaced or cleared, never shown again),
 * the model, and this site's spend cap per billing month. Brand context: the business facts Claude may
 * use, read from AJR Core (shown, never copied), plus the agency's tone and house rules (the only brand
 * field this plugin stores). Who sees the tools: decided by AJR Core, with the old ticked list shown only
 * while it overrides. Report delivery: the push address, the write-only push key, the last update, the
 * billing day (from the push, else typed here), the late-report alert address and the Import fallback.
 *
 * Every write is an admin-post handler with a nonce and Access::TOOLS_CAP.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Admin;

use AJR\SEOAssistant\AI\Claude_Client;
use AJR\SEOAssistant\AI\Spend;
use AJR\SEOAssistant\Content\Business;
use AJR\SEOAssistant\Core\Secret_Store;
use AJR\SEOAssistant\Report\Access;
use AJR\SEOAssistant\Report\Push_Endpoint;
use AJR\SEOAssistant\Report\Push_Key;
use AJR\SEOAssistant\Report\Snapshot;
use AJR\SEOAssistant\Report\Snapshot_Store;
use AJR\SEOAssistant\Report\Stale_Alert;
use AJR\SEOAssistant\Search\Page_Data;

defined( 'ABSPATH' ) || exit;

/**
 * The Settings screen.
 */
class Settings_Page {

	/** Menu slug (4.x's settings slug, so the Plugins-screen link and bookmarks keep working). */
	public const SLUG = 'ai-seo-assistant';

	/** Save action. */
	public const SAVE = 'aisa_save_settings';

	/** New push key action. */
	public const KEY = 'aisa_report_key';

	/** Import action. */
	public const IMPORT = 'aisa_report_import';

	/** Clear the agency override list. */
	public const OVERRIDE = 'aisa_clear_override';

	/**
	 * Register the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, [ $this, 'save' ] );
		add_action( 'admin_post_' . self::KEY, [ $this, 'generate_key' ] );
		add_action( 'admin_post_' . self::IMPORT, [ $this, 'import' ] );
		add_action( 'admin_post_' . self::OVERRIDE, [ $this, 'clear_override' ] );
	}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Access::TOOLS_CAP ) ) {
			return;
		}
		echo '<div class="wrap aisa-wrap"><hr class="wp-header-end"><div class="aisa-tool">';
		$last = (int) get_option( Snapshot_Store::LAST_PUSH, 0 );
		echo Ui::hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
			[
				/* translators: %s: agency name. */
				'label' => sprintf( __( 'Settings by %s', 'ai-seo-assistant' ), Ui::agency() ),
				'title' => __( 'Settings', 'ai-seo-assistant' ),
				'sub'   => __( 'Agency only. Secrets are encrypted on this site and never shown again; business details come from AJR Core.', 'ai-seo-assistant' ),
				'fresh' => false,
			]
		);
		$this->notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aisa-settings" class="aisa-settings">';
		wp_nonce_field( self::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';
		$this->claude_card();
		$this->brand_card();
		$this->access_card();
		$this->delivery_card( $last );
		echo '<p class="aisa-savebar"><button type="submit" class="aisa-btn aisa-btn--primary">' . Ui::icon( 'yes' ) . esc_html__( 'Save settings', 'ai-seo-assistant' ) . '</button><span class="aisa-small">' . esc_html__( 'Secrets are saved only when you type a new one.', 'ai-seo-assistant' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '</form>';

		// Separate forms for actions inside the cards (forms cannot nest; their buttons use form="…").
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aisa-keyform">';
		wp_nonce_field( self::KEY );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::KEY ) . '"></form>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aisa-importform">';
		wp_nonce_field( self::IMPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT ) . '"></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aisa-overrideform">';
		wp_nonce_field( self::OVERRIDE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::OVERRIDE ) . '"></form>';
		echo '</div></div>';
	}

	/**
	 * Result codes from our own redirects.
	 */
	protected function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result code from our own redirect.
		$code     = isset( $_GET['aisa'] ) ? sanitize_key( wp_unslash( $_GET['aisa'] ) ) : '';
		$messages = [
			'saved'     => [ 'success', __( 'Settings saved.', 'ai-seo-assistant' ) ],
			'badkey'    => [ 'error', __( 'That does not look like a Claude API key (they start with sk-ant-). The previous key was kept.', 'ai-seo-assistant' ) ],
			'nocrypt'   => [ 'error', __( 'The key could not be encrypted on this server, so it was not saved. Define it in wp-config.php instead.', 'ai-seo-assistant' ) ],
			'cleared'   => [ 'success', __( 'The Claude API key was removed. Suggestions stop until a new one is saved.', 'ai-seo-assistant' ) ],
			'stored'    => [ 'success', __( 'Report imported.', 'ai-seo-assistant' ) ],
			'replaced'  => [ 'success', __( 'Report imported, replacing the earlier version of that period.', 'ai-seo-assistant' ) ],
			'unchanged' => [ 'success', __( 'That report was already here, unchanged.', 'ai-seo-assistant' ) ],
			'stale'     => [ 'error', __( 'Not imported: a newer version of that period is already here.', 'ai-seo-assistant' ) ],
			'dropped'   => [ 'error', __( 'Not imported: that period is older than the 12 months kept.', 'ai-seo-assistant' ) ],
			'wrongsite' => [ 'error', __( 'Not imported: that file is a report for a different site.', 'ai-seo-assistant' ) ],
			'nofile'    => [ 'error', __( 'Not imported: no file arrived, or it was too large.', 'ai-seo-assistant' ) ],
			'invalid'   => [ 'error', __( 'Not imported: the file is not a valid report.', 'ai-seo-assistant' ) ],
			'override'  => [ 'success', __( 'The agency-users override was cleared: AJR Core decides who sees the tools.', 'ai-seo-assistant' ) ],
		];
		if ( isset( $messages[ $code ] ) ) {
			echo Ui::notice( $messages[ $code ][0], '<p>' . esc_html( $messages[ $code ][1] ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}
	}

	/**
	 * One labelled settings row.
	 *
	 * @param string $label Label (HTML, escaped by the caller).
	 * @param string $help  Help under the label.
	 * @param string $field Field HTML (escaped by the caller).
	 * @param string $for   ID the label is for ('' for a group).
	 */
	protected function row( string $label, string $help, string $field, string $for = '' ): string {
		$head = '' !== $for ? '<label for="' . esc_attr( $for ) . '">' . $label . '</label>' : '<span class="aisa-setrow__label">' . $label . '</span>';

		return '<div class="aisa-setrow"><div class="aisa-setrow__head">' . $head . ( '' !== $help ? '<p class="aisa-small">' . esc_html( $help ) . '</p>' : '' ) . '</div><div class="aisa-setrow__field">' . $field . '</div></div>';
	}

	/**
	 * Claude: key, model, cap.
	 */
	protected function claude_card(): void {
		$claude = new Claude_Client();
		$spend  = Spend::current();
		$cap    = Spend::cap();
		$day    = Spend::billing_day();
		$last4  = Secret_Store::last4( Claude_Client::OPTION_API_KEY );

		if ( $claude->has_config_key() ) {
			$key = '<p class="aisa-secret">' . Ui::icon( 'lock' ) . '<strong>' . esc_html__( 'Set in wp-config.php', 'ai-seo-assistant' ) . '</strong> <code>' . esc_html( Claude_Client::CONFIG_CONSTANT ) . '</code></p>';
		} else {
			$saved = '' !== $last4
				/* translators: %s: last four characters. */
				? '<span class="aisa-secret">' . Ui::icon( 'lock' ) . '<strong>' . esc_html( sprintf( __( 'Saved · ends …%s', 'ai-seo-assistant' ), $last4 ) ) . '</strong>' . Ui::pill( __( 'Encrypted', 'ai-seo-assistant' ), 'good' ) . '</span>'
				: '<span class="aisa-secret aisa-secret--empty">' . esc_html__( 'No key saved', 'ai-seo-assistant' ) . '</span>';
			$key   = '<div class="aisa-keyrow">' . $saved
				. '<details class="aisa-replace"><summary class="aisa-btn">' . Ui::icon( 'admin-network' ) . esc_html( '' !== $last4 ? __( 'Replace', 'ai-seo-assistant' ) : __( 'Add a key', 'ai-seo-assistant' ) ) . '</summary>'
				. '<label class="screen-reader-text" for="aisa-api-key">' . esc_html__( 'New Claude API key', 'ai-seo-assistant' ) . '</label><input type="password" id="aisa-api-key" name="api_key" autocomplete="off" spellcheck="false" placeholder="sk-ant-…"></details>'
				. ( '' !== $last4 ? '<button type="submit" name="clear_api_key" value="1" class="aisa-btn aisa-btn--danger-outline">' . Ui::icon( 'no-alt' ) . esc_html__( 'Clear', 'ai-seo-assistant' ) . '</button>' : '' ) . '</div>'
				. '<p class="aisa-small">' . esc_html__( 'Encrypted on this site; never shown again. Replace pastes a new key; Clear removes it and stops all suggestions.', 'ai-seo-assistant' ) . '</p>';
		}

		$models = '<select id="aisa-model" name="model">';
		foreach ( Claude_Client::available_models() as $id => $model ) {
			$models .= '<option value="' . esc_attr( $id ) . '"' . selected( $claude->get_model(), $id, false ) . '>' . esc_html( $model['label'] ) . '</option>';
		}
		$models .= '</select><p class="aisa-small">' . esc_html__( 'With Opus 5, title, description, focus keyphrase and alt text cost about 2–3¢ a page; about 6¢ with full recommendations in the editor box. Sonnet 5 and Haiku 4.5 cost less and write shorter suggestions. The scan itself never uses Claude.', 'ai-seo-assistant' ) . '</p>';

		$pct   = $cap > 0 ? min( 100, $spend['usd'] / $cap * 100 ) : 100;
		$range = wp_date( 'j M', $spend['start']->getTimestamp(), $spend['start']->getTimezone() ) . ' to ' . wp_date( 'j M', $spend['end']->getTimestamp() - DAY_IN_SECONDS, $spend['end']->getTimezone() );
		/* translators: 1: spent, 2: period, 3: calls, 4: reset date. */
		$used = sprintf( __( '%1$s used this billing month (%2$s) · %3$d calls · resets %4$s', 'ai-seo-assistant' ), Spend::money( $spend['usd'] ), $range, $spend['calls'], wp_date( 'j M', $spend['end']->getTimestamp(), $spend['end']->getTimezone() ) );
		$capf = '<p class="aisa-money"><span aria-hidden="true">$</span><input type="number" id="aisa-cap" name="cap" min="0" max="1000" step="0.5" value="' . esc_attr( number_format( $cap, 2, '.', '' ) ) . '" class="small-text"> <span>' . esc_html__( 'per billing month', 'ai-seo-assistant' ) . '</span></p>'
			. '<p class="aisa-usage"><span class="aisa-meter aisa-meter--spend" aria-hidden="true"><span class="aisa-meter__value" style="inline-size:' . esc_attr( (string) round( $pct, 1 ) ) . '%"></span></span><span class="aisa-small">' . esc_html( $used ) . '</span></p>'
			. '<p class="aisa-small">' . esc_html__( 'At the cap the scan keeps running but stops writing suggestions, and says so. The editor box is capped too.', 'ai-seo-assistant' ) . '</p>';
		/* translators: %s: ordinal day, e.g. "17th". */
		$cap_help = sprintf( __( 'For this site, per billing month (from the %s), on top of the Claude Console workspace limit.', 'ai-seo-assistant' ), self::ordinal_day( $day['day'] ) );

		echo '<section class="aisa-card aisa-setcard" aria-labelledby="aisa-s-claude">';
		echo Ui::card_head( 'aisa-s-claude', 'admin-customizer', __( 'Claude', 'ai-seo-assistant' ), __( 'Used only by agency users', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo $this->row( esc_html__( 'Claude API key', 'ai-seo-assistant' ), __( 'From the AJR Web Design Claude Console workspace.', 'ai-seo-assistant' ), $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Model', 'ai-seo-assistant' ), '', $models, 'aisa-model' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<div id="aisa-cap-row">' . $this->row( esc_html__( 'Monthly AI spend cap', 'ai-seo-assistant' ), $cap_help, $capf, 'aisa-cap' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</section>';
	}

	/**
	 * Brand context: AJR Core's facts (read only) and the tone field.
	 */
	protected function brand_card(): void {
		$facts = Business::facts();
		$core  = Business::core_active();
		$chip  = $core ? Ui::pill( __( 'from AJR Core', 'ai-seo-assistant' ), 'info' ) : Ui::pill( __( 'AJR Core missing', 'ai-seo-assistant' ), 'bad' );
		echo '<section class="aisa-card aisa-setcard" aria-labelledby="aisa-s-brand">';
		echo Ui::card_head( 'aisa-s-brand', 'edit', __( 'Brand context', 'ai-seo-assistant' ), __( 'What Claude knows about the business', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		foreach ( [
			'name'     => __( 'Business name', 'ai-seo-assistant' ),
			'area'     => __( 'Area served', 'ai-seo-assistant' ),
			'phone'    => __( 'Phone', 'ai-seo-assistant' ),
			'services' => __( 'Services', 'ai-seo-assistant' ),
		] as $key => $label ) {
			$id = 'aisa-b-' . $key;
			echo $this->row( esc_html( $label ), '', '<span class="aisa-readonly"><input type="text" id="' . esc_attr( $id ) . '" value="' . esc_attr( $facts[ $key ] ) . '" readonly placeholder="' . esc_attr__( 'Not set in AJR Core', 'ai-seo-assistant' ) . '">' . $chip . '</span>', $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		}
		$tone = '<textarea id="aisa-tone" name="tone" rows="3">' . esc_textarea( Business::tone() ) . '</textarea>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=ajr-core-business' ) ) . '">' . esc_html__( 'Edit business details in AJR Core', 'ai-seo-assistant' ) . '</a></p>';
		echo $this->row( esc_html__( 'Tone and extra notes', 'ai-seo-assistant' ), __( 'Only this field is stored by the plugin.', 'ai-seo-assistant' ), $tone, 'aisa-tone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		echo '</section>';
	}

	/**
	 * Who sees the tools.
	 */
	protected function access_card(): void {
		$admins = get_users(
			[
				'role'    => 'administrator',
				'number'  => 50,
				'orderby' => 'display_name',
				'fields'  => [ 'ID', 'display_name', 'user_email' ],
			]
		);
		$agency = [];
		$others = [];
		foreach ( $admins as $admin ) {
			$text = $admin->display_name . ' (' . $admin->user_email . ')';
			if ( Access::is_agency( (int) $admin->ID ) ) {
				$agency[] = $text;
			} else {
				$others[] = $text;
			}
		}
		$source = Access::source();
		$chips  = [
			'override' => Ui::pill( __( 'override list', 'ai-seo-assistant' ), 'warn' ),
			'ajr-core' => Ui::pill( __( 'from AJR Core', 'ai-seo-assistant' ), 'info' ),
			'fallback' => Ui::pill( __( 'AJR Core found no agency login: every Administrator keeps the tools', 'ai-seo-assistant' ), 'warn' ),
			'none'     => Ui::pill( __( 'AJR Core missing: every Administrator keeps the tools', 'ai-seo-assistant' ), 'bad' ),
		];
		$list   = '<ul class="aisa-people">';
		foreach ( $agency as $who ) {
			$list .= '<li>' . Ui::icon( 'admin-users' ) . esc_html( $who ) . '</li>';
		}
		$list .= '</ul>' . ( $chips[ $source ] ?? '' );
		if ( [] !== $others ) {
			/* translators: %s: names of the other Administrators. */
			$list .= '<p class="aisa-small">' . esc_html( sprintf( __( 'Every other Administrator (here: %s) sees the Report only. Change this in AJR Core.', 'ai-seo-assistant' ), implode( ', ', $others ) ) ) . '</p>';
		}
		if ( 'override' === $source ) {
			$list .= '<p class="aisa-small">' . esc_html__( 'A ticked list from 4.x overrides AJR Core on this site.', 'ai-seo-assistant' ) . ' <button type="submit" form="aisa-overrideform" class="aisa-linkbtn">' . esc_html__( 'Clear the override', 'ai-seo-assistant' ) . '</button></p>';
		}
		echo '<section class="aisa-card aisa-setcard" aria-labelledby="aisa-s-access">';
		echo Ui::card_head( 'aisa-s-access', 'groups', __( 'Who sees the tools', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo $this->row( esc_html__( 'Agency users', 'ai-seo-assistant' ), __( 'Decided by AJR Core.', 'ai-seo-assistant' ), $list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise.
		echo '</section>';
	}

	/**
	 * Report delivery.
	 *
	 * @param int $last Last push time.
	 */
	protected function delivery_card( int $last ): void {
		Push_Key::retire_stored();
		$user    = get_current_user_id();
		$new_key = get_transient( 'aisa_report_new_key_' . $user );
		if ( false !== $new_key ) {
			delete_transient( 'aisa_report_new_key_' . $user );
			$new_key = Secret_Store::open( $new_key ); // Held sealed for its five minutes (generate_key()).
		}
		$address = '<input type="text" class="code aisa-code" readonly value="' . esc_attr( rest_url( Push_Endpoint::REST_NAMESPACE . Push_Endpoint::ROUTE ) ) . '" aria-label="' . esc_attr__( 'Push address', 'ai-seo-assistant' ) . '">';

		if ( is_string( $new_key ) && '' !== $new_key ) {
			$key = '<p><strong>' . esc_html__( 'Copy this key now — it will not be shown again:', 'ai-seo-assistant' ) . '</strong></p><input type="text" class="code aisa-code" readonly value="' . esc_attr( $new_key ) . '" aria-label="' . esc_attr__( 'New push key', 'ai-seo-assistant' ) . '">';
		} elseif ( Push_Key::from_constant() ) {
			$key = '<p class="aisa-secret">' . Ui::icon( 'lock' ) . '<strong>' . esc_html__( 'Set in wp-config.php', 'ai-seo-assistant' ) . '</strong> <code>AI_SEO_ASSISTANT_REPORT_KEY</code></p>';
		} else {
			$k4  = Secret_Store::last4( Push_Key::OPTION );
			$key = '<div class="aisa-keyrow">' . ( '' !== Push_Key::get()
				/* translators: %s: last four characters. */
				? '<span class="aisa-secret">' . Ui::icon( 'lock' ) . '<strong>' . esc_html( '' !== $k4 ? sprintf( __( 'Saved · ends …%s', 'ai-seo-assistant' ), $k4 ) : __( 'Saved', 'ai-seo-assistant' ) ) . '</strong>' . Ui::pill( __( 'Encrypted', 'ai-seo-assistant' ), 'good' ) . '</span>'
				: '<span class="aisa-secret aisa-secret--empty">' . esc_html__( 'Not set — pushes are refused until a key is made.', 'ai-seo-assistant' ) . '</span>' )
				. '<button type="submit" form="aisa-keyform" class="aisa-btn">' . Ui::icon( 'admin-network' ) . esc_html( '' !== Push_Key::get() ? __( 'Make a new key', 'ai-seo-assistant' ) : __( 'Make a key', 'ai-seo-assistant' ) ) . '</button></div>';
		}
		$key .= '<p class="aisa-small">' . esc_html__( 'Encrypted, write-only. A new key stops the old one working; paste it into retainer-scan’s weekly-sites.json.', 'ai-seo-assistant' ) . '</p>';

		$latest = ( new Snapshot_Store() )->latest();
		$late   = $last > 0 && time() - $last > Stale_Alert::LATE_AFTER_DAYS * DAY_IN_SECONDS;
		$what   = [];
		if ( is_array( $latest ) ) {
			/* translators: %d: schema version. */
			$what[] = sprintf( __( 'snapshot v%d: weekly report', 'ai-seo-assistant' ), (int) $latest['schema'] );
		}
		if ( [] !== ( new Snapshot_Store() )->months() ) {
			$what[] = __( 'billing month', 'ai-seo-assistant' );
		}
		if ( Page_Data::has_data() ) {
			/* translators: %d: pages. */
			$what[] = sprintf( __( 'per-page search data (%d pages)', 'ai-seo-assistant' ), Page_Data::meta()['count'] );
		}
		$received = $last > 0
			? Ui::pill( $late ? __( 'Late', 'ai-seo-assistant' ) : __( 'On time', 'ai-seo-assistant' ), $late ? 'warn' : 'good' ) . ' <span>' . esc_html( wp_date( 'D j M Y, g:ia', $last ) . ( [] !== $what ? ' · ' . implode( ', ', $what ) : '' ) ) . '</span>'
			: '<span>' . esc_html__( 'Never', 'ai-seo-assistant' ) . '</span>';

		$day = Spend::billing_day();
		if ( 'push' === $day['source'] ) {
			$s       = Spend::current();
			$billing = '<p>' . esc_html( sprintf( /* translators: 1: ordinal day, 2: current period. */ __( 'The %1$s of each month (%2$s)', 'ai-seo-assistant' ), self::ordinal_day( $day['day'] ), wp_date( 'j M', $s['start']->getTimestamp(), $s['start']->getTimezone() ) . ' – ' . wp_date( 'j M', $s['end']->getTimestamp() - DAY_IN_SECONDS, $s['end']->getTimezone() ) ) ) . ' ' . Ui::pill( __( 'from retainer-scan', 'ai-seo-assistant' ), 'info' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		} else {
			$billing = '<input type="number" id="aisa-billing" name="billing_day" min="1" max="31" class="small-text" value="' . esc_attr( 'default' === $day['source'] ? '' : (string) $day['day'] ) . '" placeholder="1"><p class="aisa-small">' . esc_html__( 'Used until the first push brings the day from retainer-scan.', 'ai-seo-assistant' ) . '</p>';
		}
		$alert  = '<input type="email" class="regular-text" id="aisa-alert" name="alert_email" value="' . esc_attr( (string) get_option( Stale_Alert::ADDRESS, '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '">';
		$import = '<input type="file" id="aisa-snapshot" name="snapshot" form="aisa-importform" accept="application/json,.json"> <button type="submit" form="aisa-importform" class="aisa-btn">' . Ui::icon( 'upload' ) . esc_html__( 'Import', 'ai-seo-assistant' ) . '</button>';

		echo '<section class="aisa-card aisa-setcard" aria-labelledby="aisa-s-delivery">';
		echo Ui::card_head( 'aisa-s-delivery', 'update', __( 'Report delivery', 'ai-seo-assistant' ), __( 'Weekly push from retainer-scan', 'ai-seo-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo $this->row( esc_html__( 'Push address', 'ai-seo-assistant' ), '', $address ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Push key', 'ai-seo-assistant' ), '', $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Last update received', 'ai-seo-assistant' ), '', '<p class="aisa-received">' . $received . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Billing month starts', 'ai-seo-assistant' ), __( 'Sets the Monthly view and the spend cap’s month.', 'ai-seo-assistant' ), $billing, 'push' === $day['source'] ? '' : 'aisa-billing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Late-report alerts go to', 'ai-seo-assistant' ), __( 'Emailed once when an update is 8+ days late, and once when it recovers. Empty: the site’s admin email.', 'ai-seo-assistant' ), $alert, 'aisa-alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $this->row( esc_html__( 'Import a report', 'ai-seo-assistant' ), __( 'For hosts that block the weekly push: the JSON file retainer-scan saved.', 'ai-seo-assistant' ), $import, 'aisa-snapshot' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</section>';
	}

	/**
	 * Save the settings form.
	 */
	public function save(): void {
		$this->guard( self::SAVE );
		$code = 'saved';

		if ( ! empty( $_POST['clear_api_key'] ) ) {
			Secret_Store::set( Claude_Client::OPTION_API_KEY, '' );
			$code = 'cleared';
		} else {
			$key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
			if ( '' !== $key ) {
				if ( 0 !== strpos( $key, Claude_Client::KEY_PREFIX ) ) {
					$code = 'badkey';
				} elseif ( ! Secret_Store::set( Claude_Client::OPTION_API_KEY, $key ) ) {
					$code = 'nocrypt';
				}
			}
		}

		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		if ( Claude_Client::is_supported_model( $model ) ) {
			update_option( Claude_Client::OPTION_MODEL, $model, false );
		}
		if ( isset( $_POST['cap'] ) && is_numeric( $_POST['cap'] ) ) {
			update_option( Spend::CAP_OPTION, (string) round( max( 0.0, min( 1000.0, (float) $_POST['cap'] ) ), 2 ), false );
		}
		if ( isset( $_POST['billing_day'] ) ) {
			$day = absint( $_POST['billing_day'] );
			update_option( Spend::DAY_OPTION, $day >= 1 && $day <= 31 ? $day : 0, false );
		}
		if ( isset( $_POST['tone'] ) ) {
			update_option( Business::TONE_OPTION, sanitize_textarea_field( wp_unslash( $_POST['tone'] ) ), false );
		}
		if ( isset( $_POST['alert_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['alert_email'] ) );
			update_option( Stale_Alert::ADDRESS, is_email( $email ) ? $email : '', false );
		}

		$this->back( $code );
	}

	/**
	 * Make a new push key and show it once.
	 */
	public function generate_key(): void {
		$this->guard( self::KEY );
		if ( Push_Key::from_constant() ) {
			wp_die( esc_html__( 'The push key is set in wp-config.php.', 'ai-seo-assistant' ), '', [ 'response' => 403 ] );
		}
		$key = Push_Key::generate();
		if ( '' !== $key ) {
			// The transient is a wp_options row (without an object cache) for up to five minutes: sealed
			// like the key itself, so the plain key is never at rest anywhere.
			set_transient( 'aisa_report_new_key_' . get_current_user_id(), Secret_Store::seal( $key ), 5 * MINUTE_IN_SECONDS );
		}
		$this->back( '' );
	}

	/**
	 * Import a snapshot file (the fallback for hosts that block the push).
	 */
	public function import(): void {
		$this->guard( self::IMPORT );
		$result = 'nofile';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name/size/error are read, and the file's CONTENT is validated by Snapshot::from_json().
		$file = isset( $_FILES['snapshot'] ) && is_array( $_FILES['snapshot'] ) ? $_FILES['snapshot'] : null;
		if ( is_array( $file ) && UPLOAD_ERR_OK === ( $file['error'] ?? -1 ) && (int) ( $file['size'] ?? 0 ) <= Snapshot::MAX_BYTES_V2 && is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$errors   = [];
			$snapshot = Snapshot::from_json( (string) file_get_contents( (string) $file['tmp_name'] ), $errors ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload's temp file, not a URL.
			if ( null === $snapshot ) {
				$result = 'invalid';
			} elseif ( Push_Endpoint::host( $snapshot['site'] ) !== Push_Endpoint::host( (string) home_url() ) ) {
				$result = 'wrongsite';
			} else {
				$result = ( new Snapshot_Store() )->receive( $snapshot, time() );
			}
		}
		$this->back( $result );
	}

	/**
	 * Clear the 4.x agency-users override, so AJR Core decides.
	 */
	public function clear_override(): void {
		$this->guard( self::OVERRIDE );
		update_option( Access::OPTION, [], true );
		Access::flush();
		$this->back( 'override' );
	}

	/**
	 * Capability + nonce.
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
	 * Back to this screen.
	 *
	 * @param string $code Result code.
	 */
	protected function back( string $code ): void {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $code ) {
			$args['aisa'] = $code;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * "17th".
	 *
	 * @param int $day Day.
	 */
	protected static function ordinal_day( int $day ): string {
		$suffix = in_array( $day % 100, [ 11, 12, 13 ], true ) ? 'th' : ( [ 1 => 'st', 2 => 'nd', 3 => 'rd' ][ $day % 10 ] ?? 'th' );

		return $day . $suffix;
	}
}
