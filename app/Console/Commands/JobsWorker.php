<?php
// app/Console/Commands/JobsWorker.php (snippet)
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class JobsWorker extends Command
{
    protected $signature = 'jobs:worker {--sleep=1}';
    protected $description = 'Simple polling worker for notification jobs';

    public function handle(): int
    {
        $sleep = (int)$this->option('sleep');
        $this->info('Worker started. Press Ctrl+C to stop.');

        while (true) {
            $job = DB::transaction(function () {
                $row = DB::table('notification_jobs')
                    ->whereIn('status', ['PENDING','RETRY'])
                    ->where('next_run_at', '<=', now())
                    ->lockForUpdate()
                    ->first();
                if (!$row) return null;

                DB::table('notification_jobs')->where('id', $row->id)->update([
                    'status' => 'PROCESSING',
                    'updated_at' => now(),
                ]);
                return $row;
            });

            if (!$job) {
                sleep($sleep);
                continue;
            }

            $ok = (mt_rand(1, 100) <= 70);
            if ($ok) {
                DB::table('notification_jobs')->where('id', $job->id)->update([
                    'status' => 'SUCCESS',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info("Job {$job->id} SUCCESS");
            } else {
                $attempts = $job->attempts + 1;
                $max = $job->max_attempts;
                $base = pow(2, max(0,$attempts-1));
                $jitter = mt_rand(0, 30) / 100.0;
                $delay = (int)ceil($base * (1 + $jitter));

                $status = $attempts < $max ? 'RETRY' : 'FAILED';
                DB::table('notification_jobs')->where('id', $job->id)->update([
                    'status' => $status,
                    'attempts' => $attempts,
                    'next_run_at' => $status === 'RETRY' ? now()->addSeconds($delay) : null,
                    'last_error' => 'Simulated transient failure',
                    'updated_at' => now(),
                ]);
                $this->warn("Job {$job->id} {$status} (attempt {$attempts})");
            }
        }
        return 0;
    }
}
