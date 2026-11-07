# FM Similar Properties

A WordPress plugin that intelligently displays similar real estate properties based on property type, price range, and location. Built with performance in mind, featuring advanced caching and fallback strategies.

## Features

- **Smart Similarity Matching**: Finds similar properties based on type, location, and price range
- **Intelligent Price Filtering**: Uses percentage-based price tolerance (configurable)
- **Expiring Caching**: WordPress transients with automatic persistent-cache support
- **Fallback Strategy**: Automatically expands search criteria if insufficient results found
- **Admin Dashboard**: Settings page with cache management tools
- **Template Override**: Theme-friendly template system
- **Debug Support**: Conditional logging for development

## Requirements

- WordPress 5.0+
- PHP 7.4+
- **Advanced Custom Fields (ACF)** plugin (required)
- Custom post type: `properties`
- Required taxonomies: `pr_type` (property type), `location`
- Theme functions: `fm_location_tax()` and `nFormat()`

## Installation

1. Upload the plugin files to `/wp-content/plugins/fm-similar-properties/`
2. Install and activate the Advanced Custom Fields (ACF) plugin
3. Activate the FM Similar Properties plugin
4. Configure settings in **Settings → Similar Properties**

## Usage

### Shortcode

Display similar properties using the shortcode:

```
[similar_properties]
```

The shortcode automatically detects the current property and displays similar ones.

### Template Override

You can override the property card template by copying `templates/property-card.php` to your theme:

```
your-theme/fm-similar-properties/property-card.php
```

## Configuration

### Constants

The plugin uses several configurable constants:

```php
MAX_SIMILAR_PROPERTIES = 6    // Maximum properties to fetch
DISPLAY_PROPERTIES = 3        // Maximum properties to display  
CACHE_DURATION = DAY_IN_SECONDS
PRICE_TOLERANCE = 10          // Default price tolerance percentage
```

### Admin Settings

Access settings via **Settings → Similar Properties**:

- **Price Tolerance**: Adjust the percentage range for price matching
- **Cache Management**: Clear cached results
- **Debug Information**: View system status and diagnostics

## How It Works

### Similarity Algorithm

1. **Validation**: Ensures source property is published and accessible
2. **Type Matching**: Filters by property type taxonomy (`pr_type`)
3. **Location Matching**: Filters by location taxonomy
4. **Price Filtering**: Applies configurable percentage-based price range
5. **Fallback**: Removes location constraint if insufficient results
6. **Randomization**: Shuffles results when more properties found than needed

### Caching Strategy

Recommendation IDs are stored once using the WordPress Transients API. WordPress uses its persistent object cache automatically when available, or database transients otherwise. Normal results expire after one day; missing property types and invalid prices are cached for five minutes. Query errors and lock contention are never stored as empty recommendations.

Property saves, settings changes, and the Clear Cache action switch the cache generation. This invalidates results across all property pages without enumerating database or persistent-cache entries. Old entries expire naturally. An in-flight rebuild writes to the generation it started with.

Distance searches read candidates and their map pins in batches of 200, retaining only the closest matches across all batches. Each batch starts after the previous batch's highest post ID, avoiding increasing database offsets. This bounds PHP memory without truncating the candidate set. Total search time still depends on inventory size.

Properties with map pins skip the location taxonomy lookup. The taxonomy fallback uses the returned result count rather than counting every matching row in the database.

A database-backed lock allows one uncached search to rebuild at a time. Cached results remain available; other uncached requests temporarily omit the recommendations section. Locks expire after five minutes if a worker stops before releasing them.

### Card rendering

Only the displayed cards have their thumbnail posts and attachment metadata loaded in a batch. Recommendation images use native lazy loading, asynchronous decoding, and low fetch priority, so cards below the viewport do not compete with the page's main content.

