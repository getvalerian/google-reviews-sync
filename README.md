# Google Reviews Sync

A WordPress plugin by [Valerian](https://getvalerian.com) that syncs Google Business Profile reviews into a Custom Post Type with ACF fields. Build your display with full design control using any page builder — no widget styling constraints, no SaaS subscription for display-only use cases.

> **Originally built for Elementor**, but compatible with any page builder that supports ACF dynamic fields — including Bricks Builder, Divi, Beaver Builder, Oxygen, and GeneratePress. Since reviews are stored as a standard WordPress CPT with ACF fields, any tool that can query posts and display custom fields can display them.

Built by [Valerian](https://getvalerian.com) — a WordPress-focused web agency based in Columbus, Ohio.

---

## Why this plugin

Most Google Reviews plugins lock you into their widget styles, charge a recurring SaaS fee, or cap how many reviews you can display. This plugin pulls **all** your reviews into WordPress as structured data so you build exactly the layout you want using your own design system.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- ACF Free or Pro
- A page builder that supports ACF dynamic fields (Elementor Pro recommended; Bricks, Divi, Beaver Builder, and others also work)
- Google Cloud project with GBP APIs enabled and access approved

---

## Installation

Download the latest release zip from the [Releases](../../releases) page and install via **WP Admin → Plugins → Add New → Upload Plugin**.

---

## Setup

### Step 1 — Create OAuth credentials in Google Cloud

1. Go to [console.cloud.google.com/apis/credentials](https://console.cloud.google.com/apis/credentials)
2. Create Credentials → **OAuth 2.0 Client ID**
3. Application type: **Web application**
4. Add the redirect URI shown in the plugin's Settings page as an **Authorized redirect URI**
5. Copy the **Client ID** and **Client Secret**

### Step 2 — Enable the required APIs

In Google Cloud → Library, enable all four of these (they are separate APIs):

| API | Purpose |
|---|---|
| My Business Account Management API | Lists your GBP accounts |
| My Business Business Information API | Lists your locations |
| My Business Reviews API | Required for GBP access approval |
| **Google My Business API** | The actual reviews endpoint — legacy but still required |

> **⚠️ GBP API access requires manual approval from Google.** Submit the request form at [developers.google.com/my-business/content/prereqs](https://developers.google.com/my-business/content/prereqs) as soon as you create the Cloud project — approval takes **7–10 business days** and Google will email the Cloud project owner when approved. Do not wait until the rest of the setup is done to submit this. Complete Steps 3–5 while you wait.
>
> **How to read the Step 3 error in the plugin settings:**
> - `Quota exceeded for quota metric 'Requests'...` → ✅ Everything is configured correctly. New projects start with 0 QPM until approval. Just wait for the email.
> - `No accounts found` → ⚠️ The OAuth-connected Google account may not have Manager or Owner access to the Business Profile, or the Account Management API isn't enabled.
> - `403 Forbidden` / `401 Unauthorized` → OAuth credential issue. Double-check Client ID, Client Secret, and redirect URI match exactly what's in Google Cloud Console.

### Step 3 — Enter credentials in plugin settings

WP Admin → Google Reviews → Settings → Step 1. Enter Client ID and Client Secret, save.

### Step 4 — Connect Google account

Click **Connect Google Account**. Sign in with the account that has access to the client's Business Profile. You'll be redirected back to settings automatically.

### Step 5 — Select business location

After OAuth, the plugin lists all GBP accounts and locations your Google account can access. Select the right account, then the right location and save.

### Step 6 — Sync

Click **Sync Reviews Now**. The plugin paginates through all reviews (50 at a time, up to ~1,000). Set the minimum star rating to import (default: 4+). After the first sync, a daily WP-Cron job handles updates automatically.

---

## ACF fields

All fields are available as dynamic tags in any ACF-compatible page builder.

| Field name | Type | Notes |
|---|---|---|
| `reviewer_name` | Text | Reviewer's Google display name |
| `reviewer_photo` | URL | Small circular profile avatar from Google |
| `rating` | Number (1–5) | Star rating as an integer |
| `review_text` | Textarea | Full review text |
| `review_date` | Date | Returns "January 1, 2025" by default |
| `featured_review` | True/False | Manually pin reviews to surface first |
| `google_review_id` | Text | Internal dedup key — do not edit |

> **Note on review photos:** The GBP Reviews API does not return customer-attached in-review photos. The `reviewer_photo` field is the reviewer's small circular profile avatar only.

> **Featuring reviews:** Toggle the Featured switch on any review entry in WP Admin → Google Reviews. Featured reviews show in the `google_reviews_featured` and `google_reviews_featured_5star` query presets.

---

## Elementor query presets

The plugin registers named `WP_Query` presets for Elementor's Loop Grid and Posts widget. Go to the widget → Content → Query → Source: **Custom Query**, then enter the Query ID.

| Query ID | Returns | Use case |
|---|---|---|
| `google_reviews_all` | All reviews, highest rated first | Full reviews wall |
| `google_reviews_featured` | Featured toggle only | Curated homepage carousel |
| `google_reviews_recent` | All reviews, newest first | Latest reviews feed |
| `google_reviews_5star` | 5-star only, newest first | Hero social proof section |
| `google_reviews_4plus` | 4+ stars, highest rated first | Wall with quality filter |
| `google_reviews_3plus` | 3+ stars, highest rated first | Broad display |
| `google_reviews_featured_5star` | Featured AND 5-star | Tight handpicked carousel |
| `google_reviews_with_text` | Has written text, highest rated first | Excludes star-only reviews |
| `google_reviews_4plus_with_text` | 4+ stars AND written text | Most common wall use case |
| `google_reviews_featured_with_text` | Featured AND written text | Carousels where every card needs a quote |

> **Pagination:** Query presets intentionally do not set `posts_per_page`. Elementor's Loop Grid widget fully controls the count and pagination. Set Posts Per Page in the widget's Layout tab and configure Pagination in the Pagination tab.

> **Adding custom presets:** Add a new action at the bottom of the plugin file for any filter combination you need. The Query ID becomes whatever you name the action hook.

---

## Shortcodes

All shortcodes are bundled in the plugin — no `functions.php` additions needed. CSS is also output automatically via `wp_head`.

### `[vgr_social_proof_bar]` — Full proof bar (recommended for hero sections)

Outputs the complete social proof element: stacked avatars + stars + average rating + review count in one shortcode. Drop into an Elementor Shortcode widget.

```
[vgr_social_proof_bar count="5" stars="5" label="from %d Google reviews" show_avg="yes" query="featured"]
```

| Attribute | Default | Notes |
|---|---|---|
| `count` | 5 | Number of avatars to show |
| `stars` | 5 | Filled stars to display |
| `label` | `from %d Google reviews` | %d replaced with live count |
| `show_avg` | `yes` | Show computed average (e.g. 4.8) next to stars |
| `query` | `featured` | Avatar source: `all`, `featured`, or `5star` |

### Other shortcodes

| Shortcode | Description |
|---|---|
| `[vgr_avatar_stack count="5" query="featured"]` | Stacked circular reviewer avatars only |
| `[vgr_star_rating stars="5"]` | Static filled star row |
| `[vgr_aggregate_rating]` | Live computed average as a number (e.g. 4.8) |
| `[vgr_review_count label="from %d Google reviews"]` | Live review count string |
| `[vgr_loop_stars rating="5"]` | Star display for inside Loop Builder templates |
| `[vgr_schema]` | AggregateRating JSON-LD schema output |

---

## Building common layouts

### Layout 1 — Star highlight bar with avatars

Used in hero sections. Shows stacked reviewer avatars, star rating, and review count.

**Simplest approach — one shortcode widget:**
```
[vgr_social_proof_bar count="5" stars="5" label="from %d Google reviews"]
```

Style via `.vgr-social-proof-bar` in your page builder's custom CSS panel.

**Manual build — separate widgets in a Flexbox container:**

```
[vgr_avatar_stack count="5" query="featured"]   → Shortcode widget
[vgr_star_rating stars="5"]                      → Shortcode widget
[vgr_aggregate_rating]                           → Shortcode widget
[vgr_review_count label="from %d Google reviews"] → Shortcode widget
```

Arrange in a Flexbox row, align items center, gap ~12px. Star + count can be nested in a column if you want them stacked vertically.

### Layout 2 — Masonry review grid (the wall)

Built with Elementor's Loop Builder. Compatible with Bricks Builder's Query Loop and similar tools in other builders.

**Step 1 — Create a Loop Item template**

In Elementor: Templates → Add New → Loop Item. Build the card:

```
[ Flexbox Column — card ]
  ├── [ Image widget ] → Dynamic: ACF → reviewer_photo (Circle, 48px)
  ├── [ Flexbox Row ]
  │     ├── [ Text ] → Dynamic: ACF → reviewer_name (bold)
  │     └── [ Shortcode ] → [vgr_loop_stars rating="5"] (set rating to Dynamic → ACF → rating)
  ├── [ Text ] → Dynamic: ACF → review_text
  └── [ Text ] → Dynamic: ACF → review_date (small, muted)
```

Card styling: white background, border-radius ~12px, `box-shadow: 0 2px 12px rgba(0,0,0,0.08)`, 24px padding.

**Step 2 — Add a Loop Grid widget**

- Template: your Review Card loop item
- Query → Source: Custom Query → Query ID: `google_reviews_4plus_with_text`
- Layout: 3 columns / Masonry on
- Pagination: Load More or Infinite Scroll

The masonry layout handles variable-height cards automatically — longer reviews create the staggered look without any extra work.

---

## Rich snippet SEO

The plugin outputs `LocalBusiness` + `AggregateRating` JSON-LD schema, enabling star ratings in Google Search results.

Configure in Settings → Step 5:

| Setting | Notes |
|---|---|
| Business Name | Defaults to site name |
| Business Type | Choose from Schema.org types — use `CafeOrCoffeeShop`, `Restaurant`, `LocalBusiness`, etc. |
| Business URL | The business website |
| Output scope | Sitewide (recommended), specific page IDs (comma-separated), or disabled |

The aggregate rating and review count are computed dynamically from stored reviews — always accurate as new reviews sync in.

Use `[vgr_schema]` as a shortcode if you prefer to control output manually per page.

---

## Security

- **Token encryption** — OAuth tokens encrypted at rest with `sodium_crypto_secretbox`. Key derived from `wp-config.php` salts + a DB-stored salt — database dump alone is not enough to decrypt.
- **No SQL injection** — all DB writes use `wp_insert_post`, `update_field`, `update_post_meta` (prepared statements internally).
- **No XSS** — all output uses `esc_html`, `esc_attr`, `esc_url_raw`. Review content sanitized on import.
- **CSRF protected** — all admin form submissions use `check_admin_referer`. OAuth uses a state parameter validated via WP transient.
- **No public URLs** — CPT has `public: false`. No single post pages are generated.
- **Token revocation** — if a token leaks, revoke at [myaccount.google.com/permissions](https://myaccount.google.com/permissions). The Disconnect button in settings clears all stored tokens.

---

## Updates

This plugin uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). When a new release is published here, the standard WordPress "Update Available" notification appears in WP Admin → Plugins.

---

## License

GPL v2 or later. See [LICENSE](LICENSE).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
