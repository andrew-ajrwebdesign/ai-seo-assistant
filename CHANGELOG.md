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
  - High / Medium / Low tiers in place of the 0–100 score.
- **Page types from AJR Core** (`_ajr_page_type`) replace the plugin's own page role, set from the list, in bulk or from the review. Old roles are migrated: location → area, info → article, money left for the agency to choose. Post listings count as information.
- **"What Google reads on this page"** in the review. Schema findings come from rules only, and Claude never writes schema advice.
- **Google listing issue group:** pinned differences are kept on purpose and not counted. The stored check goes to AJR Core through `ajr_core_business_profile_check`.
- **Full-width screens** with AJR Core's "Need a hand?" card column (AJR Core 0.22).
- **Alt text rows** show "shown on the page", "Media Library" and "suggested" on separate lines.
- **phpcs** now also covers src/Scan, src/Review, src/Changes, src/Search and src/Admin.

### Removed
- Markdown for AI, Redirects, the `core_owns_*` hand-over filters, the Metadata report, Indexing Tools, the Google Search Console OAuth screen and client, and the runtime Composer dependency (no `vendor/` in the zip).

### Upgrade
- **Requires AJR Core.**
- The stored Google token is revoked at Google and deleted. A failed revoke does not block the upgrade.
- Enabled redirect rules are never deleted. The old table is kept until Settings confirms that every enabled rule is in AJR Core.

## 4.4.0
- Secrets sealed at rest (Secret_Store, Secret_Guard), agency-only tools with a no-lockout fallback, and stack review fixes.
