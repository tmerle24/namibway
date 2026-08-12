<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Georgia, serif; color: #1a1a1a; max-width: 600px; margin: 0 auto; padding: 24px; }
        .logo { font-size: 22px; font-weight: bold; color: #b45309; margin-bottom: 32px; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        h2 { font-size: 15px; margin: 28px 0 8px; }
        p { line-height: 1.7; margin-bottom: 16px; }
        ul { line-height: 1.7; padding-left: 20px; }
        .cta {
            display: inline-block; margin: 24px 0; padding: 14px 28px;
            background: #b45309; color: #ffffff !important; text-decoration: none;
            border-radius: 6px; font-size: 16px;
        }
        .footer { margin-top: 40px; font-size: 13px; color: #666; border-top: 1px solid #e5e5e5; padding-top: 20px; }
    </style>
</head>
<body>

<div class="logo">NamibWay</div>

<h1>Your website draft is ready</h1>

<p>We have built a first version of a website for {{ $site->name }}, using what we
already hold about the business. Nothing is public yet — this link is private, and
the page tells search engines to ignore it until you say otherwise.</p>

{{-- The colour is stated three times: in the class, inline on the anchor, and
     again on a span inside it. That is not belt and braces, it is what Gmail
     needs. It restyles `a` after the document's stylesheet *and* after the
     anchor's own inline colour — the button rendered blue on brown twice
     before this. A span inside the anchor is the one element it leaves
     alone. --}}
<p><a class="cta" href="{{ $url }}"
      style="display:inline-block;margin:24px 0;padding:14px 28px;background:#b45309;color:#ffffff;text-decoration:none;border-radius:6px;font-size:16px"><span
            style="color:#ffffff;text-decoration:none">Look at the draft</span></a></p>

@if ($gaps !== [] || $empty !== [])
    <h2>What we could not fill in</h2>

    <p>This is the useful part. Everything below is waiting on something we do not
    have, rather than on something that went wrong.</p>

    <ul>
        @foreach ($gaps as $gap)
            <li>{{ ucfirst($gap) }}.</li>
        @endforeach

        @foreach ($empty as $block)
            <li>The <strong>{{ str_replace('_', ' ', $block) }}</strong> section has nothing in it yet, so it is not shown.</li>
        @endforeach
    </ul>

    <p>Send us the missing pieces — photographs, opening hours, prices — and we put
    them in. That is part of the monthly fee, not an extra invoice.</p>
@endif

<div class="footer">
    <p>This link works without a password, so treat it like one. Anyone who has it can
    see the draft.</p>
    <p>NamibWay · namibway.com</p>
</div>

</body>
</html>
