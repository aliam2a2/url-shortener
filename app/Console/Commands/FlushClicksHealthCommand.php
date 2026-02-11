<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class FlushClicksHealthCommand extends Command
{
    protected $signature = 'short-links:flush-health {--max-delay=130 : Max allowed delay in seconds since last flush run}';

    protected $description = 'Check click flush scheduler health and detect stale execution.';

    private const META_LAST_FLUSH_AT_KEY = 'sl:meta:last_flush_at';
    private const META_LAST_FLUSH_IDS_KEY = 'sl:meta:last_flush_ids';
    private const META_LAST_FLUSH_DELTA_KEY = 'sl:meta:last_flush_delta';

    public function handle(): int
    {
        $connection = Redis::connection();
        $lastFlushAt = $connection->get(self::META_LAST_FLUSH_AT_KEY);

        if (! is_string($lastFlushAt) || $lastFlushAt === '') {
            $this->error('No flush heartbeat found. Run short-links:flush-clicks once, then re-check.');

            return self::FAILURE;
        }

        $lastFlush = CarbonImmutable::parse($lastFlushAt);
        $ageSeconds = max(now()->diffInSeconds($lastFlush, true), 0);
        $maxDelay = max((int) $this->option('max-delay'), 1);

        $processedIds = (int) ($connection->get(self::META_LAST_FLUSH_IDS_KEY) ?? 0);
        $flushedDelta = (int) ($connection->get(self::META_LAST_FLUSH_DELTA_KEY) ?? 0);

        $this->line(sprintf('last_flush_at: %s', $lastFlush->toIso8601String()));
        $this->line(sprintf('age_seconds: %d', $ageSeconds));
        $this->line(sprintf('last_processed_ids: %d', $processedIds));
        $this->line(sprintf('last_flushed_delta: %d', $flushedDelta));

        if ($ageSeconds > $maxDelay) {
            $this->error(sprintf(
                'Flush job is stale. Last run was %d seconds ago (max allowed %d).',
                $ageSeconds,
                $maxDelay
            ));

            return self::FAILURE;
        }

        $this->info('Flush job heartbeat is healthy.');

        return self::SUCCESS;
    }
}
