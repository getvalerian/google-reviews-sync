=== Google Reviews Sync ===
Contributors: getvalerian
Tags: google reviews, google business profile, reviews, elementor, acf
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync Google Business Profile reviews into a Custom Post Type with ACF fields. Build your display entirely in Elementor — no widget styling constraints.

== Description ==

**Google Reviews Sync** pulls your Google Business Profile reviews directly into WordPress as a Custom Post Type (CPT), with each review stored as a post with ACF fields. Build your display entirely in Elementor using the Loop Builder — full design control, no third-party widget styling constraints, no SaaS subscription for display-only use cases.

= Why this plugin? =

Most Google Reviews plugins lock you into their widget styles, charge a recurring SaaS fee, or limit how many reviews you can display. This plugin pulls all your reviews into WordPress so you can build exactly the layout you want using Elementor's Loop Builder and your own design system.

= Key features =

* **Full review sync** — pulls all reviews via the Google Business Profile API (not the 5-review-limited Places API)
* **OAuth 2.0** — connects securely to your Google account; tokens are encrypted at rest
* **CPT + ACF** — every review is a post with structured fields, available as Elementor dynamic tags
* **Elementor query presets** — 10 named query presets for Loop Grid / Posts widgets (all, featured, 5-star, 4+, with text, and more)
* **Rich snippet schema** — outputs LocalBusiness + AggregateRating JSON-LD for Google Search star ratings
* **Bundled shortcodes** — social proof bar, avatar stack, star rating, review count — all in one shortcode, no functions.php needed
* **Daily auto-sync** — WP-Cron keeps reviews fresh automatically

= Requirements =

* ACF (Free or Pro) — field group is registered in code, no JSON import needed
* Elementor Pro — for Loop Builder / Posts widget display
* A Google Cloud project with the Google Business Profile APIs enabled and approved

= Elementor query presets =

Use these as the Query ID in Elementor's Loop Grid or Posts widget (Content → Query → Custom Query):

* `google_reviews_all` — all reviews, highest rated first
* `google_reviews_featured` — reviews with the Featured toggle enabled
* `google_reviews_recent` — all reviews, newest first
* `google_reviews_5star` — 5-star only
* `google_reviews_4plus` — 4 stars and above
* `google_reviews_3plus` — 3 stars and above
* `google_reviews_featured_5star` — featured AND 5-star
* `google_reviews_with_text` — reviews with written text only
* `google_reviews_4plus_with_text` — 4+ stars AND written text
* `google_reviews_featured_with_text` — featured AND written text

= Bundled shortcodes =

* `[vgr_social_proof_bar]` — full proof bar: avatars + stars + average + count
* `[vgr_avatar_stack count="5"]` — stacked circular reviewer avatars
* `[vgr_star_rating stars="5"]` — static star row
* `[vgr_aggregate_rating]` — live computed average (e.g. 4.8)
* `[vgr_review_count]` — live review count string
* `[vgr_loop_stars rating="5"]` — stars for inside Loop Builder templates
* `[vgr_schema]` — AggregateRating JSON-LD output

== Installation ==

1. Upload the `valerian-google-reviews` folder to `/wp-content/plugins/`
2. Activate the plugin from WP Admin → Plugins
3. Go to **Google Reviews → Settings** and follow the 5-step setup

= Google Cloud setup (required) =

1. Create a project at [console.cloud.google.com](https://console.cloud.google.com)
2. Enable these three APIs:
   - My Business Account Management API
   - My Business Business Information API
   - Google My Business API (this handles reviews — do NOT look for a separate "My Business Reviews API", it doesn't exist as a standalone)
3. Create OAuth 2.0 credentials (Web application type) and add the redirect URI shown in the plugin settings
4. **Submit an access request** at [developers.google.com/my-business/content/prereqs](https://developers.google.com/my-business/content/prereqs) — Google requires manual approval for GBP API access. This takes 7–10 business days. You will receive an email when approved.

== Frequently Asked Questions ==

= Why am I getting "Quota exceeded" in Step 3? =

This is expected before your GBP API access request is approved. Google sets new projects to 0 requests per minute by default. Once your access is approved (email notification, 7–10 business days), the quota is raised and the plugin will work immediately.

= How many reviews does it sync? =

All of them. The plugin paginates through reviews 50 at a time using the Google Business Profile API. There is no artificial cap.

= Do I need ACF Pro? =

No. ACF Free works fine. The field group is registered in code so no JSON import is needed.

= Do I need Elementor Pro? =

You need Elementor Pro for the Loop Builder and Posts widget. The shortcodes work with free Elementor or any page builder.

= Can I use this without Elementor? =

Yes. The reviews are stored as a standard WordPress CPT. You can query them with WP_Query in any theme or template. The shortcodes also work independently of any page builder.

= Are single review URLs created? =

No. The CPT has `public: false`, so WordPress does not generate single post URLs for reviews. Nothing to redirect.

= What data is deleted when I uninstall the plugin? =

All plugin settings and OAuth tokens are deleted. Review posts (the imported CPT data) are preserved — they're content, not configuration.

== Screenshots ==

1. Plugin settings page — 5-step OAuth + sync setup
2. Admin review list — all imported reviews with rating, date, and featured columns
3. Elementor Loop Grid using the `google_reviews_4plus_with_text` query preset
4. Social proof bar shortcode in a hero section

== Changelog ==

= 2.6.0 =
* Added `google_reviews_with_text`, `google_reviews_4plus_with_text`, `google_reviews_featured_with_text` query presets to exclude star-only reviews with no text

= 2.5.0 =
* Fixed pagination: removed `posts_per_page: -1` from all query presets so Elementor's Loop Grid controls count and pagination

= 2.4.0 =
* Fixed HTTP 404 on reviews endpoint — location name now always stores as full `accounts/*/locations/*` path
* Self-healing: corrects incomplete location paths stored by earlier versions on settings page load
* Sync debug: settings page now shows the exact reviews URL being called

= 2.3.0 =
* Added 6 bundled shortcodes — no functions.php additions needed
* Bundled CSS output automatically via wp_head

= 2.2.0 =
* Token encryption at rest using sodium_crypto_secretbox
* Key derived from wp-config.php salts + DB-stored salt — database dump alone insufficient to decrypt

= 2.1.0 =
* Schema output scope: sitewide, specific page IDs, or disabled
* Added google_reviews_5star, google_reviews_4plus, google_reviews_3plus, google_reviews_featured_5star presets

= 2.0.0 =
* Replaced Places API (5-review limit) with Google Business Profile API (all reviews, paginated)
* OAuth 2.0 flow with in-plugin account + location picker
* AggregateRating JSON-LD schema
* [vgr_schema] shortcode

= 1.1.0 =
* Initial release with Places API, CPT, ACF field group, daily WP-Cron sync

== Upgrade Notice ==

= 2.0.0 =
Requires reconnecting your Google account after upgrade — the API changed from Places to Business Profile, and OAuth credentials need to be set up fresh.