The card reads the numeric fields `property_size`, `land_size`, `bedrooms`, `bathrooms`, and `price`, plus the boolean `hidden_price`, from WordPress post metadata already primed by the recommendation query. This avoids ACF field-definition lookups during card rendering. These fields must remain numeric/boolean; custom ACF formatting filters are not applied to card values. Area rounding, number formatting, and price-on-request behavior remain in the template.

### Template System

Uses WordPress template hierarchy with override support:

```
Plugin Template: templates/property-card.php
Theme Override: your-theme/fm-similar-properties/property-card.php
```

## Development

### Regression checks

Run `php tests/security-regression.php` for isolated checks covering location escaping, admin access, nearest-match selection, ID-range batching, transient expiry, generation invalidation, negative caching, taxonomy fallback, query failures, rebuild lock ownership and cleanup, card values and price privacy, lazy image attributes, and selected-card thumbnail batching. These checks use WordPress stubs; they do not replace testing against a running WordPress installation.

### File Structure

```
fm-similar-properties/
├── fm-similar-properties.php    # Main plugin file
├── includes/
│   ├── query.php               # Property search logic
│   ├── cache.php               # Caching system
│   ├── render.php              # Frontend display
│   ├── admin.php               # Admin interface
│   └── debug.php               # Debug utilities
├── templates/
│   └── property-card.php       # Property card template
├── assets/
│   └── css/
│       └── similar-properties.css
└── README.md
```

### Hooks & Filters

**Actions:**
- `init` - Component loading
- `wp_enqueue_scripts` - Asset loading
- `admin_menu` - Settings page
- `save_post_properties` - Cache clearing

**Filters:**
- `similar_properties_template_path` - Template override

### Code Standards

- All code namespaced under `SimilarProperties`
- Input validation and sanitization throughout
- WordPress coding standards compliance
- Comprehensive error handling

## Troubleshooting

### Common Issues

**Plugin won't activate:**
- Ensure ACF plugin is installed and activated
- Check PHP error logs for dependency issues

**No similar properties displayed:**
- Verify properties have required ACF fields
- Check if properties exist with matching criteria
- Review price tolerance settings
- Clear cache from admin settings

**Performance issues:**
- Enable object caching (Redis/Memcached recommended)
- Adjust `MAX_SIMILAR_PROPERTIES` constant
- Check server resources

### Debug Mode

Enable debug logging by uncommenting in `fm-similar-properties.php`:

```php
require_once __DIR__ . '/includes/debug.php';
```

## Changelog

### Version 1.1.4
- Lazy-load recommendation images with asynchronous decoding and low fetch priority.
- Batch thumbnail posts and attachment metadata for displayed cards only.
- Read numeric and boolean card values from primed post metadata instead of repeated ACF field-definition lookups.
- Preserve card formatting, hidden prices, and nearest-first ordering.
- Expand security and performance regression coverage to 55 checks.

### Version 1.1.3
- Store recommendation results once using expiring WordPress transients.
- Invalidate caches by generation on saves, settings changes, and manual clears.
- Process distance searches using ID ranges instead of increasing offsets.
- Skip total-result counts in taxonomy searches and defer unused location lookups.
- Cache incomplete property data for five minutes while leaving query failures uncached.
- Expand security and performance regression coverage to 46 checks.

### Version 1.1.2
- Escape property location names in recommendation cards.
- Enforce administrator permissions inside the settings page callback.
- Process distance matches in batches of 200 while preserving nearest-first results.
- Serialize uncached searches with an expiring database lock to reduce concurrent rebuild load.
- Add isolated security regression checks.

### Version 1.0.3
- Improved price tolerance algorithm
- Enhanced caching performance
- Added admin dashboard improvements
- Bug fixes and stability improvements

## Support

For support and feature requests, please contact Future Media:
- Website: https://futuremedia.gr

## License

This plugin is proprietary software developed by Future Media.

---

**Author**: Future Media  
**Version**: 1.1.4
**Tested up to**: WordPress 6.4