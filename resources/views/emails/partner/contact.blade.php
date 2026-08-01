<x-mail::message>
{!! nl2br(e($bodyText)) !!}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
