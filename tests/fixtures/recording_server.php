<?php

declare(strict_types=1);

/**
 * A router for `php -S` used by EnvironmentDefaultTest: appends every request's path and body as
 * one JSON line to the file named by RECORDING_SERVER_FILE, so a test can see everything a child
 * process sent, not just the last request.
 */

file_put_contents(
    (string) getenv('RECORDING_SERVER_FILE'),
    json_encode([
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'body' => file_get_contents('php://input'),
    ]) . "\n",
    FILE_APPEND | LOCK_EX
);

http_response_code(202);
