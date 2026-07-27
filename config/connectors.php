<?php

return [

    'resconnect' => [
        // Default base URL for the ResConnect API.
        // Each partner can override this via their connector_config JSON.
        // Obtain the correct URL from ResRequest for each property.
        'default_base_url' => env('RESCONNECT_BASE_URL', 'https://api.resrequest.com'),
    ],

];
