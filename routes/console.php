<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('generations:sync')->everyMinute()->withoutOverlapping(5);

// Runs queued retries on hosts without a dedicated queue worker: every minute a worker
// starts, works for 50 seconds and exits, so a retry waits a few seconds at most. It is
// listed last because it holds this scheduler run until it exits. The lock expires after
// two minutes so a killed run can't block the next ones.
Schedule::command('queue:work --max-time=50')->everyMinute()->withoutOverlapping(2);
