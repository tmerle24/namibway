<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Estimates Cloudflare R2 storage cost by listing actual object sizes in each
 * bucket via the S3-compatible API — we don't have a Cloudflare billing/analytics
 * API token, only the storage access keys, so this is a storage-only estimate at
 * the public Standard-tier price. It ignores Class A/B operations (untracked) and
 * egress (R2 doesn't charge for it), and is NOT billing-accurate.
 */
class R2StorageCostEstimator
{
    private const STORAGE_PRICE_PER_GB_MONTH = 0.015;

    private const FREE_TIER_GB = 10.0;

    private const BYTES_PER_GB = 1_073_741_824;

    /** @var array<string, string> disk => human label */
    private const DISKS = [
        'r2' => 'Media (listings)',
        'r2-backups' => 'DB backups',
    ];

    /** @return array{breakdown: array<string, array{label: string, bytes: int}>, total_bytes: int, total_gb: float, estimated_monthly_cost: float} */
    public function estimate(): array
    {
        return Cache::remember('r2_storage_cost_estimate', now()->addHours(6), function () {
            $breakdown = [];
            $totalBytes = 0;

            foreach (self::DISKS as $disk => $label) {
                $bytes = $this->bucketSizeInBytes($disk);
                $totalBytes += $bytes;
                $breakdown[$disk] = ['label' => $label, 'bytes' => $bytes];
            }

            $totalGb = $totalBytes / self::BYTES_PER_GB;
            $billableGb = max(0.0, $totalGb - self::FREE_TIER_GB);

            return [
                'breakdown' => $breakdown,
                'total_bytes' => $totalBytes,
                'total_gb' => $totalGb,
                'estimated_monthly_cost' => round($billableGb * self::STORAGE_PRICE_PER_GB_MONTH, 2),
            ];
        });
    }

    private function bucketSizeInBytes(string $disk): int
    {
        $config = config("filesystems.disks.{$disk}");

        if (empty($config['key']) || empty($config['secret']) || empty($config['bucket']) || empty($config['endpoint'])) {
            return 0;
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'] ?? 'auto',
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            // Without an explicit timeout, an R2 hiccup would hang the admin
            // dashboard request indefinitely instead of just showing $0.
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 15,
            ],
        ]);

        $bytes = 0;
        $continuationToken = null;

        try {
            do {
                $args = ['Bucket' => $config['bucket']];
                if ($continuationToken) {
                    $args['ContinuationToken'] = $continuationToken;
                }

                $result = $client->listObjectsV2($args);

                foreach ($result['Contents'] ?? [] as $object) {
                    $bytes += $object['Size'];
                }

                $continuationToken = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? null) : null;
            } while ($continuationToken);
        } catch (\Throwable $e) {
            Log::warning("R2StorageCostEstimator: failed to list bucket for disk '{$disk}'", ['error' => $e->getMessage()]);

            return 0;
        }

        return $bytes;
    }
}
