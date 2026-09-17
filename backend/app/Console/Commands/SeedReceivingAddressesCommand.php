<?php

namespace App\Console\Commands;

use App\Enums\NetworkCode;
use App\Models\DepositAddress;
use App\Models\ReceivingAddress;
use App\Models\Wallet;
use App\Services\AuditLogger;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put the first N HD addresses of every configured network into the static
 * receiving list (Admin → Addresses), so day-to-day invoices rotate over a
 * small, known set of addresses and fresh HD derivation is only the overflow
 * for peaks (AddressService::allocate()).
 *
 * Safe to re-run: an address that is already listed is skipped. An index that
 * has already been handed to an invoice as a derived address is skipped too —
 * it belongs to that invoice forever (SPEC §4) and `deposit_addresses` is
 * unique per (network, address), so pooling it would collide on first lease.
 * Afterwards `wallets.next_index` is raised to at least N so the overflow path
 * never derives one of the pooled indexes a second time.
 */
class SeedReceivingAddressesCommand extends Command
{
    protected $signature = 'cryptopay:seed-addresses
        {--count=10 : How many HD addresses (indexes 0..count-1) to list per network}
        {--network=* : Only these networks (ethereum, bsc, tron); default all}
        {--dry-run : Show what would be added without writing anything}';

    protected $description = 'Add the first N HD addresses of each network to the static receiving list';

    public function handle(WalletService $wallets, AuditLogger $audit): int
    {
        $count = (int) $this->option('count');

        if ($count < 1 || $count > 100) {
            $this->error('--count must be between 1 and 100.');

            return self::FAILURE;
        }

        $codes = array_column(NetworkCode::cases(), 'value');
        $requested = array_values(array_filter((array) $this->option('network')));

        foreach ($requested as $code) {
            if (! in_array($code, $codes, true)) {
                $this->error("Unknown network [{$code}]. Use: ".implode(', ', $codes).'.');

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $failed = false;

        foreach ($requested === [] ? $codes : $requested as $code) {
            $this->line('');
            $this->info("[{$code}]");

            $source = $wallets->source($code);

            if (! in_array($source, ['database', 'env'], true)) {
                $this->warn('  no xpub (neither in the admin Wallet page nor in the watcher env) — skipped');
                $failed = true;

                continue;
            }

            $xpub = $source === 'database'
                ? (string) Wallet::query()->where('network_code', $code)->value('xpub')
                : null;

            try {
                $derived = $wallets->deriveBatch($code, 0, $count, $xpub);
            } catch (ValidationException $e) {
                $this->error('  the watcher rejected the stored xpub: '.implode(' ', $e->errors()['xpub'] ?? []));
                $failed = true;

                continue;
            }

            $this->seedNetwork($code, $derived, $dryRun, $wallets, $audit);
        }

        $this->line('');

        if ($dryRun) {
            $this->comment('Dry run: nothing was written.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array{index: int, path: string, address: string}>  $derived
     */
    private function seedNetwork(string $code, array $derived, bool $dryRun, WalletService $wallets, AuditLogger $audit): void
    {
        $isEvm = NetworkCode::isEvmCode($code);
        $key = fn (string $address) => $isEvm ? mb_strtolower($address) : $address;

        $listed = ReceivingAddress::query()
            ->where('network_code', $code)
            ->pluck('address')
            ->map($key)
            ->flip();

        // Derived rows only: a pooled address has its own deposit row too, but
        // those are already caught by `$listed`.
        $issued = DepositAddress::query()
            ->where('network_code', $code)
            ->whereNotNull('derivation_index')
            ->pluck('address')
            ->map($key)
            ->flip();

        $added = 0;

        foreach ($derived as $item) {
            $address = $item['address'];
            $tag = sprintf('  #%-3d %s', $item['index'], $address);

            if ($listed->has($key($address))) {
                $this->line("{$tag}  already listed");

                continue;
            }

            if ($issued->has($key($address))) {
                $this->line("{$tag}  skipped: already issued to an invoice as a derived address");

                continue;
            }

            if (! $dryRun) {
                $row = ReceivingAddress::create([
                    'network_code' => $code,
                    'address' => $address,
                    'currencies' => [],
                    'label' => "HD #{$item['index']}",
                    'priority' => $item['index'],
                    'is_enabled' => true,
                    'created_by' => null,
                ]);

                $audit->log('receiving_address.created', $row, [
                    'network' => $code,
                    'address' => $address,
                    'currencies' => [],
                    'label' => $row->label,
                    'priority' => $row->priority,
                    'is_enabled' => true,
                    'via' => 'cryptopay:seed-addresses',
                ]);
            }

            $this->line("{$tag}  ".($dryRun ? 'would add' : 'added'));
            $added++;
        }

        $target = count($derived);

        if (! $dryRun) {
            $bumped = $this->raiseNextIndex($code, $target);
            $wallets->forget($code);

            if ($bumped !== null) {
                $this->line("  next_index raised {$bumped} → {$target} so the overflow path skips the pooled indexes");
            }
        }

        $this->info("  {$added} added, ".(count($derived) - $added).' skipped');
    }

    /** @return int|null the previous next_index when it was raised, null when it was already high enough */
    private function raiseNextIndex(string $code, int $target): ?int
    {
        return DB::transaction(function () use ($code, $target) {
            $wallet = Wallet::query()->where('network_code', $code)->lockForUpdate()->first();

            if (! $wallet) {
                Wallet::create([
                    'network_code' => $code,
                    'next_index' => $target,
                    'derivation_path' => Wallet::defaultPathFor($code),
                ]);

                return 0;
            }

            $current = (int) $wallet->next_index;

            if ($current >= $target) {
                return null;
            }

            $wallet->forceFill(['next_index' => $target])->save();

            return $current;
        });
    }
}
