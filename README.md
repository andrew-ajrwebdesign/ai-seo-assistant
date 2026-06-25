# AI SEO Assistant

A WordPress plugin that uses OpenAI to generate SEO metadata and provides audit tools, Google Search Console integration, and indexing utilities — all from the WordPress admin.

## Features

- **AI metadata generation** — generate SEO titles, meta descriptions, and focus keywords for posts and pages using OpenAI (GPT-4o-mini or any OpenAI model)
- **Multi-plugin support** — works with [Yoast SEO](https://yoast.com/wordpress/plugins/seo/), [The SEO Framework](https://wordpress.org/plugins/autodescription/), and [Rank Math](https://rankmath.com/)
- **SEO audit** — bulk-audit all your content for missing or thin metadata
- **Google Search Console integration** — pull real click, impression, and keyword data per page; uses OAuth 2.0 with your own Google Cloud credentials
- **Indexing tools** — submit URLs to Google's Indexing API directly from WordPress
- **Reports** — per-page SEO performance reports combining on-page metadata with GSC data
- **Local SEO context** — set your business name, location, and target keywords once; the AI uses that context on every generation

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An OpenAI API key (GPT-4o-mini is the default model)
- One of: Yoast SEO, The SEO Framework, or Rank Math (for writing generated metadata back to posts)

## Installation

1. Download or clone this repository into wp-content/plugins/ai-seo-assistant/
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **AI SEO → Settings** and enter your OpenAI API key
4. Optionally connect Google Search Console under **AI SEO → Search Console**

### Storing the API key via `wp-config.php`

Instead of saving the key in the database, you can define it as a constant:


define( 'AI_SEO_ASSISTANT_OPENAI_API_KEY', 'sk-...' );
Usage
Generating metadata for a single post
Open any post or page in the editor. The AI SEO Assistant metabox appears in the sidebar. Click Generate to produce a title and description; click Apply to write them to your active SEO plugin.

Bulk audit
Go to AI SEO → Audit to see all posts flagged for missing or short titles/descriptions, and bulk-generate metadata for them.

Google Search Console
Create OAuth 2.0 credentials in Google Cloud Console (Web Application type)
Add your WordPress admin URL as an authorised redirect URI
Enter the Client ID and Client Secret under AI SEO → Search Console → Settings
Click Connect and authorise access
Select your property and click Sync to pull the latest data
Configuration
All settings live under AI SEO → Settings:

Setting	Description
OpenAI API Key	Your sk-... key from platform.openai.com
OpenAI Model	Defaults to gpt-4o-mini; any chat-completion model works
Post Types	Which post types show the generation metabox
Local SEO Context	Business name, location, and keywords injected into every prompt
License
GPL-2.0-or-later — see LICENSE
