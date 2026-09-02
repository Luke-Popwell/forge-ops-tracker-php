<?php

declare(strict_types=1);

/**
 * A tiny router for `php -S`, used by ClientTest to test Client's actual
 * curl invocation against a real local HTTP server, rather than mocking
 * PHP's global curl_* functions (which isn't practical without an
 * extension like uopz/runkit). Records what it received to a temp file
 * for the test to inspect, and returns a status code based on the
 * request path.
 */

$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents(
    sys_get_temp_dir() . '/forge_ops_tracker_test_request.json',
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'headers' => $headers,
        'body' => $body,
    ])
);

if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/unauthorized')) {
    http_response_code(401);
} else {
    http_response_code(202);
}
