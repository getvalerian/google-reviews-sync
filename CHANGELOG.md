# Changelog

All notable changes to Google Reviews Sync are documented here.

## [2.6.0] — 2026-04-27

### Added
- `google_reviews_with_text` query preset — excludes star-only reviews with no written text
- `google_reviews_4plus_with_text` — 4+ stars AND has written text (most common wall use case)
- `google_reviews_featured_with_text` — featured reviews that have written text

## [2.5.0] — 2026-04-20

### Fixed
- Removed `posts_per_page: -1` from all Elementor query presets — Elementor's Loop Grid widget now fully controls post count and pagination

## [2.4.0] — 2026-04-17

### Fixed
- HTTP 404 on reviews sync — GBP Business Information API returns relative location paths (`locations/ID`) but the Reviews API requires the full resource path (`accounts/ID/locations/ID`). Location name is now always stored as the full path
- Self-healing: settings page detects and corrects incomplete location paths stored by earlier versions
- Sync error messages now include the exact URL called, for easier debugging

## [2.3.0] — 2026-04-10

### Added
- 6 bundled shortcodes — no functions.php needed:
  - `[vgr_social_proof_bar]` — full proof bar in one shortcode
  - `[vgr_avatar_stack]` — stacked reviewer avatars
  - `[vgr_star_rating]` — static star row
  - `[vgr_aggregate_rating]` — live average rating
  - `[vgr_review_count]` — live review count
  - `[vgr_loop_stars]` — stars for Loop Builder templates
- Bundled CSS output automatically via `wp_head` — no manual stylesheet needed

## [2.2.0] — 2026-04-08

### Added
- Token encryption at rest using `sodium_crypto_secretbox`
- Encryption key derived from `wp-config.php` salts + DB-stored salt — database dump alone is insufficient to decrypt tokens

## [2.1.0] — 2026-04-07

### Added
- Schema output scope setting: sitewide, specific page IDs (comma-separated), or disabled
- `google_reviews_5star`, `google_reviews_4plus`, `google_reviews_3plus`, `google_reviews_featured_5star` Elementor query presets

## [2.0.0] — 2026-04-05

### Changed
- **Breaking:** Replaced Google Places API (5-review limit) with Google Business Profile API (all reviews, paginated). Requires OAuth setup — see readme.

### Added
- OAuth 2.0 flow with in-plugin Google account connection and location picker
- Full review pagination (50 per request, up to ~1,000 reviews)
- AggregateRating JSON-LD schema output with `[vgr_schema]` shortcode
- Schema scope settings (sitewide / specific pages / disabled)

## [1.1.0] — 2026-03-20

### Added
- Initial release
- Google Places API sync (up to 5 reviews)
- Custom Post Type `google_review` with ACF field group registered in code
- Daily WP-Cron auto-sync
- Elementor query presets: `google_reviews_all`, `google_reviews_featured`, `google_reviews_recent`
- Admin columns: rating, date, featured flag, review excerpt
