# AI SEO Assistant

AI SEO Assistant is AJR Web Design's retainer plugin. The client sees one screen, the **Report**: their enquiries, Search Console, Analytics and Ads, week by week and by billing month. The agency gets the tools behind it: an **SEO scan** of every rendered page ranked by the clicks it could win, a **page review** where Claude suggests the title, description, keyphrase and image alt text, a **Changes** log that measures each change and can undo it, and a **Search Console** view.

5.0 requires **AJR Core** (`Requires Plugins: ajr-core`). Everything that AJR Core now does (Markdown for AI, redirects, schema, the business profile, Google tags) has left this plugin.

## Screens

| Menu | Who | What |
|---|---|---|
| **Report** (Weekly \| Monthly) | every Administrator | Enquiries first (calls + forms + bookings; taps shown apart, never added), Search Console, Analytics key events, Ads. The monthly view follows the client's billing month, prints to A4 and reads on a phone. |
| **SEO scan** | agency | Every published page checked as Google sees it, ranked by opportunity, with the page review. |
| **Search Console** | agency | The site's last week and 12-week lines, and every page with its searches. |
| **Changes** | agency | Every applied change with its before and after, its effect on clicks after 4 full weeks, Undo, and a CSV export. |
| **Settings** | agency | Claude key and model, monthly spend cap, brand tone, who sees the tools, report delivery. |

An Administrator who is not agency staff sees **Report** only. "Agency" is AJR Core's `Support::is_agency_user()`, or the list ticked under **Who sees the tools**. If neither names a current Administrator, every Administrator keeps the tools, so an update locks no one out. This keeps the menu tidy for the client; it is not a security boundary.

## The data comes to the site; the site holds no Google login

The agency's `retainer-scan` (claude-workspace `toolkits/retainer-scan`) builds each week's snapshot and POSTs it to `/wp-json/ai-seo-assistant/v1/report`, signed with HMAC-SHA256 (`X-AISA-Signature: t=<unix>,v1=<hex>` over `"<t>.<body>"`, 10-minute window). The endpoint refuses a body over 2 MiB **before** it checks the signature, and checks the signature before it decodes anything.

* **Schema 1** (weekly report) is still read.
* **Schema 2** adds the billing month, per-page Search Console and GA4 data (the scan's ranking), enquiry sources with `kind` / `in_total`, and an optional `business_profile_check` that shows as the **Google listing** card in the SEO scan. A tap source that claims `in_total` is refused. Google-sourced text is capped and escaped, and rows that fail the text rules are dropped. Stored snapshots are never autoloaded.
* The per-site push key is made under **Settings → Report delivery** and shown once, or set as `AI_SEO_ASSISTANT_REPORT_KEY` in `wp-config.php`. On a host that blocks the push, import the saved JSON on the same screen.
* If no update arrives for 8 days, one alert email goes out, then a "back on track" email when updates resume.

Upgrading from 4.x **revokes the stored Google token** at Google and deletes it. A failed revoke never blocks the upgrade.

## SEO scan

The scan fetches each published page from the site itself, reads the rendered HTML and checks:

* **Title:** width in pixels (580 px), duplicates.
* **Meta description:** length and duplicates.
* **Headings:** one H1, no skipped levels.
* **Images:**
  * missing or weak alt text, and the same alt text shared across images;
  * alt text the page does not print: a Divi module or a block with its own alt;
  * the printed alt disagreeing with the Media Library's;
  * heavy images.
* **Links:**
  * pages nothing links to, and too few links in or out;
  * broken links, and links that go through a redirect.
* **Indexing:** noindex pages listed in the sitemap, pages missing from the sitemap, and canonicals pointing elsewhere. These checks are skipped while search engines are discouraged.
* **Schema and content:** schema type, sharing image and thin content. The "add Service schema" advice is given only while AJR Core's `custom_schema` module is on.

Results go in the custom table `{prefix}aisa_scan`.

The scan runs:

* after each push;
* when a page is saved, as a deferred event for that page;
* when you press **Rescan now**. This runs in AJAX steps from the browser, so it works where WP-Cron is off.

Opening a page review also rescans that page if it was edited since its last scan. Apply and Undo rescan the page in the same request.

### Opportunity

Pages are ranked by the extra clicks a better listing could win (`src/Scan/Opportunity.php`, decision 2026-10-06):

```
missed clicks = Σ per top search: impressions × max(0, expected CTR at its position − its CTR) × reach
              + the rest of the page's impressions at its average position
weighted      = missed clicks × role value × (1 + ln(1 + enquiries))
score         = 0–100, the top page 100
```

* `reach` is 1 up to position 20 and fades to 0 at 30. Pages pushed with v1 data use the page-level formula.
* The list and the review show **≈ N extra clicks / 90 days**. The review splits the number search by search.
* **Expected CTR is the site's own when there is enough data.** Positions are bucketed: 1–10 one by one, then 11–15, 16–20, 21–30 and 31–50.
  * A bucket with 1,000 impressions uses the site's CTR.
  * A thinner bucket takes the standard value × own ÷ standard at the nearest calibrated bucket.
  * The curve is then smoothed so it never rises as position falls, and cached per push.
  * The `ai_seo_assistant_expected_ctr` filter overrides it.
* **Role values:** money 1.5, location 1.2, unclassified 1.0, info 0.6.
  * By default posts are info.
  * A page is money when it is AJR Core's booking page, when it holds a form (Gravity Forms, WPForms, CF7, Fluent, Ninja, Formidable, AJR Forms, Divi) or a form or booking embed (Calendly, HubSpot, Typeform…, plus AJR Core's `google.booking_domains`), or when the main menu links to it at the top level.
  * A page whose enquiry rate is twice the site's counts one role higher, but only when the site had 10 or more enquiries.
  * The agency sets a role in one click (post meta `_aisa_page_role`, never shown to the client).

