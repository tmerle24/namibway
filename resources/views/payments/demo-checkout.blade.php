@php
    use App\Support\Money;
    use App\Support\UiTranslations as T;

    /** @var \App\Models\PaymentIntent $intent */
    $reservation = $intent->reservation;
    $property = $reservation?->listing;
@endphp

<x-payments.layout :title="T::get('payments.heading')">
    <h1>{{ T::get('payments.heading') }}</h1>

    {{-- Unmissable, on every screen and in any screenshot. A page that takes a
         "pay" click without taking money must never be mistaken for one that
         does. --}}
    <div class="notice">{{ T::get('payments.demoNotice') }}</div>

    <p class="total">{{ Money::format($intent->charge_amount, $intent->charge_currency) }}</p>

    @if ($intent->isConverted())
        {{-- Said plainly rather than hidden: a guest whose booking is quoted
             in Namibian dollars and whose card is charged in rand deserves to
             read why before they click, not afterwards on a statement. --}}
        <p class="hint">
            {{ T::replace('payments.chargedIn', [
                'currency' => strtoupper($intent->charge_currency),
                'rate' => rtrim(rtrim(number_format($intent->exchange_rate, 4, '.', ''), '0'), '.'),
                'base' => '1 '.strtoupper($intent->currency),
            ]) }}
        </p>
    @endif

    <dl>
        <dt>{{ T::get('payments.for') }}</dt>
        <dd>{{ $intent->purpose->guestLabel() }}</dd>

        @if ($property)
            <dt>{{ T::get('payments.stay') }}</dt>
            <dd>{{ $property->name }}</dd>
        @endif

        @if ($reservation)
            <dt>{{ T::get('payments.booking') }}</dt>
            <dd>{{ $reservation->reference }}</dd>
        @endif
    </dl>

    {{-- Three buttons, because a demo that can only succeed exercises one
         branch and teaches the wrong shape. Declining and walking away are
         both things guests do, and both have to be producible on demand. --}}
    <div class="actions">
        <form method="POST" action="{{ route('payments.decide', $intent) }}">
            @csrf
            <input type="hidden" name="decision" value="pay">
            <button type="submit" class="btn--primary">{{ T::get('payments.pay') }}</button>
        </form>

        <form method="POST" action="{{ route('payments.decide', $intent) }}">
            @csrf
            <input type="hidden" name="decision" value="decline">
            <button type="submit" class="btn--quiet">{{ T::get('payments.decline') }}</button>
        </form>

        <form method="POST" action="{{ route('payments.decide', $intent) }}">
            @csrf
            <input type="hidden" name="decision" value="abandon">
            <button type="submit" class="btn--quiet">{{ T::get('payments.abandon') }}</button>
        </form>
    </div>
</x-payments.layout>
