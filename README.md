# AI SEO Assistant

AI SEO Assistant is a WordPress admin plugin for generating SEO metadata, reviewing page content, producing SEO recommendations, and using Google Search Console data to support smarter page-level optimization decisions.

The plugin is designed for controlled WordPress admin workflows. It is not intended to automatically rewrite public content without review. Its purpose is to help developers, site owners, and SEO teams identify practical page-level improvements using a combination of AI analysis, site context, page content, and real search performance data.

## Features

### AI Metadata Generation

Generate page-level SEO metadata suggestions, including:

* SEO titles
* Meta descriptions
* Focus keyword ideas
* Page-level SEO summaries

The plugin uses the OpenAI API when an API key is configured.

### SEO Recommendations

Generate structured SEO recommendations for individual pages or posts.

Recommendations may include:

* Metadata issues
* Missing or weak topic coverage
* Search intent alignment
* Page clarity issues
* Repeated or vague headings
* Thin content sections
* Internal linking opportunities
* Content placement suggestions
* Local SEO improvements where relevant

When Google Search Console data is available, recommendations can be informed by real search queries and page performance data.

When Search Console data is not available, the plugin should still perform a general page-quality review instead of assuming the page is already optimized.

### Google Search Console Integration

AI SEO Assistant can connect to Google Search Console through Google OAuth and use Search Console data to support page-level recommendations.

Search Console data may be used to identify:

* Top queries
* Existing visibility
* Search intent patterns
* Content gaps
* Missed topic opportunities
* Page-level optimization priorities

### SEO Focus Autofill

The plugin includes an Autofill SEO Focus feature for editor/admin workflows.

Autofill can use:

1. Google Search Console data
2. Existing page content
3. Global plugin settings
4. Site-level SEO context

By default, autofill should only populate empty fields unless overwrite behavior is explicitly enabled.

### Content Placement Suggestions

The plugin can return conservative content insertion suggestions when a page appears to be missing an important concept, term, or supporting topic.

These suggestions are intended to be:

* Limited
* Practical
* Page-specific
* Reviewable by an admin or editor

The plugin should avoid repeating the same content suggestion endlessly for the same page issue.

### SEO Plugin Integration

AI SEO Assistant auto-detects the active SEO plugin. No manual configuration is required.

Supported plugins:

* The SEO Framework
* Yoast SEO
* Rank Math

When a supported plugin is active, generated metadata is written directly to that plugin's meta fields. If no supported plugin is detected, a notice is shown in the admin and the audit, report, and indexing pages are hidden until a supported plugin is activated.

In the block editor, the active SEO plugin's snippet preview updates live after metadata is generated, without requiring a hard refresh.

### Markdown and LLMs.txt

The plugin can generate a machine-readable Markdown index and full-content export of the site for use with AI tools and LLM context.

Generated endpoints:

* `/llms.txt` — index of public post titles and URLs
* `/llms-full.txt` — full post content export, paginated across multiple pages (`/llms-full-2.txt`, `/llms-full-3.txt`, etc.)

Output is cached using WordPress transients. Cache is flushed automatically when posts are saved or the plugin settings change.

### SEO Audit Tools

The plugin includes admin audit tools for reviewing page-level SEO status, metadata, content coverage, and available Search Console signals.

The audit logic is intended to support concept-aware matching, so related terms and multilingual equivalents can be treated as coverage where appropriate.

### Indexing Tools

The plugin includes indexing-related admin tools for reviewing and applying noindex recommendations.

Indexing tools are handled separately from metadata and content recommendations.

Potential noindex candidates may include:

* Policy pages
* Legal pages
* Utility pages
* Thank-you pages
* Internal system pages
* WooCommerce system pages, where applicable

Indexing recommendations should always be reviewed before applying changes to a production site.

### Redirects

A **Redirects** admin page (under the AI SEO Assistant menu) lets you send old or broken URLs to a new destination so visitors and search engines get a real page instead of a 404.

