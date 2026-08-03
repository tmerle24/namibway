<x-mail::message>
{!! nl2br(e($bodyText)) !!}

{!! nl2br(e($signature)) !!}

@if(count($listingLinks) > 0)
---

**Your listing{{ count($listingLinks) > 1 ? 's' : '' }} on NamibWay:**

@foreach($listingLinks as $link)
@if($link['url'])
- [{{ $link['name'] }}]({{ $link['url'] }}){{ $link['published'] ? '' : ' (preview — not public yet)' }}
@else
- {{ $link['name'] }} (not public yet)
@endif
@endforeach
@endif

@if($claimUrl)
Haven't claimed your listing yet? [Claim it for free]({{ $claimUrl }}).
@endif
</x-mail::message>
