<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Marks the startup change snapshot as already scheduled before any test runs, so a test that
// init()s an enabled client before anything has called resetForTesting() can't schedule a real one
// for the end of the run. ChangeSnapshotTest opts back in with resetForTesting(changeSnapshot: true).
ForgeOps\Tracker\ForgeOpsTracker::resetForTesting();
