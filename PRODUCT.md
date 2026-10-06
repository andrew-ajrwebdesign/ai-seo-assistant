# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **The client business owner** (primary). A small-business owner on an AJR Web Design retainer (e.g. a local plumber). They log in to their own WordPress admin roughly once a week and glance at the Weekly report to see whether the retainer is working. Only Administrator accounts see the report (Andrew, 2026-09-29: "Owner only"); staff with Editor logins do not.
- **Andrew Ryan (AJR Web Design)**. Writes the weekly note, checks the report reads correctly for the client, and uses the plugin's tool screens (Settings, Audit, Recommendations, Search Console). Those tool screens are for Andrew, not the client: the client sees the Weekly report only (Andrew, 2026-09-29: "Yes, report only").

## Product Purpose

AI SEO Assistant is the **retainer product**: installed on retainer clients' sites only (memory: product-split-core-vs-ai-assistant). The Weekly report makes the retainer visible every time the client logs in — how many enquiries came in and from where, what Andrew did this week and what is next, and the Search Console, Analytics and Ads figures behind it. Success: the owner understands in a glance whether the week was good, and sees the agency's work without asking.

## Positioning

- **Enquiries first, from every source.** The headline is the number of enquiries and where they came from (phone calls, website form, bookings, email), not traffic. Ads/GA4 see a slice; the report counts all measurable sources, each labelled (rule: reports show all enquiry sources).
- **A person, not a dashboard.** Andrew's note sits at the top, beside the enquiries (confirmed 2026-09-29).
- **No Google keys on the client's site.** Figures are built on Andrew's machine (retainer-scan) and pushed weekly over HTTPS; the site stores the report only. The report footer says so.

## Operating Context

