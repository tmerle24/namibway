@php
    /**
     * The tab icon, made from the business's own logo.
     *
     * Without this the browser asks the host for /favicon.ico and gets
     * NamibWay's, so a customer's own domain sat there under our mark. A
     * site with no logo gets nothing, which is the browser's blank page icon
     * rather than somebody else's.
     */
    $icon = $site->logoUrl(64);
    $touch = $site->logoUrl(180);
@endphp
@if ($icon)
    <link rel="icon" href="{{ $icon }}">
    <link rel="apple-touch-icon" href="{{ $touch }}">
@endif
