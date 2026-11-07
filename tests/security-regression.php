<?php
// Isolated regression checks: php tests/security-regression.php (no WordPress install required).
define('ABSPATH', __DIR__);
define('SimilarProperties\MAX_SIMILAR_PROPERTIES', 6);
define('SimilarProperties\CACHE_DURATION', 86400);
$GLOBALS['options'] = [];
$GLOBALS['cache'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['persistent_transients'] = [];
$GLOBALS['expires'] = [];
$GLOBALS['term_calls'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['fields'] = [];
$GLOBALS['types'] = [1];
$GLOBALS['has_pin'] = true;
$GLOBALS['candidates'] = range(1, 405);
$GLOBALS['queries'] = [];
$GLOBALS['pins'] = [];
foreach ($GLOBALS['candidates'] as $id) {
    $GLOBALS['pins'][$id] = serialize(['lat' => 38 + (406 - $id) / 10000, 'lng' => 23]);
}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
class AuditDB {
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $max_batch = 0;
    public $before_insert;
    function prepare($sql, ...$args) {
        if (strpos($sql, ' AND ') === 0) { return sprintf($sql, ...$args); }
        return [$sql, count($args) === 1 && is_array($args[0]) ? $args[0] : $args];
    }
    function get_var($query) { return $GLOBALS['options'][$query[1][0]] ?? null; }
    function query($query) {
        [$sql, $args] = $query;
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            if ($this->before_insert) { ($this->before_insert)(); $this->before_insert = null; }
            if (isset($GLOBALS['options'][$args[0]])) { return 0; }
            $GLOBALS['options'][$args[0]] = $args[1]; return 1;
        }
        if (strpos($sql, 'DELETE') === 0 && ($GLOBALS['options'][$args[0]] ?? null) === $args[1]) {
            unset($GLOBALS['options'][$args[0]]); return 1;
        }
        return 0;
    }
    function get_results($query) {
        if (!empty($GLOBALS['coordinate_failure'])) { $this->last_error = 'coordinate query failed'; return null; }
        $ids = array_values(array_filter(array_slice($query[1], 1), function ($id) { return isset($GLOBALS['pins'][$id]); }));
        $this->max_batch = max($this->max_batch, count($ids));
        return array_map(function ($id) { return (object)['post_id' => $id, 'meta_value' => $GLOBALS['pins'][$id]]; }, $ids);
    }
}
$GLOBALS['wpdb'] = new AuditDB();
class WP_Query {
    public $posts;
    public $args = [];
    public $post_count = 0;
    function __construct($args = []) { if ($args) { $this->query($args); } }
    function query($args) {
        $this->args = $args;
        if (!empty($GLOBALS['query_exception'])) { throw new RuntimeException('database interruption'); }
        $where = apply_filters('posts_where', '', $this);
        $GLOBALS['queries'][] = $args + ['test_where' => $where];
        $GLOBALS['wpdb']->last_error = !empty($GLOBALS['candidate_failure']) ? 'candidate query failed' : '';
        if (($args['fields'] ?? '') === 'ids') {
            preg_match('/ID > (\d+)/', $where, $match);
            $after_id = isset($match[1]) ? (int) $match[1] : 0;
            $ids = array_values(array_filter($GLOBALS['candidates'], function ($id) use ($after_id) { return $id > $after_id; }));
            $this->posts = array_slice($ids, 0, $args['posts_per_page']);
        } else {
            if (isset($args['post__in'])) {
                $ids = $args['post__in'];
            } else {
                $ids = count($args['tax_query']) === 1 ? ($GLOBALS['fallback_matches'] ?? []) : ($GLOBALS['location_matches'] ?? []);
                $ids = array_slice($ids, 0, $args['posts_per_page']);
            }
            $this->posts = array_map(function ($id) { return (object)['ID' => $id]; }, array_values(array_filter($ids)));
        }
        $this->post_count = count($this->posts);
        return $this->posts;
    }
    function set($key, $value) { $this->args[$key] = $value; }
    function get($key) { return $this->args[$key] ?? ''; }
}
function add_filter($tag, $callback, $priority = 10, $accepted = 1) { $GLOBALS['filters'][$tag][] = $callback; }
function remove_filter($tag, $callback, $priority = 10) {
    foreach ($GLOBALS['filters'][$tag] ?? [] as $i => $registered) {
        if ($registered === $callback) { unset($GLOBALS['filters'][$tag][$i]); }
    }
}
function apply_filters($tag, $value, ...$args) {
    foreach ($GLOBALS['filters'][$tag] ?? [] as $callback) { $value = $callback($value, ...$args); }
    return $value;
}
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ++$n; }
function wp_cache_get($key) { return $GLOBALS['cache'][$key] ?? false; }
function wp_cache_set($key, $value) { $GLOBALS['cache'][$key] = $value; }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['cache'][$key]); }
function transient_storage() { return !empty($GLOBALS['persistent_mode']) ? 'persistent_transients' : 'transients'; }
function get_transient($key) {
    $storage = transient_storage();
    if (($GLOBALS['expires'][$key] ?? 0) <= time()) { unset($GLOBALS[$storage][$key]); }
    return $GLOBALS[$storage][$key] ?? false;
}
function set_transient($key, $value, $ttl) {
    $GLOBALS[transient_storage()][$key] = $value;
    $GLOBALS['expires'][$key] = time() + $ttl;
    $GLOBALS['durations'][$key] = $ttl;
    return true;
}
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function is_serialized($value) { return is_string($value) && preg_match('/^[aOsibdN]:?/', $value); }
function get_post_type($id) { return 'properties'; }
function get_post_status($id) { return 'publish'; }
function post_password_required($id) { return false; }
function get_post_meta($id, $key, $single) { return $GLOBALS['has_pin'] ? ['lat' => 38, 'lng' => 23] : ''; }
function wp_get_post_terms($id, $taxonomy, $args) {
    $GLOBALS['term_calls'][] = $taxonomy;
    if (!empty($GLOBALS['throw_terms'])) { throw new RuntimeException('query failure'); }
    if (!empty($GLOBALS['invalidate_during_build'])) {
        $GLOBALS['invalidate_during_build'] = false;
        SimilarProperties\clear_all_caches();
    }
    return $taxonomy === 'pr_type' ? $GLOBALS['types'] : [2];
}
function wp_list_pluck($posts, $key) { return array_map(function ($p) use ($key) { return $p->$key; }, $posts); }
function get_field($key, $id) { return $GLOBALS['fields'][$key] ?? ($key === 'price' ? 100 : ($key === 'hidden_price')); }
function add_action(...$args) {}
function current_user_can($cap) { return false; }
function wp_die($message, $title, $args) { throw new RuntimeException('HTTP ' . $args['response']); }
function fm_location_tax($id) { return ['name' => '<img src=x onerror="alert(1)">']; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_html($value); }
function get_permalink($id) { return 'https://example.test/property'; }
function get_the_post_thumbnail(...$args) { return ''; }
function nFormat($value, ...$args) { return (string)$value; }
function _e($value, ...$args) { echo $value; }
require __DIR__ . '/../includes/query.php';
require __DIR__ . '/../includes/cache.php';
require __DIR__ . '/../includes/admin.php';

