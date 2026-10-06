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

Every screen uses the full admin width. With AJR Core 0.22, its "Need a hand?" card (`Support::render_card()`) sits in a right-hand column, below the content on narrow screens; the Report shows it too.

An Administrator who is not agency staff sees **Report** only. "Agency" is AJR Core's `Support::is_agency_user()`, or the list ticked under **Who sees the tools**. If neither names a current Administrator, every Administrator keeps the tools, so an update locks no one out, and a warning stays on the plugin's screens, the Dashboard and the Plugins screen until someone is named. This keeps the menu tidy for the client; it is not a security boundary.

## The data comes to the site; the site holds no Google login

The agency's `retainer-scan` (claude-workspace `toolkits/retainer-scan`) builds each week's snapshot and POSTs it to `/wp-json/ai-seo-assistant/v1/report`, signed with HMAC-SHA256 (`X-AISA-Signature: t=<unix>,v1=<hex>` over `"<t>.<body>"`, 10-minute window). The endpoint refuses a body over 2 MiB **before** it checks the signature, and checks the signature before it decodes anything.

* **Schema 1** (weekly report) is still read.
* **Schema 2** adds the billing month, per-page Search Console and GA4 data (the scan's ranking), enquiry sources with `kind` / `in_total`, and an optional `business_profile_check` that shows as the **Google listing** card in the SEO scan. A tap source that claims `in_total` is refused. Google-sourced text is capped and escaped, and rows that fail the text rules are dropped. Stored snapshots are never autoloaded.
* The per-site push key is made under **Settings → Report delivery** and shown once, or set as `AI_SEO_ASSISTANT_REPORT_KEY` in `wp-config.php`. On a host that blocks the push, import the saved JSON on the same screen.
* If no update arrives for 8 days, one alert email goes out, then a "back on track" email when updates resume.

Upgrading from 4.x **revokes the stored Google token** at Google and deletes it. A failed revoke never blocks the upgrade.

## SEO scan

The scan fetches each published page from the site itself (never following a redirect off it), reads the rendered HTML and checks only the page's own content: the entry content when the theme marks it, without the sidebar, author box, related posts or comments. Password-protected pages are never scanned or sent to Claude. It checks:

* **Title:** width in pixels (580 px), duplicates.
* **Meta description:** length and duplicates.
* **Headings:** one H1, no skipped levels.
* **Images:**
  * missing or weak alt text, and the same alt text shared across images;
  * `alt=""`, reported apart (right for decoration); images marked `role="presentation"` or `aria-hidden` owe no alt;
  * alt text the page does not print: a Divi module or a block with its own alt;
  * a copied alt printed over a good Media Library alt;
  * heavy images.
* **Links:**
  * pages nothing links to, and too few links in or out (menu and footer links count as links in; suggested pages to link from must be on a related topic);
  * broken links, and links that go through a redirect.
* **Indexing:** noindex pages listed in the sitemap, pages missing from the sitemap, and canonicals pointing elsewhere. These checks are skipped while search engines are discouraged.
* **Page type, sharing image and short pages** (not for contact, team member, "other", form or calculator pages).

A finding on more than half the pages (six or more scanned) comes from the theme or a template: it is shown once, under "Across the site", and not on each page.

Results go in the custom table `{prefix}aisa_scan`.

The scan runs:

* after each push;
* when a page is saved: any number of saves join one queued run;
* when you press **Rescan now**. This runs in AJAX steps from the browser, so it works where WP-Cron is off. The header shows a progress bar (pages done, time left, Cancel). A scan already running shows at its stored position; leaving the screen is safe, as the queue carries on in WP-Cron. At the end the list refreshes in place and says how many issues more or fewer there are.

Opening a page review also rescans that page if it was edited since its last scan. Apply, Undo and a page type change rescan the page in the same request, without network calls (link checks and the sitemap are left to the next queued run).

### Opportunity

Pages are ranked by the extra visits a year a better listing could win (`src/Scan/Opportunity.php`, `src/Scan/Intent.php`; decisions 2026-10-06).

A search Google answers on the results page itself (weather, time, distances, codes…) is **zero-click**: at position 5 or better, 300+ impressions in 90 days, and clicked under a sixth of what that position earns on the built-in curve. Words such as "weather" or "how far" count only when the search also under-clicks (page 1, 100+ impressions, under a third). A zero-click search weighs 0.1 (filter `ai_seo_assistant_zero_click_weight`), is left out of the site's click curve, and the review says "Google answers this search itself (few clicks possible)".

```
quick win (a year) = Σ per top search: impressions × max(0, expected CTR at its position − its CTR) × reach × 365/90
                     + the unnamed rest at the page's average position
top-3 prize        = the same at position 3
value              = Σ quick win × intent weight × page-type value × (1 + ln(1 + enquiries))
tier               = High: the top pages holding half the site's total value; Medium: the next quarter; Low: the rest
                     (floors: High ≥ 24, Medium ≥ 8 weighted visits a year; filter: ai_seo_assistant_opportunity_floors)
mode               = "Quick wins" (rank by the quick win; default) or "Biggest prizes" (rank by the top-3 prize), per user
```

- **Intent weights:** lead 3, commercial 2, informational 1, navigational 0.5.
  - Searches are sorted by keyword rules first (filter: `ai_seo_assistant_intent_rules`).
  - Searches the rules leave go to Claude Haiku 4.5 once per push. The call counts toward the cap and the answers are cached.
- **Page-type value** comes from AJR Core's page type (`_ajr_page_type`): service and contact 1.5, area 1.2, article, FAQ and team member 0.6, other 1.0.
  - Without a page type: posts and post listings count as information; forms, booking embeds, AJR Core's booking page and top-level menu pages count as money.
- **Enquiry estimate:** "≈ N enquiries a year", shown only when the site tracked 10 or more enquiries in 90 days.
- **Expected CTR:** the site's own when there is enough data. Thin buckets are scaled to the site's level and the curve never rises as position falls. The `ai_seo_assistant_expected_ctr` filter overrides it.

### What Google reads and the Google listing

- **Schema is never an editor job.** AJR Core prints the structured data from the page type and the business details. The scan's only page-level schema finding is "Page type not set" (one click), and Claude never writes schema advice.
- **Page types are set automatically when AJR Core is sure** (0.22: the booking page, a blog post, a title that is one of the services…). Each is logged in Changes as "Automatic" with Undo. "Page type not set" is an issue only when AJR Core is unsure; such pages are in the scan's "Review and apply all" list. A page with nothing pointing anywhere counts as "other". Any manual change or Undo makes the page manual, and auto-apply never touches it again. Settings → "Set obvious page types automatically" (on by default) turns it off. A type set outside the plugin clears "Page type not set" at once.
- **The review's "What Google reads on this page" panel** shows:
  - the page type, with AJR Core's suggestion;
  - the link to the business;
  - the structured data on the rendered page, in plain words;
  - a link to the Rich Results Test.
- **The SEO scan's "Google listing" group** lists the pushed `business_profile_check`. Differences pinned in the Business details row of AJR Core → Your essentials are "Kept on purpose" and not counted.
- **Hand-over to AJR Core:** the plugin returns the stored check on `ajr_core_business_profile_check`, with the profile check's `suggestions` and `suggestions_unchecked`. AJR Core lists them; the scan shows one line, "N suggested edits for your Google listing".

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

Secrets saved on the settings screens (the Claude key and the report push key) are sealed with libsodium `secretbox`, using a key derived from the site's `AUTH_*` and `SECURE_AUTH_*` salts. The fields are write-only. A `wp-config.php` constant always wins over a saved value. Every write to a secret option is checked and sealed on every request, so options.php or another plugin cannot swap in a plain-text key. None of this protects against a hostile Administrator, who can install code or read `wp-config.php`.

## Install and release

* The release zip is built by CI (`.github/workflows/release.yml`). It has **no `vendor/` directory**: the plugin autoloads its own classes, and Composer is for development only.
* Upload the zip under **Plugins → Add New → Upload Plugin**.
* **Upgrading from 4.x keeps your redirects.** Enabled redirect rules are never deleted. The old table stays until **Settings** confirms that every enabled rule is in AJR Core's redirect map.

## Development

Tests: `vendor/bin/phpunit` and `node --test tests/js/*.test.mjs` (no dependencies). Both run in CI with phpcs.


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
