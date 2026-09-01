<?php

namespace App\Console\Commands;

use App\Models\Network;
use Illuminate\Console\Command;

/**
 * Marks a network unhealthy when the watcher has not sent a heartbeat for more
 * than two minutes (SPEC §7 heartbeats every 15s).
 */
class NetworksHealthCommand extends Command
{
    protected $signature = 'networks:health {--stale=120 : Seconds without a heartbeat before a network is unhealthy}';

    protected $description = 'Flag networks whose watcher heartbeat has gone stale';

    public function handle(): int
    {
        $cutoff = now()->subSeconds((int) $this->option('stale'));

        $stale = Network::query()
            ->where('watcher_healthy', true)
            ->where(fn ($q) => $q->whereNull('watcher_seen_at')->orWhere('watcher_seen_at', '<', $cutoff))
            ->get();

        foreach ($stale as $network) {
            $network->forceFill(['watcher_healthy' => false])->save();
            $this->warn("Network {$network->code} marked unhealthy (last heartbeat: ".($network->watcher_seen_at?->toIso8601String() ?? 'never').').');
        }

        $this->info("Checked watcher health; {$stale->count()} network(s) marked unhealthy.");

        return self::SUCCESS;
    }
}
