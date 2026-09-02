<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

/**
 * The response of GET /api/v1/me: the merchant profile, its balances, and
 * webhook configuration (never the webhook secret itself).
 */
final readonly class Merchant
{
    /**
     * @param  array<mixed>  $settings
     * @param  Balance[]  $balances
     * @param  array{url: ?string, configured: bool, events: string[], signature_header: ?string}  $webhook
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $webhookUrl,
        public bool $isActive,
        public array $settings,
        public ?string $underpaymentTolerance,
        public ?string $createdAt,
        public array $balances,
        public array $webhook,
        public array $raw = [],
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $balances = [];
        foreach ((array) ($data['balances'] ?? []) as $balance) {
            if (is_array($balance)) {
                $balances[] = Balance::fromArray($balance);
            }
        }

        $webhook = (array) ($data['webhook'] ?? []);

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            email: $data['email'] ?? null,
            webhookUrl: $data['webhook_url'] ?? null,
            isActive: (bool) ($data['is_active'] ?? false),
            settings: is_array($data['settings'] ?? null) ? $data['settings'] : [],
            underpaymentTolerance: $data['underpayment_tolerance'] ?? null,
            createdAt: $data['created_at'] ?? null,
            balances: $balances,
            webhook: [
                'url' => $webhook['url'] ?? null,
                'configured' => (bool) ($webhook['configured'] ?? false),
                'events' => (array) ($webhook['events'] ?? []),
                'signature_header' => $webhook['signature_header'] ?? null,
            ],
            raw: $data,
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
