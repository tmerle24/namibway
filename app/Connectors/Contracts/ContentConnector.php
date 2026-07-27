<?php

namespace App\Connectors\Contracts;

use App\Connectors\Wetu\DTOs\PropertyContent;

interface ContentConnector
{
    /**
     * Fetch rich content for a property (description, highlights, images, etc.)
     * to be imported into a NamibWay Listing.
     */
    public function fetchPropertyContent(string $propertyId): PropertyContent;

    /**
     * Return the connector identifier (e.g. "wetu").
     */
    public function getConnectorType(): string;
}
