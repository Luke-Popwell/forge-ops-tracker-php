<?php

declare(strict_types=1);

/**
 * Appends every request to a JSON-lines file (unlike fixtures/echo_server.php, which keeps only the
 * last), because a script that captures both a metric and an infrastructure reading makes two.
 */

file_put_contents(
    sys_get_temp_dir() . '/forge_ops_tracker_metrics_test_requests.jsonl',
    json_encode([
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'body' => json_decode(file_get_contents('php://input'), true),
    ]) . "\n",
    FILE_APPEND
);

http_response_code(202);
