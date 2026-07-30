<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Georgia, serif; color: #1a1a1a; max-width: 600px; margin: 0 auto; padding: 24px; }
        .logo { font-size: 22px; font-weight: bold; color: #b45309; margin-bottom: 32px; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        p { line-height: 1.7; margin-bottom: 16px; }
        .cta {
            display: inline-block;
            margin: 24px 0;
            padding: 14px 28px;
            background: #b45309;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 16px;
        }
        .footer { margin-top: 40px; font-size: 13px; color: #666; border-top: 1px solid #e5e5e5; padding-top: 20px; }
    </style>
</head>
<body>

<div class="logo">NamibWay</div>

<h1>We've prepared a free listing for {{ $partner->name }}</h1>

<p>Dear team at {{ $partner->name }},</p>

<p>
    We are building <strong>NamibWay</strong> — a new travel platform focused exclusively on
    Namibia, designed to connect independent travellers with the best lodges, camps,
    activities and restaurants the country has to offer.
    Our goal is to become the go-to planning tool for visitors to Namibia, powered by
    an AI travel companion that builds personalised, bookable itineraries.
</p>

<p>
    We have prepared a <strong>free listing for {{ $partner->name }}</strong> based on
    publicly available information.
    To make your listing as accurate and attractive as possible, we'd like to invite you
    to <strong>claim it and take full control</strong> — update your description, add photos,
    set your rates, and decide how enquiries reach you.
</p>

@if($listing)
<p><strong>What we've listed so far:</strong></p>
<ul>
    <li>
        {{ $listing->getTranslation('name', 'en') }} ({{ ucfirst($listing->type->value) }}@if($listing->region)
, {{ $listing->region }}
@endif
)
    </li>
    <li>
        @if($listing->image)
            1 photo
        @else
            No photo yet
        @endif
        @if(! empty($listing->gallery))
            , {{ count($listing->gallery) }} more in the gallery
        @endif
    </li>
    @if($listing->address)
    <li>Address: {{ $listing->address }}</li>
    @endif
    @if($listing->phone)
    <li>Phone: {{ $listing->phone }}</li>
    @endif
</ul>
@endif

@if($listingUrl)
<p><a href="{{ $listingUrl }}">See how it looks on namibway.com →</a></p>
@endif

<a class="cta" href="{{ $claimUrl }}">View &amp; claim your listing →</a>

<p>
    There is no cost to claim or maintain a basic listing on NamibWay.
    Once confirmed, you decide whether to accept booking enquiries through the platform.
    If you would prefer not to be listed, simply reply to this email and we will remove
    your property within 24 hours — no questions asked.
</p>

<p>
    We'd love to hear your thoughts and are happy to jump on a quick call if you have
    questions. Thank you for considering NamibWay as a partner.
</p>

<p>
    Warm regards,<br>
    The NamibWay Team<br>
    <a href="https://namibway.com">namibway.com</a>
</p>

<div class="footer">
    You are receiving this email because {{ $partner->name }} appears in public tourism
    directories for Namibia. Not your business, or would rather not be listed?
    <a href="{{ $declineUrl }}">Remove my listing</a> — one click, no reply needed.
</div>

</body>
</html>
