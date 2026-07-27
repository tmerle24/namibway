<?php

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Enums\ConnectorType;
use App\Models\Partner;

it('creates a ResConnectConnector for a partner configured with resconnect', function () {
    $partner = new Partner;
    $partner->setRawAttributes([
        'connector_type' => ConnectorType::ResConnect->value,
        'connector_property_code' => 'PROP001',
        'connector_config' => json_encode(['api_key' => 'test-key-123']),
    ]);

    $connector = ConnectorFactory::make($partner);

    expect($connector)->toBeInstanceOf(ResConnectConnector::class)
        ->and($connector->getConnectorType())->toBe('resconnect');
});

it('throws when no connector is configured', function () {
    $partner = new Partner;

    expect(fn () => ConnectorFactory::make($partner))
        ->toThrow(InvalidArgumentException::class, 'no automated booking connector');
});

it('throws when connector api_key is missing', function () {
    $partner = new Partner;
    $partner->setRawAttributes([
        'connector_type' => ConnectorType::ResConnect->value,
        'connector_config' => json_encode([]),
    ]);

    expect(fn () => ConnectorFactory::make($partner))
        ->toThrow(InvalidArgumentException::class, 'missing the ResConnect API key');
});