* Match is on the exact request path, with the trailing slash ignored and matching case-insensitive by default (filterable via `ai_seo_assistant_redirects_case_insensitive`).
* Supported types: `301` permanent, `302` temporary, `307` temporary (method preserved), and `410` Gone (no destination).
* Redirects are stored in a dedicated table and served from a compact, autoloaded lookup map, so a normal front-end request performs no extra database query and no write.
* When Google Search Console is connected, the page surfaces cached GSC URLs that no longer resolve on the site as one-click redirect suggestions (some archive or term URLs can appear here even when valid, so review before creating a redirect).

## Requirements

* WordPress 6.0+
* PHP 8.0+
* Administrator access to WordPress
* OpenAI API key for AI-powered generation
* Google Cloud OAuth credentials for Search Console integration
* Verified Google Search Console property for the site

## Installation

> No build step is required. The `vendor/` directory (the Composer autoloader and dependencies) is committed to the repository, so the plugin installs and activates directly from any GitHub download or clone — you do **not** need to run Composer on the target site.

### Option 1: Install from a Zip File

1. Download or create a zip of the plugin folder.
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the plugin zip file.
4. Activate **AI SEO Assistant**.

### Option 2: Install Manually

Upload the plugin folder to:

```text
wp-content/plugins/ai-seo-assistant
```

Then activate the plugin from:

```text
WordPress Admin → Plugins
```

### Option 3: Install with Git

From the WordPress plugins directory:

```bash
cd wp-content/plugins
git clone git@github.com:andrew-ajrwebdesign/ai-seo-assistant.git
```

Then activate the plugin in WordPress admin.

## API Key Configuration

For security, API keys should be stored in `wp-config.php`, not committed to Git and not hard-coded into plugin files.

Add configuration constants above this line in `wp-config.php`:

```php
/* That's all, stop editing! Happy publishing. */
```

### OpenAI API Key

```php
define( 'AI_SEO_ASSISTANT_OPENAI_API_KEY', 'your-openai-api-key-here' );
```

Do not commit a real OpenAI API key to this repository.

## Google Search Console OAuth Setup

To connect Google Search Console, create a Google OAuth client in Google Cloud Console.

### Required Google Cloud Steps

1. Go to **Google Cloud Console**.
2. Create or select a project.
3. Enable the **Google Search Console API**.
4. Configure the OAuth consent screen.
5. Create an OAuth client ID.
6. Set the application type to **Web application**.
7. Add the required authorized redirect URI for the WordPress site.

The redirect URI will typically look like:

```text
https://your-site.com/wp-admin/admin-post.php?action=ai_seo_assistant_gsc_callback
```

Replace `your-site.com` with the domain where the plugin is installed.

If using the same Google OAuth app across multiple sites, each site must be added as an authorized redirect URI.

### Optional Google Credential Constants

Depending on plugin configuration, Google OAuth credentials may be stored in `wp-config.php` using constants such as:

```php
define( 'AI_SEO_ASSISTANT_GOOGLE_CLIENT_ID', 'your-google-client-id-here' );
define( 'AI_SEO_ASSISTANT_GOOGLE_CLIENT_SECRET', 'your-google-client-secret-here' );
```

Do not commit a real Google client secret to this repository.

## OAuth Troubleshooting

### Error: redirect_uri_mismatch

This means the redirect URI sent by WordPress does not exactly match one of the authorized redirect URIs in Google Cloud Console.

Check for differences in:

* `http` vs `https`
* `www` vs non-`www`
* trailing slashes
* query strings
* staging vs production domains
* incorrect callback action names

The authorized redirect URI should match the value sent in the OAuth request exactly.

### Error: access_denied

This may happen if the Google OAuth app is still in Testing mode and the Google account trying to connect has not been added as a test user.

In Google Cloud Console, go to the OAuth consent screen settings and add the connecting Google account as an approved test user.

### OAuth App Testing Mode

If the OAuth app is in Testing mode, only approved test users can connect.

For private or internal use, adding approved test users is usually enough.

For wider public use, the OAuth app may need to be published and may require Google verification depending on the scopes requested.

## Initial Plugin Setup

After activation, follow this setup order:

