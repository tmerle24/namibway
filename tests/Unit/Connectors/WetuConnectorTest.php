<?php

use App\Connectors\Wetu\WetuClient;
use App\Connectors\Wetu\WetuConnector;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->client = Mockery::mock(WetuClient::class);
    $this->connector = new WetuConnector($this->client);
});

afterEach(fn () => Mockery::close());

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('wetu');
});

it('fetches and maps property content correctly', function () {
    $this->client->shouldReceive('get')
        ->once()
        ->with('WETU-001/Get', Mockery::type('array'))
        ->andReturn([
            'Name' => 'Dune Lodge',
            'Introduction' => 'A stunning lodge overlooking the Namib dunes.',
            'Images' => [
                ['Url' => 'https://cdn.wetu.com/img/dune1.jpg'],
                ['Url' => 'https://cdn.wetu.com/img/dune2.jpg'],
            ],
            'KeyFeatures' => [
                ['Text' => 'Private pool'],
                ['Text' => 'Guided dune walks'],
            ],
            'Location' => [
                'Region' => 'Hardap',
                'Latitude' => '-24.7282',
                'Longitude' => '15.9419',
            ],
            'Website' => 'https://dunelodge.com',
        ]);

    $content = $this->connector->fetchPropertyContent('WETU-001');

    expect($content->wetuId)->toBe('WETU-001')
        ->and($content->name)->toBe('Dune Lodge')
        ->and($content->description)->toBe('A stunning lodge overlooking the Namib dunes.')
        ->and($content->imageUrls)->toHaveCount(2)
        ->and($content->imageUrls[0])->toBe('https://cdn.wetu.com/img/dune1.jpg')
        ->and($content->highlights)->toBe(['Private pool', 'Guided dune walks'])
        ->and($content->region)->toBe('Hardap')
        ->and($content->latitude)->toBe(-24.7282)
        ->and($content->longitude)->toBe(15.9419)
        ->and($content->website)->toBe('https://dunelodge.com');
});

it('handles missing optional fields gracefully', function () {
    $this->client->shouldReceive('get')->andReturn([
        'Name' => 'Minimal Lodge',
    ]);

    $content = $this->connector->fetchPropertyContent('WETU-002');

    expect($content->name)->toBe('Minimal Lodge')
        ->and($content->description)->toBeNull()
        ->and($content->imageUrls)->toBeEmpty()
        ->and($content->highlights)->toBeEmpty()
        ->and($content->latitude)->toBeNull();
});

it('returns empty content on API error', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(404);
    $httpResponse->shouldReceive('body')->andReturn('Not found');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(makePsrResponseStub());

    $this->client->shouldReceive('get')
        ->andThrow(new RequestException($httpResponse));

    $content = $this->connector->fetchPropertyContent('WETU-GHOST');

    expect($content->wetuId)->toBe('WETU-GHOST')
        ->and($content->name)->toBe('')
        ->and($content->imageUrls)->toBeEmpty();
});

it('filters out images without a url', function () {
    $this->client->shouldReceive('get')->andReturn([
        'Name' => 'Test',
        'Images' => [
            ['Url' => 'https://cdn.wetu.com/img/valid.jpg'],
            ['Caption' => 'No url here'],
            ['Url' => null],
        ],
    ]);

    $content = $this->connector->fetchPropertyContent('WETU-003');

    expect($content->imageUrls)->toHaveCount(1)
        ->and($content->imageUrls[0])->toBe('https://cdn.wetu.com/img/valid.jpg');
});
