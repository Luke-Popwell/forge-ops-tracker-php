<?php

declare(strict_types=1);

/**
 * Same purpose as fixtures/echo_server.php, on a separate port -- used by
 * ForgeOpsTrackerTest specifically, kept as its own instance so the two
 * test classes' local servers never collide.
 */

$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents(
    sys_get_temp_dir() . '/forge_ops_tracker_facade_test_request.json',
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'headers' => $headers,
        'body' => $body,
    ])
);

http_response_code(202);
