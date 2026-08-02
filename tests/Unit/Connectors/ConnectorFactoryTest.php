<?php

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Enums\ConnectorType;
use App\Models\Partner;

afterEach(fn () => Mockery::close());

it('creates a ResConnectConnector for a verified partner configured with resconnect', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('getAttribute')->with('connector_config')->andReturn(['api_key' => 'test-key-123']);
    $partner->setRawAttributes([
        'connector_type' => ConnectorType::ResConnect->value,
        'connector_verified_at' => now(),
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
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('getAttribute')->with('connector_config')->andReturn([]);
    $partner->setRawAttributes([
        'connector_type' => ConnectorType::ResConnect->value,
        'connector_verified_at' => now(),
    ]);

    expect(fn () => ConnectorFactory::make($partner))
        ->toThrow(InvalidArgumentException::class, 'missing the ResConnect API key');
});

it('throws when the connector has not been verified yet, even with a valid api_key', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('getAttribute')->with('connector_config')->andReturn(['api_key' => 'test-key-123']);
    $partner->setRawAttributes(['connector_type' => ConnectorType::ResConnect->value]);

    expect(fn () => ConnectorFactory::make($partner))
        ->toThrow(InvalidArgumentException::class, "haven't been verified yet");
});
