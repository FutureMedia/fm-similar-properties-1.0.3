<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function debug_log($message, $data = null, $type = '🔍') {
    if (!WP_DEBUG) return;

    $output = "$type [Similar Properties] $message";
    if ($data !== null) {
        $output .= ": " . print_r($data, true);
    }
    error_log($output);
}

function log_sql_query($sql) {
    debug_log('FULL SQL QUERY', $sql, '📊');
    return $sql;
}

function log_meta_sql($sql) {
    debug_log('META SQL', $sql, '📊');
    return $sql;
}