- Lives in wp-admin under AI SEO Assistant → Weekly report, among normal WordPress admin screens (admin bar, left menu, notices).
- Updated every Monday by a signed push (or a JSON import when a host blocks outside POSTs). Reports are kept for 12 months; the owner can step back week by week.
- States: a normal week; **late** (the Monday update did not arrive — says so plainly, keeps last week's figures, and Andrew is emailed automatically); **empty** (before the first update).
- Retainer end = the push stops and the report stops updating; no licensing.

## Capabilities and Constraints

- WordPress plugin (PHP 8.0+, WP 6.0+), namespace `AJR\SEOAssistant\`, text domain `ai-seo-assistant`. No build step; plain PHP-rendered admin screens with WordPress admin styles and components.
- Charts are server-rendered inline SVG; the report must be fully readable without JavaScript.
- Figures come only from the pushed snapshot (format v1, architecture map §12). A source with no data is omitted, never shown as 0. Nothing is estimated or invented on the site.
- Undecided: whether the report is also emailed to the owner (not requested; out of scope for 4.3.0).

## SEO scan: how pages are ranked (agency only)

The SEO scan lists pages by **opportunity**: the extra visits a year a better Google listing could win, weighted by what the searches and the page are worth. Approved by Andrew 2026-10-06 (v2 in the morning, v3 "yes add all four" the same day). The code and its reasoning are in `src/Scan/Opportunity.php` and `src/Scan/Intent.php`.

- **Two yearly figures, both estimates.**
  - **Quick win:** what a better title and description could bring at today's position. It is worked out for each of the page's top searches as impressions × (expected CTR at its position − its CTR), then × 365/90.
  - **Top-3 prize:** the same if each search reached position 3.
  - The page's other impressions (searches Google does not name) count at the page's average position.
  - A search past position 20 adds almost nothing to the quick win.
  - Pages pushed with v1 data use the page-level formula.
- **Search intent.** Each search is sorted by keyword rules first. These are generic, plus extra phrases for AJR Core's business type, and filterable with `ai_seo_assistant_intent_rules`. For an estate agent (Andrew, round 3), researching a move is **commercial**, not a lead: "moving to", "relocation", "relocating", "real estate", "living in", "cost of living". Lead stays for agent, realtor, broker, selling, homes for sale, home value and listing a home. The searches the rules leave go to Claude (Haiku 4.5) once per push, in one call; a change to the rules runs it again, and the rules always win over a cached answer. That call counts toward the spend cap and is skipped when the cap is reached; its answers are cached. The weights are:

  | Intent | Weight |
  |---|---|
  | Lead (ready to enquire) | 3 |
  | Commercial (comparing) | 2 |
  | Informational (learning) | 1 |
  | Navigational (looking for this business by name) | 0.5 |
  | Not sorted yet | 1 |

  The unnamed searches take the named ones' average weight.
- **Page value from its page type.** The page type is set in AJR Core; it is the same setting that decides the page's schema.

  | Page type | Value |
  |---|---|
  | Service, contact | 1.5 |
  | Area | 1.2 |
  | Article, FAQ, team member | 0.6 |
  | Other | 1.0 |

  - **Not set:** a post counts as information, and so does a page that is mainly a list of posts. Otherwise a page counts as money when it is AJR Core's booking page, holds a form AJR Core's Leads module counts (or a known form or booking embed), or is in the main menu's top level.
  - **Enquiry rate:** a page whose enquiry rate is twice the site's counts one step higher, but only when the site has 10 or more enquiries in 90 days.
- **Enquiry estimate.** "≈ N enquiries a year" is the extra visits × the page's enquiry rate (the site's when the page had fewer than 30 visits). It is shown only when the site tracked 10 or more enquiries in 90 days, and is never shown as 0.
- **Tiers that scale with the site, not a 0–100 score** (Andrew, 2026-10-06, round 3). Pages are sorted by their weighted value a year: the figure × intent × page value × (1 + ln(1 + enquiries)).
  - **High:** the smallest set of top pages that together hold half the site's total.
  - **Medium:** the pages holding the next quarter.
  - **Low:** the rest with a value. **None:** no measurable value.
  - **Floors**, so a trivial gain never reads High or Medium: High needs 24 weighted visits a year, Medium 8 (filter: `ai_seo_assistant_opportunity_floors`).
- **"Quick wins | Biggest prizes".** A toggle on the SEO scan list. Quick wins (the default) ranks and tiers by the weighted quick win. Biggest prizes ranks and tiers by the weighted top-3 prize: the pages worth content and link work. Each agency user's choice is remembered (user meta `aisa_rank_mode`). The review header shows both figures either way.
- **The site's own click curve.** Expected CTR comes from the site's own searches when there are enough.
  - Searches are bucketed by position: 1 to 10 each, then 11–15, 16–20, 21–30 and 31–50.
  - A bucket with 1,000 impressions uses the site's CTR.
  - A thinner bucket takes the standard value × own ÷ standard at the nearest calibrated bucket.
  - The curve is smoothed so it never rises with position, worked out once per push, and named in the scan footnote.
  - The `ai_seo_assistant_expected_ctr` filter still overrides it.

## What Google reads, and the Google listing (agency only)

- **Schema is never an editor job.** AJR Core prints the structured data from each page's type and the business details. Google's Business Profile is the source of truth for those details.
- **What the scan reports.** The scan's only page-level schema finding is "Page type not set" (one click). Claude is never asked for schema advice.
- **"What Google reads on this page"** is a panel in the page review. It shows:
  - the page type, with AJR Core's suggestion and a one-click "Set as …";
  - whether the page names the business, and whether the business matches Google;
  - the structured data found on the rendered page, in plain words;
  - a link to Google's Rich Results Test.
- **The "Google listing" group** in the SEO scan comes from the weekly push's `business_profile_check`.
  - A difference the agency pinned in the Business details row of AJR Core → Your essentials is "Kept on purpose": shown with its reason, never counted.
  - A check that could not run says "Google listing not checked", never "all match".
  - The plugin hands the stored check to AJR Core through `ajr_core_business_profile_check`.

## Layout

Every screen uses the full admin width. AJR Core's "Need a hand?" card sits in a right-hand column, as on AJR Core's own screens, and drops below the content on narrow screens. The client's Report shows it too: the card is for the client. It needs AJR Core 0.22 (`Support::render_card()`); before that the screens are full width without it.

## Brand Commitments

- **AJR-branded premium look** (confirmed 2026-09-29): unmistakably AJR Web Design's work inside wp-admin, not a generic stats page. Header reads "Weekly report by AJR Web Design".
- Voice: plain English for a non-technical owner ("times you appeared", not "impressions"; "Figures cover Monday 21 to Sunday 27 September").

## Evidence on Hand

- Approved Figma mockup: https://www.figma.com/design/reORO92GJaeEhxTgWuaj9R — Desktop weekly report (3:2), Mobile (4:35), Stale data (5:68), Empty (5:469). The figures in it (Northfield Plumbing & Heating) are illustrative, not a real client.
- Test fixture with the mockup's figures: `tests/fixtures/snapshot-week.json`.
- No real client data may appear in the product, docs or screenshots without that client's report.

## Product Principles

1. The owner's question — "was this a good week, and what are you doing for me?" — is answered before anything else.
2. Every number says where it came from and what period it covers.
3. Never show a number the data does not support: missing is missing, not zero.
4. The report is the owner's; the tools are Andrew's.

## Accessibility & Inclusion

WCAG 2.2 AA in wp-admin: charts carry a text summary, colour is never the only signal (up/down changes are written out), and the page works at 200% zoom and on a phone.
