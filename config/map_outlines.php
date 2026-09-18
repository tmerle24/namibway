<?php

/*
|--------------------------------------------------------------------------
| Country outlines for the drawn expedition map
|--------------------------------------------------------------------------
|
| One simplified border per ISO 3166-1 alpha-2 code, as [longitude, latitude]
| pairs, clockwise from any point. Drawn by hand from the coastline and the
| river borders, a few dozen points each: the map is an illustration, not
| cartography, and a few kilometres of Kunene are not what anybody reads it
| for. A country is added here, not in code (App\Sites\Rendering\RouteMap).
|
*/

return [
    'NA' => [
        [11.75, -17.25], [12.2, -17.2], [12.6, -16.98], [13.2, -16.98], [13.5, -17.25], [14.2, -17.4],
        [16.5, -17.4], [18.45, -17.4], [18.9, -17.8], [19.9, -17.85], [20.8, -18.0], [21.4, -18.0],
        [22.5, -17.8], [23.2, -17.55], [24.0, -17.45], [24.7, -17.45], [25.26, -17.78], [24.6, -17.95],
        [23.5, -18.0], [22.0, -18.1], [21.0, -18.3], [21.0, -22.0], [20.0, -22.0], [20.0, -24.75],
        [20.0, -28.4], [19.6, -28.5], [19.0, -28.8], [18.2, -28.9], [17.4, -28.75], [16.9, -28.6],
        [16.45, -28.63], [16.0, -28.3], [15.3, -27.4], [15.1, -26.65], [14.9, -25.8], [14.6, -24.0],
        [14.5, -22.9], [14.1, -21.8], [13.4, -20.3], [12.9, -19.3], [12.0, -18.0],
    ],
];
