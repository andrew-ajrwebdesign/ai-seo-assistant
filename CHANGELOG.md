# Changelog

## 5.0.0 — 2026-10-06

The product cut (decision 2026-10-06, `work/coding-projects/ai-seo-assistant/decisions.md`): the client gets the Report, the agency gets the SEO tools, and AJR Core does everything else.

### Added
- **SEO scan** of every published page, read from its rendered HTML.
  - Checks: title width in pixels, descriptions, headings, image alt text and weight, internal links, broken links and links through redirects, indexing against the sitemap, schema, sharing image and thin content.
  - A "Google listing" card shows the pushed Business Profile check.
  - Runs after each push, when a page is saved, and on **Rescan now**. Rescan now works in AJAX steps, so it does not need WP-Cron.
- **Opportunity ranking.**
  - Missed clicks are counted per search, with the site's own click curve.
  - A page's role (money, location, unclassified, info) weights its clicks, and the agency can set the role in one click.
  - The list and review show "≈ N extra clicks / 90 days".
- **Page review.**
  - Claude suggests title, description, keyphrase and image alt text, each with Accept, Edit or Skip.
  - The images are sent to Claude, so the alt text describes the actual photo, and a good alt is never replaced blind.
  - Alt text is written to the Media Library and to where the page prints it: Divi modules, core image blocks and classic images. These content writes are guarded, and each alt is checked on the rendered page after Apply.
  - The page is rescanned straight after Apply or Undo.
- **Changes log**: before and after for each change, the effect on clicks after 4 full weeks, Undo (byte-identical for content), and CSV export.
- **Search Console** screen and a **Monthly** report on the client's billing month, which prints to A4 and reads on a phone.
- **Spend cap**: $10 a billing month by default, counted from real token usage. Opus 5 is the default model.
- **Snapshot schema 2.**
  - Adds the billing month, per-page data, enquiry sources by kind, and `business_profile_check`.
  - Taps are never added to the total.
  - The endpoint checks size first, then the signature, then decodes.

### Added (round 2, same day)
- **Opportunity v3:**
  - yearly quick win and top-3 prize per search;
  - search-intent weighting: keyword rules, then one Haiku pass per push, cached and counted in the cap;
  - an enquiry estimate, shown only with 10 or more tracked enquiries;
  - High / Medium / Low tiers in place of the 0–100 score. Round 3: the tiers scale with the site (High = the top pages holding half the total, Medium = the next quarter), with floors of 24 and 8 weighted visits a year.
  - A "Quick wins | Biggest prizes" toggle on the list, remembered per user.
  - Estate-agent intent: researching a move is commercial, not a lead. The intent pass runs again when the rules change.
- **Page types from AJR Core** (`_ajr_page_type`) replace the plugin's own page role, set from the list, in bulk or from the review. Old roles are migrated: location → area, info → article, money left for the agency to choose. Post listings count as information.
- **"What Google reads on this page"** in the review. Schema findings come from rules only, and Claude never writes schema advice.
- **Google listing issue group:** pinned differences are kept on purpose and not counted. The stored check goes to AJR Core through `ajr_core_business_profile_check`.
- **Full-width screens** with AJR Core's "Need a hand?" card column (AJR Core 0.22).
- **Alt text rows** show "shown on the page", "Media Library" and "suggested" on separate lines.
- **phpcs** now also covers src/Scan, src/Review, src/Changes, src/Search and src/Admin.

### Final review round (same day)
- **Page types set automatically** when AJR Core 0.22 is sure (`suggest_with_confidence()` high): logged in Changes as "Automatic" with Undo. "Page type not set" is an issue only for an unsure (medium) guess; a page nothing points to is "other". A ticked "Review and apply all" list applies the medium guesses as one batch. Any manual change or Undo makes the page manual, and auto-apply never touches it again. Settings toggle, on by default. The list shows an "auto" marker; the review shows "Set automatically" and "Change".
- A page type set outside the plugin clears a stale "Page type not set" at once (render-time check and `ajr_core_page_type_changed`).
- **Progress bar** in the scan header: pages done of total, time left, Cancel (keeps what was scanned), resume from the stored position, and an in-place refresh with "N fewer issues than before".
- **Less noise:** a template finding on more than half the pages is reported once, "Across the site". Only the entry content is judged (no sidebar, author box, related posts). Menu and footer links count as links in. `alt=""` is its own softer finding, and decorative images (`role="presentation"`, `aria-hidden`) owe no alt. Link suggestions must be on a related topic. "Short page" (was "Thin content") skips contact, team, other, form and calculator pages.
- **Review prompt:** title width and description length are checked on the server, with one text-only retry. The SEO plugin's appended site name is told to Claude. The agency's phrases and brand rule are carried, and sibling pages are named for duplicates. New alt rules: logos are transcribed, card images get `alt=""`, landmarks are named only when certain.
- **Google listing:** the profile check's `suggestions[]` and `suggestions_unchecked[]` are validated and passed to AJR Core; the scan shows one line, "N suggested edits".
- **Safety:**
  - Alt text is matched by uploads path and never written into another attachment's module.
  - Own-site fetches never follow redirects.
  - Password-protected pages are never scanned or sent to Claude.
  - Each tools AJAX action has its own nonce.
  - The push size is checked before WordPress decodes the JSON.
  - A failed Google revoke shows a one-time notice.
  - A persistent notice appears when every Administrator has the tools.
