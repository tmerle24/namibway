<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a traveller is actually asking for.
 *
 * A request has always been assumed to be a booking request — somebody wanting
 * a room for a range of nights. A restaurant breaks that in two ways at once: a
 * table is a date *and a time* with no departure, and an order is neither, it is
 * a list of things with quantities.
 *
 * This is deliberately a property of the **request**, not of the listing's
 * vertical. `BOOKING_BEYOND_ROOMS.md` §6 warns against the core learning to ask
 * "which vertical am I serving?"; asking "what shape is this request?" is a
 * different and answerable question, and it is the one every reader downstream
 * actually has — which fields to render, which to validate, and whether the
 * thing can become a stay on a nights calendar.
 */
enum InquiryKind: string implements HasColor, HasLabel
{
    /**
     * A room, a vehicle, a seat on a tour — a stay-shaped request with a start
     * and an end. Everything that existed before restaurants had a form of
     * their own, and the default for every listing type but one.
     */
    case Booking = 'booking';

    /** A table at a restaurant: one date, one time, a number of people. */
    case TableReservation = 'table_reservation';

    /** Food ordered from the menu: items and quantities, no date, no party. */
    case Order = 'order';

    public function getLabel(): string
    {
        return match ($this) {
            self::Booking => 'Booking request',
            self::TableReservation => 'Table reservation',
            self::Order => 'Order',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Booking => 'info',
            self::TableReservation => 'warning',
            self::Order => 'success',
        };
    }

    /**
     * Whether this request can become a `Reservation` on the property's
     * calendar once it is confirmed.
     *
     * Only a booking can. The other two are real requests a partner answers,
     * but nothing about them allocates a unit for a range of nights, so
     * `StayPromoter` must skip them rather than fail on them — a failed
     * promotion raises an operational alert, and a confirmed table booking is
     * not an incident.
     *
     * Restaurant inventory (covers per sitting) is a genuine question and not
     * this one; when it is answered it will be a slot on the ARI calendar, and
     * this method is where that decision surfaces.
     */
    public function becomesAStay(): bool
    {
        return $this === self::Booking;
    }

    /** Whether this request carries line items rather than dates and guests. */
    public function hasItems(): bool
    {
        return $this === self::Order;
    }
}
