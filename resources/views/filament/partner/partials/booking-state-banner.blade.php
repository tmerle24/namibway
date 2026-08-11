@php
    use App\Filament\Partner\Support\SelectedProperty;
    use App\Services\Booking\BookingMailbox;

    // A render hook has no page behind it, so this resolves what it needs. The
    // partner is one relation off the signed-in user and the property is the
    // one the panel already resolved for this request.
    $bookingProperty = auth()->user()?->partner_id === null ? null : app(SelectedProperty::class)->current();
    $bookingNotice = $bookingProperty === null ? null : (new BookingMailbox($bookingProperty))->holdingNotice();
@endphp

{{--
    Where a booking's mail actually goes, said on every screen until it goes
    where the operator expects.

    This is not decoration. An operator who takes a real booking believing the
    guest was written to has been misled by silence, and they will only find
    out when the guest arrives with no confirmation. So the notice is present
    the whole time a property is not live, and disappears by itself the moment
    it is — nobody has to remember to turn it off.

    Deliberately quieter than the demo banner beside it: that one says "none of
    this is real", which is a bigger claim than "this is real but the post is
    being held".
--}}
@if ($bookingNotice !== null)
    <div class="nw-booking-banner" role="status">
        <span class="nw-booking-banner__tag">Mail</span>
        <span>{{ $bookingNotice }}</span>
    </div>

    <style>
        .nw-booking-banner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
            line-height: 1.35;
            color: rgb(12 74 110);
            background-color: rgb(var(--info-100, 224 242 254));
            border-bottom: 1px solid rgb(var(--info-500, 14 165 233));
        }

        .nw-booking-banner__tag {
            flex: none;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(255 255 255);
            background-color: rgb(2 132 199);
            border-radius: 0.25rem;
        }

        .dark .nw-booking-banner {
            color: rgb(224 242 254);
            background-color: rgb(12 74 110);
        }

        /* A printed arrivals list is carried around the property; whether the
           guest was written to is exactly what somebody reading it needs. */
        @media print {
            .nw-booking-banner {
                border-bottom: 1px solid #000;
            }
        }
    </style>
@endif
