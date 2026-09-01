<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminInvoiceResource;
use App\Http\Resources\AdminNetworkResource;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Network;
use App\Models\WebhookDelivery;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const CURRENCIES = ['USDT', 'USDC'];

    public function __invoke(Request $request): JsonResponse
    {
        $paidStatuses = [InvoiceStatus::Paid->value, InvoiceStatus::Overpaid->value];

        $volume24h = $this->volumeByCurrency(now()->subDay());
        $volumeTotal = $this->volumeByCurrency(null);

        return response()->json([
            'stats' => [
                'invoices_total' => Invoice::query()->count(),
                'invoices_paid' => Invoice::query()->whereIn('status', $paidStatuses)->count(),
                'volume_24h' => $volume24h,
                'volume_total' => $volumeTotal,
                'merchants_active' => Merchant::query()->where('is_active', true)->count(),
                'pending_webhooks' => WebhookDelivery::query()
                    ->where('status', WebhookDeliveryStatus::Pending->value)->count(),
            ],
            'chart' => $this->chart(),
            'recent_invoices' => AdminInvoiceResource::collection(
                Invoice::query()->with(['depositAddress', 'transactions', 'merchant'])->latest()->limit(10)->get()
            )->resolve(),
            'networks' => AdminNetworkResource::collection(
                Network::query()->orderBy('code')->get()
            )->resolve(),
        ]);
    }

    /** Confirmed deposit volume per currency, from the ledger. */
    private function volumeByCurrency(?\DateTimeInterface $since): array
    {
        $rows = LedgerEntry::query()
            ->selectRaw('currency, sum(amount) as total')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $result = [];

        foreach (self::CURRENCIES as $currency) {
            $result[$currency] = Money::format($rows[$currency] ?? '0', 6);
        }

        return $result;
    }

    /** 30 days of daily deposit volume, one row per day, zero-filled. */
    private function chart(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $rows = LedgerEntry::query()
            ->where('created_at', '>=', $start)
            ->get(['currency', 'amount', 'created_at']);

        $buckets = [];

        for ($i = 0; $i < 30; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $buckets[$date] = ['date' => $date, 'USDT' => '0', 'USDC' => '0'];
        }

        foreach ($rows as $row) {
            $date = $row->created_at->toDateString();

            if (! isset($buckets[$date]) || ! in_array($row->currency, self::CURRENCIES, true)) {
                continue;
            }

            $buckets[$date][$row->currency] = Money::add($buckets[$date][$row->currency], $row->amount);
        }

        return array_values(array_map(fn ($b) => [
            'date' => $b['date'],
            'USDT' => Money::format($b['USDT'], 6),
            'USDC' => Money::format($b['USDC'], 6),
        ], $buckets));
    }
}
