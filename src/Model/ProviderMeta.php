<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Decoded structure of the provider `meta` JSON field.
 */
class ProviderMeta
{
    /** @var array<int, array{url: string, addedAt?: int}>|null */
    public ?array $customEndpoints = null;
    public ?string $usageScript = null;
    public ?string $costMultiplier = null;
    public ?string $apiFormat = null;
    public ?string $providerType = null;
    public ?string $limitDailyUsd = null;
    public ?string $limitMonthlyUsd = null;

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }
        return self::fromArray($data);
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        $meta = new self();
        $meta->customEndpoints = $data['customEndpoints'] ?? null;
        $meta->usageScript = $data['usageScript'] ?? null;
        $meta->costMultiplier = $data['costMultiplier'] ?? null;
        $meta->apiFormat = $data['apiFormat'] ?? null;
        $meta->providerType = $data['providerType'] ?? null;
        $meta->limitDailyUsd = $data['limitDailyUsd'] ?? null;
        $meta->limitMonthlyUsd = $data['limitMonthlyUsd'] ?? null;
        return $meta;
    }

    /**
     * Encode to JSON string.
     */
    public function toJson(): string
    {
        $data = array_filter([
            'customEndpoints' => $this->customEndpoints,
            'usageScript' => $this->usageScript,
            'costMultiplier' => $this->costMultiplier,
            'apiFormat' => $this->apiFormat,
            'providerType' => $this->providerType,
            'limitDailyUsd' => $this->limitDailyUsd,
            'limitMonthlyUsd' => $this->limitMonthlyUsd,
        ], fn($v) => $v !== null);

        return json_encode($data ?: new \stdClass(), JSON_UNESCAPED_SLASHES);
    }
}