1. Confirm the OpenAI API key is available.
2. Configure Google OAuth credentials, if Search Console integration is needed.
3. Connect Google Search Console.
4. Select the correct Search Console property.
5. Configure site-level SEO context.
6. Configure priority services, locations, tone, and avoided phrases where applicable.
7. Test metadata generation on a draft or low-risk page.
8. Run SEO recommendations on one real page.
9. Review audit and indexing tools before applying broad changes.

## Recommended Production Testing

Before using the plugin broadly on a live site:

1. Activate the plugin.
2. Confirm no fatal errors appear.
3. Confirm OpenAI API key detection.
4. Generate metadata for one test page.
5. Connect Google Search Console.
6. Sync Search Console data.
7. Generate SEO recommendations for one page.
8. Review the quality of recommendations.
9. Confirm content insertion suggestions are relevant and not repetitive.
10. Confirm audit and indexing tools behave as expected.

## Development Workflow

### Local Development

Clone or place the plugin inside a local WordPress install:

```bash
cd wp-content/plugins/ai-seo-assistant
```

Check repository status:

```bash
git status
```

### Normal Git Workflow

```bash
git status
git add .
git commit -m "Describe the change"
git push
```

### Feature Branch Workflow

For larger changes:

```bash
git checkout -b feature/prompt-builder-upgrade
```

Commit and push:

```bash
git add .
git commit -m "Improve prompt builder framework"
git push -u origin feature/prompt-builder-upgrade
```

## Creating a Clean Plugin Zip

