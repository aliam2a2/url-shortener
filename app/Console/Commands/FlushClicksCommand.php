<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class FlushClicksCommand extends Command
{
    protected $signature = 'short-links:flush-clicks';

    protected $description = 'Flush aggregated click counters from Valkey into PostgreSQL.';

    private const GET_AND_DELETE_SCRIPT = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then
    return 0
end
redis.call('DEL', KEYS[1])
return tonumber(value)
LUA;

    private const META_LAST_FLUSH_AT_KEY = 'sl:meta:last_flush_at';
    private const META_LAST_FLUSH_IDS_KEY = 'sl:meta:last_flush_ids';
    private const META_LAST_FLUSH_DELTA_KEY = 'sl:meta:last_flush_delta';

    public function handle(): int
    {
        $connection = Redis::connection();
        $ids = $connection->smembers('sl:dirty');
        $processedIds = 0;
        $flushedDelta = 0;

        if (empty($ids)) {
            $this->writeFlushMeta($connection, 0, 0);
            $this->info('No dirty counters to flush.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            $idAsInt = (int) $id;

            if ($idAsInt <= 0) {
                $connection->srem('sl:dirty', $id);
                continue;
            }

            $processedIds++;
            $delta = (int) $connection->eval(self::GET_AND_DELETE_SCRIPT, 1, sprintf('sl:clicks:%d', $idAsInt));
            $flushedDelta += max($delta, 0);

            if ($delta > 0) {
                DB::table('short_links')->where('id', $idAsInt)->increment('clicks_total', $delta);
            }

            $connection->srem('sl:dirty', (string) $idAsInt);
        }

        $this->writeFlushMeta($connection, $processedIds, $flushedDelta);
        $this->info(sprintf('Flushed click counters for %d short links.', count($ids)));

        return self::SUCCESS;
    }

    private function writeFlushMeta($connection, int $processedIds, int $flushedDelta): void
    {
        $connection->mset([
            self::META_LAST_FLUSH_AT_KEY => now()->toIso8601String(),
            self::META_LAST_FLUSH_IDS_KEY => (string) $processedIds,
            self::META_LAST_FLUSH_DELTA_KEY => (string) $flushedDelta,
        ]);
    }
}
