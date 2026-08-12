@php
    use App\Enums\PaymentIntentStatus;
    use App\Support\Money;
    use App\Support\UiTranslations as T;

    /** @var \App\Models\PaymentIntent $intent */
    /** @var \App\Models\Reservation|null $reservation */
    /** @var \App\Services\Payments\DTOs\FolioStatement|null $statement */

    [$mark, $tone, $titleKey, $bodyKey] = match ($intent->status) {
        PaymentIntentStatus::Succeeded => ['✓', 'ok', 'paidTitle', 'paidBody'],
        PaymentIntentStatus::Failed => ['✕', 'bad', 'failedTitle', 'failedBody'],
        PaymentIntentStatus::Abandoned => ['–', 'wait', 'abandonedTitle', 'abandonedBody'],
        // Pending is a real answer, not an error: a bank that has not answered
        // yet is neither a success nor a failure, and telling a guest their
        // payment failed because we are still waiting is worse than waiting.
        PaymentIntentStatus::Pending => ['…', 'wait', 'pendingTitle', 'pendingBody'],
    };
@endphp

<x-payments.layout :title="T::get('payments.'.$titleKey)">
    <div class="result">
        <div class="result__mark {{ $tone }}">{{ $mark }}</div>
        <h1>{{ T::get('payments.'.$titleKey) }}</h1>
        <p class="lede">{{ T::get('payments.'.$bodyKey) }}</p>
    </div>

    @if ($statement && $reservation)
        <dl>
            <dt>{{ T::get('payments.booking') }}</dt>
            <dd>{{ $reservation->reference }}</dd>

            <dt>{{ T::get('payments.paidSoFar') }}</dt>
            <dd>{{ Money::format($statement->paid(), $statement->currency()) }}</dd>

            @if ($statement->balance() !== null)
                <dt>{{ T::get('payments.stillToPay') }}</dt>
                <dd>{{ Money::format(max(0, $statement->balance()), $statement->currency()) }}</dd>
            @endif
        </dl>
    @endif

    {{-- Offered only where trying again can change anything. A settled attempt
         cannot be paid twice, and a link that invites it would produce exactly
         the double charge the unique index exists to prevent. --}}
    @if ($intent->status === PaymentIntentStatus::Failed || $intent->status === PaymentIntentStatus::Abandoned)
        <p class="hint">{{ T::get('payments.tryAgain') }} — {{ config('app.name') }}</p>
    @endif
</x-payments.layout>
