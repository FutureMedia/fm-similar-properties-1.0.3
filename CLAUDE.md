# CLAUDE.md

Guidance for working on FM Similar Properties. Updated 2026-11-07 against release **1.1.4**, commit `de536c8`.

## Project and current release

FM Similar Properties is a namespaced WordPress plugin that renders `[similar_properties]` on single `properties` pages. It chooses listings by property type and price, then either map distance or location taxonomy.

- Repository: `/Volumes/T7/Local Sites/WP Plugins/fm-similar-properties`.
- Current release: `releases/fm-similar-properties-1.1.4.zip`.
- Declared requirements: WordPress 5.0+, PHP 7.4+, Advanced Custom Fields (ACF).
- Latest changes: lazy recommendation images, batched attachment loading, and raw numeric/boolean card metadata instead of repeated ACF field-definition lookups.
- The regression suite currently contains **55 checks**.

## Architecture

| File | Responsibility |
| --- | --- |
| `fm-similar-properties.php` | Constants, activation dependency check, component loading, and WordPress hooks |
| `includes/query.php` | Source validation, price filtering, distance calculation, ID-range batches, taxonomy fallback, and live queries rebuilt from cached IDs |
| `includes/cache.php` | Transient payloads, generation invalidation, and the database rebuild lock |
| `includes/render.php` | Shortcode context, card selection, selected-card thumbnail priming, and output buffering |
| `templates/property-card.php` | Escaped location/title output, raw card metadata, formatting, price privacy, and lazy images |
| `includes/admin.php` | Settings registration, capability checks, and nonce-protected cache clearing |
| `includes/debug.php` | Optional logging helpers; its bootstrap include is commented out |
| `assets/css/similar-properties.css` | Recommendation grid styles |
| `tests/security-regression.php` | Standalone regression checks using WordPress stubs |

All runtime PHP uses the `SimilarProperties` namespace and guards direct access with `ABSPATH`.

## Configuration and data contracts

```php
MAX_SIMILAR_PROPERTIES = 6;
DISPLAY_PROPERTIES = 3;
CACHE_DURATION = DAY_IN_SECONDS;
PRICE_TOLERANCE = 10;
```

The query reads the `sp_price_tolerance` option, with a 10% fallback; the constant does not override that option. Admin validation accepts values greater than zero and at most 100. `sp_search_radius` defaults to 50 km and accepts 1–500 km.

- Post type: `properties`.
- Taxonomies: `pr_type` and `location`.
- Map: ACF field `location_map`, stored as an array containing numeric `lat` and `lng`. Out-of-range coordinates and the `(0, 0)` placeholder are rejected.
- Source price: read through ACF, cleaned, and required to be finite and between 1 and 1,000,000,000.
- Card numeric fields: `property_size`, `land_size`, `bedrooms`, `bathrooms`, and `price`.
- Card boolean field: `hidden_price`.
- Theme helpers: `nFormat()` formats numbers; `fm_location_tax()` supplies location labels when available.

Cards read the six numeric/boolean fields directly with `get_post_meta()` from metadata primed by the recommendation query. Custom ACF formatting filters do not apply to card output. Preserve area rounding, unavailable-value fallbacks, and price-on-request behavior. Any future field-type or formatting changes need compatibility checks before retaining this optimization.

## Matching and rendering behavior

1. Require an accessible published source property and ACF availability. Recommendation queries exclude the source and require published, non-password-protected properties.
2. Match `pr_type` and the configured price band.
3. **Source has a valid map pin:** scan candidates in ascending ID batches of 200. Each query adds a prepared `ID > last_id` condition through a temporary `posts_where` filter scoped to that query instance and removed in `finally`. Read only that batch's pins, calculate straight-line distances, and retain the nearest six inside the configured radius. Equal distances sort by post ID. Do not widen the radius or fall back to taxonomy matching on this path.
4. **Source has no valid pin:** match location taxonomy first. If the returned count is below the limit, rerun with property type only. Use `no_found_rows => true` and `post_count`; total match counts are unnecessary. Candidate pins are not required on this path.
5. Render at most three cards. Distance results stay nearest-first; taxonomy results are shuffled when there are more than three candidates. The shortcode accepts a `count` attribute but currently uses the fixed constants rather than the supplied value.
6. Prime thumbnail posts and attachment metadata once with `update_post_thumbnail_cache()` for displayed cards only. Images use `loading="lazy"`, `decoding="async"`, and `fetchpriority="low"`.

The distance scan bounds PHP memory, but total search time still grows with candidate count. It does not truncate the candidate set or use a separate geographic lookup table.

## Caching and concurrency

