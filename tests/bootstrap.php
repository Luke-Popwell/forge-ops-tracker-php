<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Marks the startup change snapshot as already scheduled before any test runs, so a test that
// init()s an enabled client before anything has called resetForTesting() can't schedule a real one
// for the end of the run. ChangeSnapshotTest opts back in with resetForTesting(changeSnapshot: true).
ForgeOps\Tracker\ForgeOpsTracker::resetForTesting();

// init() logs a one-time "Not sending" warning through error_log() when a test configures a DSN in a
// disabled environment without a logger. Keeps that out of the test output; the tests that check the
// warning pass a logger, or run a child process with its own error_log setting.
ini_set('error_log', '/dev/null');
