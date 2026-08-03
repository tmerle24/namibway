<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 2.3cm 2cm; }
    * { box-sizing: border-box; }
    body, h1, h2, p, table { margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #2c2521; background: #fff; }

    .eyebrow { font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; color: #c0533a; font-weight: bold; }
    h1 { font-size: 20px; color: #2c2521; margin: 2px 0 4px; }
    .subtitle { font-size: 11px; color: #7a6a5e; margin-bottom: 18px; }

    h2 { font-size: 13px; color: #2c2521; border-bottom: 1px solid #e8e0d4; padding-bottom: 5px; margin: 20px 0 10px; }

    p { margin-bottom: 8px; line-height: 1.5; }

    code, .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 9.5px; }

    .box { background: #faf8f5; border-left: 3px solid #c0533a; padding: 10px 14px; margin-bottom: 12px; font-size: 10px; color: #5b5346; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th { text-align: left; font-size: 9px; text-transform: uppercase; color: #a09080; padding: 4px 8px 4px 0; border-bottom: 1px solid #e8e0d4; }
    td { padding: 5px 8px 5px 0; border-bottom: 1px dotted #e8e0d4; vertical-align: top; }

    .endpoint { background: #2c2521; color: #f5f0e8; padding: 8px 10px; border-radius: 4px; margin-bottom: 6px; font-size: 9.5px; }
    .method { color: #e8b84b; font-weight: bold; }

    .footer { margin-top: 22px; padding-top: 10px; border-top: 1px solid #e8e0d4; font-size: 9px; color: #a09080; }
</style>
</head>
<body>

    <div class="eyebrow">NamibWay API</div>
    <h1>Partner Quickstart</h1>
    <p class="subtitle">Query NamibWay's listing inventory and check live availability — the same middleware that routes bookings to each property's connector (ResConnect, NightsBridge, hopeCloud, or NamibWay's own booking system).</p>

    <h2>1. Authentication</h2>
    <p>Every request needs the bearer token NamibWay sent you, in the <code>Authorization</code> header:</p>
    <div class="box mono">Authorization: Bearer {YOUR_TOKEN}</div>
    <p>There's no self-serve signup — if your token stops working or you need a new one, contact NamibWay.</p>

    <h2>2. Base URL</h2>
    <div class="box mono">{{ $baseUrl }}</div>

    <h2>3. Endpoints</h2>
    <table>
        <thead>
            <tr><th>Endpoint</th><th>What it does</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="mono">GET /api/v1/listings</td>
                <td>Published listings, paginated &amp; filterable (type, region, keyword, budget, min_rating).</td>
            </tr>
            <tr>
                <td class="mono">GET /api/v1/listings/{slug}</td>
                <td>A single listing's full detail.</td>
            </tr>
            <tr>
                <td class="mono">GET /api/v1/listings/{slug}/availability</td>
                <td>Live availability for check_in/check_out dates. Returns <code>booking_mode: "connector"</code> with real room types &amp; rates, or <code>"inquiry"</code> if the property has no live connector.</td>
            </tr>
        </tbody>
    </table>

    <h2>4. Example request</h2>
    <div class="endpoint">
        <span class="method">GET</span> {{ $baseUrl }}/api/v1/listings?region=Erongo&amp;type=accommodation<br>
        Authorization: Bearer {YOUR_TOKEN}<br>
        Accept: application/json
    </div>

    <h2>5. Full reference</h2>
    <p>Every endpoint, all parameters, response schemas, and a live "try it out" tester:</p>
    <div class="box mono">{{ $baseUrl }}/docs</div>

    <div class="footer">
        NamibWay — The smartest way to experience Namibia. Questions about this API? Contact NamibWay directly.
    </div>

</body>
</html>
