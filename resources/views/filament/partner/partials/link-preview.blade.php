{{--
    What a shared link to booking.namibway.com looks like in a chat.

    The panel renders Filament's own layout, not app.blade.php, so it carried
    no Open Graph tags at all — a link pasted into WhatsApp came out as a bare
    URL. That matters here more than on an ordinary admin screen: this address
    *is* the product's front door, and it gets sent to lodge owners.

    Absolute URLs, because a crawler has no page to resolve a relative one
    against. The image is the brand's, shared with the travel site until the
    booking product has one of its own — the same mark either way, which is the
    point of not inventing a second name for it.
--}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }} Booking">
<meta property="og:title" content="{{ config('app.name') }} Booking — the booking system your lodge runs">
<meta property="og:description" content="Calendar, rates, arrivals and guests in one place. Take a booking at the desk, on the phone or from namibway.com, and see the same book everywhere.">
<meta property="og:image" content="{{ asset('images/og-image.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="{{ url()->current() }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ config('app.name') }} Booking — the booking system your lodge runs">
<meta name="twitter:description" content="Calendar, rates, arrivals and guests in one place.">
<meta name="twitter:image" content="{{ asset('images/og-image.png') }}">
