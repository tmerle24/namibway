<?php

namespace App\Services;

use App\Services\Kaia\AnthropicClient;
use Throwable;

class ShopProductDescriber
{
    private const MODEL = 'claude-haiku-4-5-20251001';

    public function __construct(private AnthropicClient $client) {}

    /**
     * Generate a title and description for a single product image.
     *
     * @return array{title: string, description: string}
     */
    public function describe(string $imageUrl): array
    {
        $results = $this->describeMany([$imageUrl]);

        return $results[0] ?? ['title' => 'Product', 'description' => ''];
    }

    /**
     * Generate titles and descriptions for multiple product images in one API call.
     *
     * The order of the returned array matches the order of $imageUrls.
     *
     * @param  list<string>  $imageUrls
     * @return list<array{title: string, description: string}>
     */
    public function describeMany(array $imageUrls): array
    {
        if ($imageUrls === []) {
            return [];
        }

        $content = [];

        foreach ($imageUrls as $i => $url) {
            $content[] = ['type' => 'text', 'text' => 'Image '.($i + 1).':'];
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'url', 'url' => $url],
            ];
        }

        try {
            $response = $this->client->send(
                model: self::MODEL,
                system: 'You write concise English product listings for a Namibian artisan shop. '.
                        'For each product image, provide a short product name (2–5 words) and a '.
                        'one-sentence description that is warm and factual. No prices. No hashtags.',
                messages: [['role' => 'user', 'content' => $content]],
                tools: [[
                    'name' => 'describe_products',
                    'description' => 'Return a title and description for each product image, in the same order.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'products' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                    ],
                                    'required' => ['title', 'description'],
                                ],
                            ],
                        ],
                        'required' => ['products'],
                    ],
                ]],
                maxTokens: 1024,
                toolChoice: ['type' => 'tool', 'name' => 'describe_products'],
            );
        } catch (Throwable) {
            return array_fill(0, count($imageUrls), ['title' => 'Product', 'description' => '']);
        }

        $products = data_get($response, 'content.0.input.products', []);

        $results = [];

        foreach ($imageUrls as $i => $_) {
            $p = $products[$i] ?? [];
            $results[] = [
                'title' => trim((string) ($p['title'] ?? 'Product')),
                'description' => trim((string) ($p['description'] ?? '')),
            ];
        }

        return $results;
    }
}
