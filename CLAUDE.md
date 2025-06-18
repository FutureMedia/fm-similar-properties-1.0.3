# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**FM Similar Properties** is a WordPress plugin that displays similar real estate properties based on property type, price range, and location. The plugin uses Advanced Custom Fields (ACF) for property data and implements intelligent caching for performance.

## Architecture

The plugin follows a modular architecture with clear separation of concerns:

- **Main Plugin File**: `fm-similar-properties.php` - Plugin bootstrap, dependency checking, and asset loading
- **Query Logic**: `includes/query.php` - Property search with fallback strategies and price filtering
- **Caching System**: `includes/cache.php` - Multi-layer caching (object cache + transients)
- **Frontend Display**: `includes/render.php` - Shortcode handling and template rendering
- **Admin Interface**: `includes/admin.php` - Settings page and cache management
- **Debug Utilities**: `includes/debug.php` - Conditional logging for development
- **Template**: `templates/property-card.php` - Individual property card display

## Key Constants & Configuration

```php
MAX_SIMILAR_PROPERTIES = 6    // Maximum properties to fetch
DISPLAY_PROPERTIES = 3        // Maximum properties to display
CACHE_DURATION = DAY_IN_SECONDS
PRICE_TOLERANCE = 20          // Default price tolerance percentage (20%)
```

## Dependencies

- **Required**: Advanced Custom Fields (ACF) plugin
- **Post Type**: `properties` with taxonomies `pr_type` and `location`
- **Theme Functions**: Expects `fm_location_tax()` and `nFormat()` functions

## Caching Strategy

The plugin implements a two-tier caching system:
1. **Object Cache** (fastest, memory-based)
2. **Transients** (persistent across requests)

Cache keys follow pattern: `sp_similar_{post_id}`

## WordPress Hooks Used

- `init` - Component loading
- `wp_enqueue_scripts` - CSS loading with file modification versioning
- `admin_menu` - Settings page registration
- `save_post_properties` - Automatic cache clearing
- `similar_properties_template_path` - Template override filter

## Core Query Logic

The similarity algorithm in `includes/query.php`:
1. Validates source property (published, no password protection)
2. Filters by property type and location taxonomies
3. Applies price range filtering with configurable percentage-based tolerance
4. **Fallback**: Removes location filter if insufficient results found
5. Randomizes results when more properties found than needed

## Development Notes

- All code is namespaced under `SimilarProperties`
- Debug logging available but disabled in production
- Input validation and sanitization implemented throughout
- Template override system supports theme customization
- Price handling includes currency symbol cleaning and billion-dollar safety limits