### Page review

**Generate** sends Claude the page's text, its real searches, its figures and the business facts from AJR Core. It also sends the images themselves, as image blocks, so the alt text describes the actual photo and names landmarks.

* Claude suggests a title, description, keyphrase and alt text, each with Accept, Edit or Skip.
* A good alt is never replaced blind: an image whose alt is already good is only checked.
* **Generate for selected** on the list shows a cost estimate first.

**Apply** writes through the SEO plugin in use (Yoast, Rank Math or The SEO Framework).

* Alt text goes to the Media Library **and** to where the page prints it: a Divi image, full-width image, blurb or slide module, a core image block, or a classic `<img>`.
* Content writes are guarded:
  * the content is backed up in the change log first;
  * the edit must match exactly one place;
  * it runs only for a user with `unfiltered_html`, with `wp_slash` and no kses;
  * it is read back after saving, and rolled back if it does not match.
* After Apply, the rendered page is checked. An alt that does not appear is marked **Not visible on the page**, with the reason.

**Undo** restores the content byte for byte. It never overwrites a field changed again since.

Issues Claude cannot fix are shown as **N left · do in the editor**, with an **Applied** badge.

### Spend cap

Claude calls are priced from their real token usage and counted against a cap per billing month: $10 by default, set in Settings. The month starts on the client's billing day, taken from the push or from Settings. Once the cap is reached, generating stops until the next billing month. Opus 5 is the default model: about 2–3¢ a page, about 6¢ with recommendations.

## Requirements

* WordPress 6.5+, PHP 8.0+
* AJR Core (active)
* Yoast SEO, Rank Math or The SEO Framework for titles and descriptions
* A Claude API key (Settings, or `AI_SEO_ASSISTANT_ANTHROPIC_API_KEY` in `wp-config.php`)

Secrets saved on the settings screens (the Claude key and the report push key) are sealed with libsodium `secretbox`, using a key derived from the site's `AUTH_*` and `SECURE_AUTH_*` salts. The fields are write-only. A `wp-config.php` constant always wins over a saved value.

## Install and release

* The release zip is built by CI (`.github/workflows/release.yml`). It has **no `vendor/` directory**: the plugin autoloads its own classes, and Composer is for development only.
* Upload the zip under **Plugins → Add New → Upload Plugin**.
* **Upgrading from 4.x keeps your redirects.** Enabled redirect rules are never deleted. The old table stays until **Settings** confirms that every enabled rule is in AJR Core's redirect map.

## Development

```bash
composer install            # dev tools only (PHPUnit, WP_Mock, PHPCS)
vendor/bin/phpunit          # unit tests (WP_Mock)
vendor/bin/phpcs            # WordPress standards, on the files listed in phpcs.xml
```

CI runs both on every pull request.

```text
src/
├── Core/       Plugin (wiring), Schema (custom tables), Upgrade, Secret_Store, Secret_Guard
├── Adapters/   Yoast, Rank Math, The SEO Framework
├── AI/         Claude_Client, Prompt_Builder, Spend, Metadata_Generator (editor box)
├── Content/    Business (AJR Core facts), Content_Extractor
├── Report/     Snapshot (v1), Snapshot_V2, Snapshot_Store, Push_Endpoint, Report_Page, views, Access
├── Search/     Page_Data (per-page search data)
├── Scan/       Page_Fetcher, Html_Parser, Rules, Scanner, Scheduler, Opportunity, Page_Role, Ranking
├── Review/     Page_Review, Alt_Writer
├── Changes/    Change_Log
└── Admin/      Menu, Ui, Scan_Page, Search_Console_Page, Changes_Page, Settings_Page, Tools_Actions
```

This repository is public: never commit a key. A real key that was ever committed must be rotated.

## License

See `LICENSE`.
