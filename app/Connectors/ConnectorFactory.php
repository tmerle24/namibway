<?php

namespace App\Connectors;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\Contracts\ContentConnector;
use App\Connectors\HopeCloud\HopeCloudClient;
use App\Connectors\HopeCloud\HopeCloudConnector;
use App\Connectors\Native\NativeConnector;
use App\Connectors\NightsBridge\NightsBridgeClient;
use App\Connectors\NightsBridge\NightsBridgeConnector;
use App\Connectors\Nwr\NwrConnector;
use App\Connectors\ResConnect\ResConnectClient;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Connectors\Wetu\WetuClient;
use App\Connectors\Wetu\WetuConnector;
use App\Enums\ConnectorType;
use App\Models\Partner;
use InvalidArgumentException;

class ConnectorFactory
{
    public static function makeBooking(Partner $partner): BookingConnector
    {
        $type = $partner->connector_type;

        return match ($type) {
            ConnectorType::ResConnect => self::makeResConnect($partner),
            ConnectorType::NightsBridge => self::makeNightsBridge($partner),
            ConnectorType::HopeCloud => self::makeHopeCloud($partner),
            ConnectorType::Nwr => new NwrConnector,
            ConnectorType::Native => new NativeConnector,
            null, ConnectorType::Manual, ConnectorType::Wetu => throw new InvalidArgumentException(
                "Partner [{$partner->id}] has no automated booking connector configured."
            ),
        };
    }

    public static function makeContent(Partner $partner): ContentConnector
    {
        $type = $partner->connector_type;

        return match ($type) {
            ConnectorType::Wetu => self::makeWetu($partner),
            default => throw new InvalidArgumentException(
                "Partner [{$partner->id}] has no content connector configured."
            ),
        };
    }

    /** @deprecated Use makeBooking() */
    public static function make(Partner $partner): BookingConnector
    {
        return self::makeBooking($partner);
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

    private static function makeNightsBridge(Partner $partner): NightsBridgeConnector
    {
        $config = $partner->connector_config ?? [];
        $bbid = $config['bbid'] ?? '';
        $apiKey = $config['api_key'] ?? '';
        $baseUrl = $config['base_url'] ?? config('connectors.nightsbridge.default_base_url');

        if (blank($bbid) || blank($apiKey)) {
            throw new InvalidArgumentException(
                "Partner [{$partner->id}] is missing NightsBridge bbid or api_key."
            );
        }

        return new NightsBridgeConnector(
            client: new NightsBridgeClient(bbid: $bbid, apiKey: $apiKey, baseUrl: $baseUrl),
        );
    }

    private static function makeHopeCloud(Partner $partner): HopeCloudConnector
    {
        $config = $partner->connector_config ?? [];
        $apiKey = $config['api_key'] ?? '';
        $accountId = $config['account_id'] ?? '';
        $baseUrl = $config['base_url'] ?? config('connectors.hopecloud.default_base_url');

        if (blank($apiKey) || blank($accountId)) {
            throw new InvalidArgumentException(
                "Partner [{$partner->id}] is missing hopeCloud api_key or account_id."
            );
        }

        return new HopeCloudConnector(
            client: new HopeCloudClient(apiKey: $apiKey, accountId: $accountId, baseUrl: $baseUrl),
        );
    }

    private static function makeWetu(Partner $partner): WetuConnector
    {
        $config = $partner->connector_config ?? [];
        $apiKey = $config['api_key'] ?? '';

        if (blank($apiKey)) {
            throw new InvalidArgumentException(
                "Partner [{$partner->id}] is missing the Wetu API key."
            );
        }

        return new WetuConnector(
            client: new WetuClient(apiKey: $apiKey),
        );
    }
}