- Store only `{ids, sorted_by_distance}` payloads, never serialized `WP_Query` objects. Rebuild a live query on a cache hit to recheck publication status and password protection.
- Use the WordPress Transients API once per payload; WordPress chooses persistent object caching or database storage. Do not add a second default-group cache entry.
- Key format: `sp_similar_v2_{generation}_{post_id}`. The generation comes from `sp_cache_generation`, defaulting to `1`.
- Normal results expire after one day. Missing property types and invalid prices produce empty results cached for five minutes. Query failures and lock contention are not cached as empty results.
- Property saves, settings changes, and manual clears replace the generation. A changed listing can affect recommendations on other property pages, so invalidation covers all recommendation results. Old transient entries expire naturally; no storage enumeration is needed.
- Capture the generation before rebuilding and write back to that generation, so an in-flight rebuild cannot repopulate a newly cleared cache.
- The shared `sp_rebuild_lock` uses atomic database insertion and an ownership token. It expires after five minutes and is released in `finally`; an old worker must not release a newer worker's lock.
- Only one uncached rebuild runs at a time across property pages. Other uncached requests temporarily omit the section; cached results remain available.

## Hooks and security boundaries

- Activation: dependency validation and default settings.
- `init`, priority 20: load components and register the shortcode.
- `wp_enqueue_scripts`: enqueue CSS, versioned with file modification time. Currently runs on all frontend pages.
- `admin_menu` / `admin_init`: settings page and Settings API registration.
- `save_post_properties`: generation invalidation.
- `acf/save_post`, priority 20: invalidate again after ACF writes property data; ignore non-property/non-numeric IDs.
- `posts_where`: temporary query-instance filter used for ID-range batches.

Keep the explicit `manage_options` check inside the admin callback and the nonce check for cache clearing. Escape location labels at output, even if the theme helper currently escapes them. Preserve integer normalization of cached IDs and prepared SQL values. Pin deserialization must retain `allowed_classes => false`.

## Verification

Run from the repository root:

```sh
php -d error_reporting=32767 tests/security-regression.php
```

Expected: **55 PASS lines**, the final success message, and no warnings. Coverage includes escaping, admin denial, nearest-first batching, lock ownership/races/cleanup, expiry, generation invalidation, negative caching, taxonomy fallback, database failures, card values, hidden prices, lazy image attributes, and selected-card thumbnail loading.

Lint changed PHP files with `php -l path/to/file.php` and run `git diff --check`. The standalone tests use stubs and do not validate real SQL plans or replace live WordPress checks.

## Local site and measured performance

The active local site is `https://sothebysrealty.local`, with the example page `/properties/symphony-of-colors/`.

- Served WordPress root: `/Users/lefteris/Local Sites/sothebys/app/public`.
- Installed plugin: `/Users/lefteris/Local Sites/sothebys/app/public/wp-content/plugins/fm-similar-properties`.
- The separate `/Volumes/T7/Local Sites/sothebys` tree is not the site root served by this domain.
- Last verified environment: WordPress 7.1.4, PHP 8.4.10, MySQL 8.0.35, Sotheby's Greece theme, no persistent object cache.
- The installed render/template files contain the latest card optimizations, but its plugin header still says **1.1.3** as checked on 2026-11-07. The repository and packaged release say **1.1.4**. Do not assume creating a release ZIP updates the installed copy.

Live verification on 2026-11-07 found 76 published properties, 16 type/price candidates for the example, and three displayed recommendations. Comparing raw card metadata with ACF values across all 76 published properties found no display differences.

Five fresh WordPress processes per scenario, with isolated profiling generations and temporary entries removed afterward, measured the following medians. These include lookup and card rendering, exclude WordPress bootstrap, and are not production load-test results:

| Scenario | Before card optimizations | After card optimizations |
| --- | --- | --- |
| Uncached rendering | 14.4 ms / 35 SQL queries | 12.0 ms / 21 SQL queries |
| Cached rendering | 8.1 ms / 26 SQL queries | 5.5 ms / 10 SQL queries |

Cached-render SQL attribution after optimization: one transient read, five result-hydration queries, two attachment-priming queries, and two location-ancestor queries. ACF field-definition queries during card rendering dropped to zero.

Browser verification showed zero recommendation-image requests at the top of the page; all three images loaded successfully when scrolled into view. Card text, links, prices, and ordering stayed the same. Image payloads are deferred, not recompressed. Earlier unthrottled page measurements were approximately 237 ms median HTML response time, 401 ms LCP in one trace, and CLS 0; these describe the whole local page, not isolated plugin time.

Use the Local site's matching PHP runtime and database socket for future profiling. Keep temporary profiling scripts outside the served web root and remove temporary cache entries. Do not commit credentials, machine-specific socket settings, or raw database dumps.

## Release workflow and known limitations

For a requested release, update the plugin header, README version, and changelog together. Package the bootstrap plus `includes/`, `templates/`, and `assets/` under one top-level `fm-similar-properties/` directory in `releases/fm-similar-properties-X.Y.Z.zip`. Exclude hidden files, Git/editor metadata, tests, documentation, and nested release archives. Verify ZIP integrity and compare extracted runtime bytes against the source before committing. Preserve previous release ZIPs.

`get_template_path()` exposes `similar_properties_template_path`, but rendering currently includes the bundled template directly. Automatic theme overrides advertised in README are not wired into the render path; copying a template into a theme alone has no effect. Do not describe override support as implemented without changing and testing that path.

Debug helpers are not loaded by default, and most logging calls are commented out. The renderer still emits a small console-count script. ACF remains required for source matching even though card values no longer use ACF lookups.