- **Data:**
  - Pushed page data is replaced all-or-nothing in a transaction, keyed lower-case.
  - A failed child sitemap is "not checked", never "not in the sitemap".
  - The link cache is not refreshed on reads.
  - Schema 3 adds `scan.flags`.
  - Uninstall removes every option.
- **Speed:**
  - Saves join one queued run.
  - Inline rescans make no network calls.
  - Unchanged issues are not rewritten.
  - Caches are primed in one query.
  - admin-ajax builds the admin stack only for this plugin's actions.
  - A page that crashes a step is skipped.
- **Zero-click searches** (weather, time, distances…): detected from the clicks (top 5, 300+ impressions, under a sixth of the expected CTR), with a word list as a second signal that never decides alone. They weigh 0.1 (filterable), stay out of the site's click curve, and are labelled in the review's searches table.
- **Last review round:**
  - Listing suggestions follow the schema's rules, with retainer-scan's own fixtures.
  - Page types are housekeeping, not applied work: one Changes row per batch with "Undo all", and none in "Applied" or the client report.
  - The main search is never a zero-click search or a sentence, and the town alone is not the topic.
  - Template images (on most pages) are one site-wide alt finding.
  - Visits read "up to"; "map" and "directions" join the zero-click words.
  - The push size check ignores case.
  - Spend is one atomic SQL increment.
  - Cancel sticks.
  - The listing line uses AJR Core's open count.
  - No innerHTML.
  - Automatic types are logged first.
  - A failed page-data save is reported.
  - Changes stores content summaries (schema 4) and shows 50 rows a page.
  - Waiting types are set 100 at a time.
  - Upgrade steps run after an AJAX or WP-CLI update.
  - The progress bar pauses in a hidden tab.
- **The post editor's box is now "SEO to-do for this page"** (Andrew: "all we really need to see is the do this in editor"). It shows the tier, the to-do count, the last scan date and a link to the full review, then the headings, links and content to-dos, each with a **Done** tick that queues a rescan. The 4.x box is gone: its title and description fields (Yoast's own box has them), the "Local SEO Focus" form with another client's example placeholders, the generate buttons, the live preview into Yoast, and the save handler. The plugin now writes nothing when a post is saved. Notes already typed there are still read by the review until cleared.
- **Fix:** the bulk bar's "Generate for selected (3)" label is no longer written into the button's icon span.
- **Fix: page builders' text is read.** The editor advice's phrase check and the review's page text both came through `strip_shortcodes()`. With Divi's modules registered, that deletes every module's enclosed text, so a Divi page read as empty and content to-dos never ticked themselves.
  - The scan now stores each page's rendered visible text (schema 5, `scan.body_text`). That includes builder modules and attribute-only copy such as a blurb's title.
  - Rows scanned before this fall back to the title and content with shortcode tags removed but their text kept.
  - Tags are stripped before entities are decoded, so copy reading `&lt;title&gt;` stays words.

### Removed
- Markdown for AI, Redirects, the `core_owns_*` hand-over filters, the Metadata report, Indexing Tools, the Google Search Console OAuth screen and client, and the runtime Composer dependency (no `vendor/` in the zip).

### Upgrade
- **Requires AJR Core.**
- The stored Google token is revoked at Google and deleted. A failed revoke does not block the upgrade.
- Enabled redirect rules are never deleted. The old table is kept until Settings confirms that every enabled rule is in AJR Core.

## 4.4.0
- Secrets sealed at rest (Secret_Store, Secret_Guard), agency-only tools with a no-lockout fallback, and stack review fixes.
