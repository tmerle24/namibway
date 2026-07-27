<?php

namespace App\Connectors;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\ResConnect\ResConnectClient;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Enums\ConnectorType;
use App\Models\Partner;
use InvalidArgumentException;

class ConnectorFactory
{
    public static function make(Partner $partner): BookingConnector
    {
        $type = ConnectorType::tryFrom($partner->connector_type ?? '');

        return match ($type) {
            ConnectorType::ResConnect => self::makeResConnect($partner),
            null, ConnectorType::Manual => throw new InvalidArgumentException(
                "Partner [{$partner->id}] has no automated booking connector configured."
            ),
            default => throw new InvalidArgumentException(
                "Connector type [{$type->value}] is not yet implemented."
            ),
        };
    }

    private static function makeResConnect(Partner $partner): ResConnectConnector
    {
        $config = $partner->connector_config ?? [];

        $apiKey = $config['api_key'] ?? '';
        $baseUrl = $config['base_url'] ?? config('connectors.resconnect.default_base_url');

        if (blank($apiKey)) {
            throw new InvalidArgumentException(
                "Partner [{$partner->id}] is missing the ResConnect API key."
            );
        }

        return new ResConnectConnector(
            client: new ResConnectClient(apiKey: $apiKey, baseUrl: $baseUrl),
        );
    }
}
