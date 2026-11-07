<?php
// Isolated regression checks: php tests/security-regression.php (no WordPress install required).
define('ABSPATH', __DIR__);
define('SimilarProperties\MAX_SIMILAR_PROPERTIES', 6);
define('SimilarProperties\CACHE_DURATION', 86400);
$GLOBALS['options'] = [];
$GLOBALS['cache'] = [];
$GLOBALS['transients'] = [];
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
    public $max_batch = 0;
    public $before_insert;
    function prepare($sql, ...$args) { return [$sql, count($args) === 1 && is_array($args[0]) ? $args[0] : $args]; }
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
        $ids = array_slice($query[1], 1);
        $this->max_batch = max($this->max_batch, count($ids));
        return array_map(function ($id) { return (object)['post_id' => $id, 'meta_value' => $GLOBALS['pins'][$id]]; }, $ids);
    }
}
$GLOBALS['wpdb'] = new AuditDB();
class WP_Query {
    public $posts;
    public $args;
    function __construct($args) {
        $this->args = $args;
        $GLOBALS['queries'][] = $args;
        if (($args['fields'] ?? '') === 'ids') {
            $this->posts = array_slice($GLOBALS['candidates'], $args['offset'], $args['posts_per_page']);
        } else {
            $this->posts = array_map(function ($id) { return (object)['ID' => $id]; }, array_filter($args['post__in']));
        }
    }
    function set($key, $value) { $this->args[$key] = $value; }
}
function wp_json_encode($v) { return json_encode($v); }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ++$n; }
function wp_cache_get($key) { return $GLOBALS['cache'][$key] ?? false; }
function wp_cache_set($key, $value) { $GLOBALS['cache'][$key] = $value; }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['cache'][$key]); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function is_serialized($value) { return is_string($value) && preg_match('/^[aOsibdN]:?/', $value); }
function get_post_type($id) { return 'properties'; }
function get_post_status($id) { return 'publish'; }
function post_password_required($id) { return false; }
function get_post_meta($id, $key, $single) { return ['lat' => 38, 'lng' => 23]; }
function wp_get_post_terms(...$args) { if (!empty($GLOBALS['throw_terms'])) { throw new RuntimeException('query failure'); } return [1]; }
function wp_list_pluck($posts, $key) { return array_map(function ($p) use ($key) { return $p->$key; }, $posts); }
function get_field($key, $id) { return $key === 'price' ? 100 : ($key === 'hidden_price'); }
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
echo "All security regression checks passed.\n";
