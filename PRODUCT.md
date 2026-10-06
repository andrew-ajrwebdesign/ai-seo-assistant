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

The SEO scan lists pages by **opportunity**: the extra clicks a better Google listing could win, weighted by what a click on that page is worth. Approved by Andrew 2026-10-06; the code and its reasoning are in `src/Scan/Opportunity.php`.

- **Per search.** Each of the page's top searches (snapshot v2) counts on its own: impressions × (expected CTR at its position − its CTR). The page's other impressions and clicks (searches Google does not name) count at the page's average position. A search past position 20 adds almost nothing (fades to zero at 30): a better title does not win clicks on page 3. Pages pushed without searches (v1 data) use the page-level formula.
- **The number is shown.** The list and the review header show "≈ N extra clicks / 90 days" ("< 5" for a handful) beside the 0–100 score; the review shows where those clicks are, search by search. Sorting is by the score.
- **The site's own click curve.** Expected CTR comes from the site's own searches when there are enough: bucketed by position (1 to 10 each, 11–15, 16–20, 21–30, 31–50), a bucket with 1,000 impressions uses the site's CTR, otherwise the standard curve; smoothed so it never rises with position; worked out once per push. The scan footnote says which curve is in use. The `ai_seo_assistant_expected_ctr` filter still overrides it.
- **Business value.** weight = value of the page's role × (1 + ln(1 + enquiries)). Money pages (services, booking, contact, home value, landing) 1.5; location pages 1.2; unclassified 1.0; information (blog, news) 0.6. By default a post is information; a page is money when AJR Core names it as the booking page, when it holds a Gravity Forms form, or when the main menu links to it at the top level (not the blog index); everything else is unclassified. A page whose enquiry rate is at least twice the site's counts one role higher, but only when the site had 10 or more enquiries in the 90 days. The agency sets a role in one click (the tag on the list, bulk for selected pages, or the review header); it is stored in post meta `_aisa_page_role`, wins over the default, and is never shown to the client.

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