$first = SimilarProperties\acquire_rebuild_lock();
check(is_string($first), 'first rebuild acquires lock');
check(SimilarProperties\acquire_rebuild_lock() === false, 'concurrent rebuild is rejected');
SimilarProperties\release_rebuild_lock('wrong owner');
check($GLOBALS['options']['sp_rebuild_lock'] === $first, 'wrong owner cannot release lock');
$GLOBALS['options']['sp_rebuild_lock'] = json_encode(['owner' => 'expired', 'expires' => time() - 1]);
$second = SimilarProperties\acquire_rebuild_lock();
check(is_string($second) && $second !== $first, 'expired lock is recoverable');
SimilarProperties\release_rebuild_lock($first);
check($GLOBALS['options']['sp_rebuild_lock'] === $second, 'old worker cannot release replacement lock');
check(SimilarProperties\get_similar_properties(999) === false && !$GLOBALS['queries'], 'busy lock skips uncached queries');
SimilarProperties\set_cached_properties(999, [2, 3], true);
check(count(SimilarProperties\get_similar_properties(999)->posts) === 2, 'cached results remain available during rebuild');
SimilarProperties\release_rebuild_lock($second);
$GLOBALS['wpdb']->before_insert = function () { $GLOBALS['options']['sp_rebuild_lock'] = 'racing-owner'; };
check(SimilarProperties\acquire_rebuild_lock() === false && $GLOBALS['options']['sp_rebuild_lock'] === 'racing-owner', 'insert race cannot overwrite another worker');
unset($GLOBALS['options']['sp_rebuild_lock']);
$GLOBALS['throw_terms'] = true;
try { SimilarProperties\get_similar_properties(998); } catch (RuntimeException $e) {}
check(!isset($GLOBALS['options']['sp_rebuild_lock']), 'exception releases rebuild lock');
$GLOBALS['throw_terms'] = false;
$GLOBALS['queries'] = [];
$result = SimilarProperties\get_similar_properties(998);
check(wp_list_pluck($result->posts, 'ID') === [405, 404, 403, 402, 401, 400], 'nearest matches include later batches in correct order');
$batches = array_values(array_filter($GLOBALS['queries'], function ($args) { return ($args['fields'] ?? '') === 'ids'; }));
check(count($batches) === 3 && $GLOBALS['wpdb']->max_batch <= 200, 'candidate and coordinate reads are limited to 200 per batch');
check(!isset($GLOBALS['options']['sp_rebuild_lock']), 'successful rebuild releases lock');
check(is_finite(SimilarProperties\distance_km(90, 0, -90, 180)), 'antipodal distance stays finite');
try { SimilarProperties\render_admin_page(); throw new LogicException('authorization failed'); }
catch (RuntimeException $e) { check($e->getMessage() === 'HTTP 403', 'unauthorized settings access returns 403'); }
$similar_id = 1;
$similar = (object)['post_title' => 'Safe title'];
ob_start();
include __DIR__ . '/../templates/property-card.php';
$html = ob_get_clean();
check(strpos($html, '<img src=x') === false && strpos($html, '&lt;img src=x') !== false, 'location HTML is escaped');
// Performance regressions, including transient storage with and without persistent caching.
foreach ([false, true] as $persistent) {
    $GLOBALS['persistent_mode'] = $persistent;
    $mode = $persistent ? 'persistent cache' : 'database transients';
    SimilarProperties\set_cached_properties(1001, [7]);
    $key = SimilarProperties\property_cache_key(1001);
    check(SimilarProperties\get_cached_properties(1001)['ids'] === [7] && $GLOBALS['durations'][$key] === 86400, "$mode: normal results expire in one day");
    $GLOBALS['expires'][$key] = time() - 1;
    check(SimilarProperties\get_cached_properties(1001) === false, "$mode: expired results are a miss");
    SimilarProperties\set_cached_properties(1001, [8]);
    SimilarProperties\clear_all_caches();
    check(SimilarProperties\get_cached_properties(1001) === false, "$mode: generation change invalidates all results");
    SimilarProperties\set_cached_properties(1001, [9]);
    SimilarProperties\clear_property_cache_after_acf_save(1001);
    check(SimilarProperties\get_cached_properties(1001) === false, "$mode: property save invalidates results");
}
check($GLOBALS['cache'] === [], 'no duplicate default-group cache entries are written');
$GLOBALS['persistent_mode'] = false;
$GLOBALS['queries'] = [];
$GLOBALS['term_calls'] = [];
SimilarProperties\get_similar_properties(1002);
check($GLOBALS['term_calls'] === ['pr_type'], 'map search skips location taxonomy lookup');
$batches = array_values(array_filter($GLOBALS['queries'], function ($q) { return ($q['fields'] ?? '') === 'ids'; }));
check(strpos($batches[1]['test_where'], 'ID > 200') !== false && !isset($batches[1]['offset']), 'next batch seeks after last ID without offset');
check(empty($GLOBALS['filters']['posts_where']), 'ID-range filter is removed after query');
$GLOBALS['types'] = [];
SimilarProperties\get_similar_properties(1003);
$key = SimilarProperties\property_cache_key(1003);
$previous_term_call_count = count($GLOBALS['term_calls']);
SimilarProperties\get_similar_properties(1003);
check($GLOBALS['durations'][$key] === 300 && count($GLOBALS['term_calls']) === $previous_term_call_count, 'missing property type is cached for five minutes');
$GLOBALS['types'] = [1];
foreach ([0, -10, 1000000001, INF] as $i => $price) {
    $GLOBALS['fields']['price'] = $price;
    SimilarProperties\get_similar_properties(1100 + $i);
    $key = SimilarProperties\property_cache_key(1100 + $i);
    check(($GLOBALS['durations'][$key] ?? null) === 300, "invalid price case $i is cached briefly");
}
unset($GLOBALS['fields']['price']);
$GLOBALS['has_pin'] = false;
$GLOBALS['location_matches'] = [1, 2];
$GLOBALS['fallback_matches'] = [3, 4, 5, 6, 7, 8];
$GLOBALS['queries'] = [];
$result = SimilarProperties\get_similar_properties(1200);
check(wp_list_pluck($result->posts, 'ID') === [3, 4, 5, 6, 7, 8] && count($GLOBALS['queries']) === 2, 'short location results trigger type-only fallback');
check($GLOBALS['queries'][0]['no_found_rows'] && $GLOBALS['queries'][1]['no_found_rows'], 'both taxonomy queries skip total counts');
$GLOBALS['location_matches'] = [1, 2, 3, 4, 5, 6];
$GLOBALS['queries'] = [];
SimilarProperties\get_similar_properties(1201);
check(count($GLOBALS['queries']) === 1, 'full location results skip fallback without total counts');
$GLOBALS['has_pin'] = true;
$GLOBALS['types'] = new WP_Error();
check(SimilarProperties\get_similar_properties(1300) === false && SimilarProperties\get_cached_properties(1300) === false, 'taxonomy errors are not cached');
$GLOBALS['types'] = [1];
foreach (['candidate_failure', 'coordinate_failure'] as $failure) {
    $GLOBALS[$failure] = true;
    check(SimilarProperties\get_similar_properties(1301) === false && SimilarProperties\get_cached_properties(1301) === false, "$failure is not cached");
    check(empty($GLOBALS['filters']['posts_where']) && !isset($GLOBALS['options']['sp_rebuild_lock']), "$failure releases filter and lock");
    $GLOBALS[$failure] = false;
    $GLOBALS['wpdb']->last_error = '';
}
$GLOBALS['invalidate_during_build'] = true;
SimilarProperties\get_similar_properties(1400);
check(SimilarProperties\get_cached_properties(1400) === false, 'in-flight rebuild cannot populate a newly invalidated generation');
$GLOBALS['candidates'] = [1, 17, 300, 500, 900];
$GLOBALS['queries'] = [];
$result = SimilarProperties\query_candidate_batch(['fields' => 'ids', 'posts_per_page' => 200], 300);
check($result === [500, 900], 'ID-range batching handles gaps in post IDs');
$nested_where = null;
// Run an unrelated query through the same active WHERE filters without recursion.
$scope_filter = function ($where, $query) use (&$nested_where) {
    static $inside = false;
    if (!$inside) {
        $inside = true;
        $nested_where = apply_filters('posts_where', '', new WP_Query());
        $inside = false;
    }
    return $where;
};
add_filter('posts_where', $scope_filter, 10, 2);
SimilarProperties\query_candidate_batch(['fields' => 'ids', 'posts_per_page' => 200], 300);
remove_filter('posts_where', $scope_filter, 10);
check($nested_where === '', 'ID filter leaves unrelated nested queries unchanged');
$GLOBALS['query_exception'] = true;
try { SimilarProperties\get_similar_properties(1500); } catch (RuntimeException $e) {}
check(empty($GLOBALS['filters']['posts_where']) && !isset($GLOBALS['options']['sp_rebuild_lock']), 'query exception releases ID filter and rebuild lock');
$GLOBALS['query_exception'] = false;
$GLOBALS['types'] = [];
SimilarProperties\get_similar_properties(1501);
$key = SimilarProperties\property_cache_key(1501);
$GLOBALS['types'] = [1];
$GLOBALS['expires'][$key] = time() - 1;
$GLOBALS['term_calls'] = [];
SimilarProperties\get_similar_properties(1501);
check($GLOBALS['term_calls'] === ['pr_type'], 'expired negative cache rebuilds with corrected property data');
$GLOBALS['types'] = [];
SimilarProperties\get_similar_properties(1502);
$GLOBALS['types'] = [1];
SimilarProperties\clear_property_cache_after_acf_save(1502);
$GLOBALS['term_calls'] = [];
SimilarProperties\get_similar_properties(1502);
check($GLOBALS['term_calls'] === ['pr_type'], 'saving corrected property bypasses negative cache immediately');
echo "All security and performance regression checks passed.\n";
