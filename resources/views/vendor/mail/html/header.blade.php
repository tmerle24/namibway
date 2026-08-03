@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- alt text is the fallback when the image doesn't load — most email
clients render it in place of a broken/blocked image automatically. --}}
<img src="{{ asset('images/namibway-logo-dark.png') }}" class="logo" alt="{{ trim($slot) }}">
</a>
</td>
</tr>
