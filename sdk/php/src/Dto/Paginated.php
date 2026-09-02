<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Dto;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A page of hydrated DTOs from a Laravel-paginated CryptoPay endpoint.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Paginated implements Countable, IteratorAggregate
{
    /**
     * @param  T[]  $data  Already-hydrated DTOs.
     * @param  array<mixed>  $meta  Raw `meta` block (current_page, last_page, total, per_page, ...).
     * @param  array<mixed>  $links  Raw `links` block (first, last, prev, next).
     * @param  array<mixed>  $raw  The original raw payload.
     */
    public function __construct(
        public array $data,
        public array $meta,
        public array $links,
        public array $raw = [],
    ) {
    }

    public function currentPage(): int
    {
        return (int) ($this->meta['current_page'] ?? 1);
    }

    public function lastPage(): int
    {
        return (int) ($this->meta['last_page'] ?? 1);
    }

    public function total(): int
    {
        return (int) ($this->meta['total'] ?? count($this->data));
    }

    public function perPage(): int
    {
        return (int) ($this->meta['per_page'] ?? count($this->data));
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
