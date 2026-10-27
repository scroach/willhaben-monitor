<?php

declare(strict_types=1);

namespace App;

final class ImmobilienScout24ListingsResult
{
    private function __construct(private readonly array $data)
    {
    }

    public static function fromGraphQL(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHits(): array
    {
        $hits = $this->getResults()['hits'] ?? [];

        return is_array($hits) ? $hits : [];
    }

    public function getCurrentPage(): int
    {
        return (int) ($this->getResults()['pagination']['pageIndex'] ?? 0) + 1;
    }

    public function getMaxPage(): int
    {
        return (int) ($this->getResults()['pagination']['totalPages'] ?? 0);
    }

    public function getTotalHits(): int
    {
        return (int) ($this->getResults()['totalHits'] ?? 0);
    }

    private function getResults(): array
    {
        $results = $this->data['data']['getDataByURL']['results'] ?? [];

        return is_array($results) ? $results : [];
    }
}