> **The `vendor/` directory must be included.** The plugin loads all of its classes through `vendor/autoload.php`, so a zip without it fails to activate with a fatal error. `vendor/` is committed to the repository, so a plain GitHub download already contains it — build a clean zip only when you want a correctly-named `ai-seo-assistant/` folder (GitHub's "Download ZIP" names it `ai-seo-assistant-main`).

From the parent `plugins` directory:

```bash
zip -r ai-seo-assistant.zip ai-seo-assistant \
  -x "ai-seo-assistant/.git/*" \
  -x "ai-seo-assistant/.claude/*" \
  -x "ai-seo-assistant/.env" \
  -x "ai-seo-assistant/.env.*" \
  -x "*/.DS_Store"
```

If you are building from a fresh checkout, regenerate the autoloader first so `vendor/` matches `composer.json`:

```bash
composer install --no-dev -o
```

Upload the generated zip through **Plugins → Add New → Upload Plugin**.

## Security Notes

This repository is public.

Do not commit:

* OpenAI API keys
* Google client secrets
* OAuth refresh tokens
* Site-specific private data
* Client credentials
* Server credentials
* Debug logs containing sensitive data
* Exported settings containing secrets

Before committing, check for obvious secret patterns:

```bash
grep -R "sk-" .
grep -R "GOOGLE_CLIENT_SECRET" .
grep -R "OPENAI_API_KEY" .
```

It is normal for the plugin to contain constant names such as:

```text
AI_SEO_ASSISTANT_OPENAI_API_KEY
```

It is not okay for real key values to be committed.

To check Git history before making a public release:

```bash
git log --all -p | grep "sk-"
```

If a real key was ever committed, remove it from Git history and rotate the key immediately.

## Repository Structure

The plugin follows a PSR-4 layout. Every class lives under the single root
namespace `AJR\SEOAssistant\` (mapped to `src/`) and is autoloaded by Composer.

```text
ai-seo-assistant/
├── ai-seo-assistant.php        # Thin bootstrap: headers, constants, autoload, kickoff
├── composer.json               # PSR-4: AJR\SEOAssistant\ => src/
├── composer.lock
├── README.md
├── LICENSE
├── .gitignore
├── assets/
│   ├── css/
│   └── js/
│       ├── admin.js
│       └── wpmai-admin.js
├── languages/                  # Translation files for the ai-seo-assistant text domain
├── src/
│   ├── Core/                   # Plugin (wiring), Utils, Logger
│   ├── Adapters/               # SEO plugin adapters (TSF, Yoast, Rank Math) + resolver
│   ├── AI/                     # OpenAI client, Prompt_Builder, Metadata_Generator
│   ├── Content/                # Content_Extractor, Local_SEO_Context
│   ├── Admin/                  # Admin, Ajax, Audit/Report/Indexing/Markdown/Redirects pages
│   ├── GSC/                    # Google Search Console client + page
│   ├── Redirects/              # Redirect manager (Redirect_Store + Redirect_Handler)
│   └── Markdown/               # Markdown-for-AI module (Module, endpoints, settings, cache)
└── vendor/                     # Composer dependencies (league/html-to-markdown)
```

## Architecture Notes

### Main Plugin Bootstrap

```text
ai-seo-assistant.php
```

A thin entry point: defines constants, requires the Composer autoloader, then
boots `Core\Plugin` and the `Markdown\Module` on `plugins_loaded`.

### Admin UI

```text
src/Admin/Admin.php
```

Handles admin screens, settings, editor metaboxes, and admin-facing actions.

### OpenAI Client

```text
src/AI/OpenAI_Client.php
```

Handles communication with the OpenAI API and retrieves the configured API key.

### Metadata Generator

```text
src/AI/Metadata_Generator.php
```

Handles metadata generation, SEO recommendations, content placement suggestions, and AI response processing.

### Prompt Builder

```text
src/AI/Prompt_Builder.php
```

Builds structured prompts using site context, page content, SEO focus fields, Search Console data, and recommendation guidance.

### Audit Page

```text
src/Admin/Audit_Page.php
```

Displays audit rows and page-level SEO visibility information.

### Indexing Tools

```text
src/Admin/Indexing_Tools_Page.php
```

Handles noindex recommendations and indexing-related admin tools.

### Markdown-for-AI Module

```text
src/Markdown/Module.php
```

Boots the AI-discovery endpoints (`/llms.txt`, `?format=markdown`, REST API, sitemap), the settings screen, and cache invalidation. Kept as a self-contained module under `AJR\SEOAssistant\Markdown\`.

### Redirects

```text
src/Redirects/Redirect_Store.php
src/Redirects/Redirect_Handler.php
src/Admin/Redirects_Page.php
```

`Redirect_Store` owns the custom redirects table, path normalization, and a compact autoloaded lookup map. `Redirect_Handler` runs early on `template_redirect` and serves the redirect from that map, so a normal front-end request performs no extra database query and no write. `Redirects_Page` provides the admin UI and, when Search Console is connected, the 404 suggestions.

### Utilities

```text
src/Core/Utils.php
```

Contains shared helper methods, including methods for masking sensitive data before logging or displaying errors.

## Prompt Strategy

The plugin is moving toward a structured default prompt framework that can work across different client sites.

The intended prompt context includes:

* Business Profile
* Ideal Customer / Audience
* Voice DNA
* Page Goal / Content Intent
* SEO Focus / Strategic Directives
* Real-World Signals / Search Console
* Page Content

The goal is to produce recommendations that are practical, site-specific, and based on real page context instead of generic SEO advice.

## Recommendation Principles

Recommendations should be:

* Specific
* Actionable
* Page-aware
* Search-intent-aware
* Conservative with content insertion
* Clear about what should change and why

Recommendations should avoid:

* Generic SEO filler
* Invented search demand
* Over-optimization
* Repeating the same suggestion endlessly
* Suggesting metadata changes when existing metadata is already clear and relevant
* Treating lack of Search Console data as proof that a page is perfect

## Content Placement Principles

Content placement suggestions should:

* Recommend no more than one primary insertion at a time
* Focus on the most important missing topic or concept
* Include a clear recommended location
* Explain the reason in admin-friendly language
* Avoid repeating the same suggestion if it has already been shown
* Respect the language of the page for suggested page copy

Admin-facing explanation fields should remain in English for consistency.

## Language Handling

Admin-facing explanations should be in English.

If the page content is in another language, suggested inserted copy may match the page language, but admin fields such as `reason`, `recommended_location`, and implementation notes should remain in English.

## Status

This plugin is in active development.

It should be tested on staging or on a low-risk page before using it broadly on a production site.

## License

See `LICENSE`.
