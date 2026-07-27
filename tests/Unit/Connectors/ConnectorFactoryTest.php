<?php

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Enums\ConnectorType;
use App\Models\Partner;

afterEach(fn () => Mockery::close());

it('creates a ResConnectConnector for a partner configured with resconnect', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('getAttribute')->with('connector_config')->andReturn(['api_key' => 'test-key-123']);
    $partner->setRawAttributes(['connector_type' => ConnectorType::ResConnect->value]);

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
    $partner->setRawAttributes(['connector_type' => ConnectorType::ResConnect->value]);

    expect(fn () => ConnectorFactory::make($partner))
        ->toThrow(InvalidArgumentException::class, 'missing the ResConnect API key');
});
