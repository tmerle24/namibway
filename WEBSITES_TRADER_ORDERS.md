# Trader Orders

Section for `WEBSITES.md`. Covers QR-initiated ordering for small traders (street food, crafts, informal accommodation) who are listed on NamibWay and hold a mini-website in the Business Owner Website System.

---

## 1. Purpose

Make small traders findable by travellers and give them an order channel they cannot build themselves. The mini-website is the surface; the order form is the transaction layer on top of it.

This is a **feature of the website product**, not a revenue line. No commission is charged on trader orders. It exists to justify the N$399/month subscription and to put inventory into the platform that no competitor has.

---

## 2. Regulatory boundary — non-negotiable

**NamibWay never takes custody of trader funds.**

Under the Payment System Management Act, 2023, collecting payer funds and disbursing them to third parties is a licensed payment service (payment facilitation / e-money issuance) requiring Bank of Namibia authorisation under PSD-1, a trust account, capital cover and FIA/KYC obligations per merchant. None of that is in scope.

Consequences that must hold in code:

- The payment link always resolves to the **trader's own** payment instrument (PayToday business profile, bank merchant account, or cash on handover).
- NamibWay renders the link. NamibWay does not originate, hold, split or forward the payment.
- No NamibWay-held balance, wallet, escrow or "pending payout" concept for traders. If such a field is ever proposed, it is a licensing question, not a schema question.
- All customer-facing surfaces name the **trader as seller** and NamibWay as intermediary. Most traders are not VAT-registered and must not appear to sell through NamibWay.

Same organising principle as `PAYMENTS.md`: **Recording, not Collecting.** Trader orders are recording-only in every settlement model.

---

## 3. Actors

| Actor | Role |
|---|---|
| Traveller | Scans QR or opens listing, places order |
| Trader | Confirms availability, receives payment directly, hands over goods |
| NamibWay | Renders order form, records order state, relays notifications |
| Charnette (agency) | Onboards trader, sets up payment instrument, configures order form |

Onboarding is the real product here. A trader without a payment instrument cannot be listed with ordering enabled — and that is precisely the trader who most needs the help. Setting the trader up on PayToday (or a bank merchant profile) is an agency task, performed on the trader's behalf, in the same model as website editing.

---

## 4. Order lifecycle

Deliberately parallel to the Inquiry / Reservation split. An order is an **enquiry until the trader confirms**. Payment is requested only after confirmation.

```
                  ┌─────────────────────┐
                  │ pending_confirmation│  ← traveller submitted
                  └──────────┬──────────┘
             confirm │  decline │  timeout
        ┌────────────┘          │        └──────────────┐
        ▼                       ▼                       ▼
┌───────────────────┐    ┌──────────┐           ┌───────────┐
│ awaiting_payment  │    │ declined │           │  expired  │
└─────────┬─────────┘    └──────────┘           └───────────┘
          │ payment attested / cash on handover
          ▼
   ┌────────────┐        ┌───────────┐
   │    paid    │───────▶│ fulfilled │
   └────────────┘        └───────────┘
          │
          ▼
   ┌────────────┐
   │ cancelled  │
   └────────────┘
```

**Statuses**

| Status | Meaning | Exit |
|---|---|---|
| `pending_confirmation` | Submitted by traveller, trader not yet responded | confirm / decline / expiry timer |
| `awaiting_payment` | Trader confirmed availability, payment link issued | payment attested, or cancel |
| `paid` | Trader has attested receipt of payment (see §5) | fulfilled / cancelled |
| `fulfilled` | Goods handed over or delivered | terminal |
| `declined` | Trader unavailable / sold out | terminal |
| `expired` | No trader response within window | terminal |
| `cancelled` | Cancelled after confirmation by either side | terminal |

**Expiry window** is per fulfilment mode, not global. Same-day pickup of prepared food expires in minutes; a craft item collected next week can hold for hours. Default: 30 min for `pickup_same_day`, 24 h otherwise. Configurable per listing.

All transitions go through a single write service (`TraderOrderWriteService`), consistent with the rule that all inventory mutations pass through one write class. Transitions are idempotent and carry an actor and a reason.

---

## 5. Payment state is attested, not observed

Because NamibWay is not in the money flow, **the platform cannot verify that payment occurred.** `paid` means *the trader said so*, or the handover was cash. This must be explicit in the model and in the UI — do not render it as a verified state.

- `payment_state` is stored separately from `status`, with values `not_required`, `link_issued`, `attested_by_trader`, `attested_by_traveller`, `disputed`.
- Attestation records who attested and when.
- Disputes are resolved between traveller and trader. NamibWay has no financial lever — no chargeback, no withholding, no refund path. This is the accepted cost of staying unlicensed and must be stated in the terms shown at order submission.

**Open decision:** whether PayToday (or the acquiring bank) exposes a callback that would let the platform observe settlement rather than rely on attestation. If it does, `payment_state` gains an `observed` value and the trust model improves substantially. To be checked with PayToday before the order form is built — it changes the design, not just the wiring.

---

## 6. Notifications

WhatsApp is the primary channel; it is the de-facto messaging standard in Namibia and supports a confirm/decline deep link, which SMS does not. SMS is fallback only, for traders without WhatsApp.

- Outbound goes through the existing queue (Redis / Horizon), never inline in the request cycle.
- Confirm/decline links are signed, single-use, and expire with the order.
- **Sandbox tenants must never emit outbound communications.** The existing demo-tenant flag governs this; trader orders are no exception. A demo trader order transitions state and renders UI, and sends nothing.
- Escalation: if no trader response before expiry, the traveller is notified that the order lapsed and shown alternative nearby listings. Never leave a traveller waiting on a silent order.

---

## 7. Fulfilment modes

| Mode | MVP | Notes |
|---|---|---|
| `pickup` | ✅ | On-site handover. Cash or link, both allowed. Simplest and safest — ship this first. |
| `delivery_local` | Phase 2 | Trader delivers within their own locale. Delivery radius and fee are trader-configured. |
| `shipping_international` | ❌ out of scope | See below. |

**International shipping is excluded from the MVP.** Namibian craft goods frequently contain animal materials (horn, bone, leather, hide, feathers, shell). Export to the EU/US can engage CITES permits and veterinary import rules, and a platform that offers shipping places itself in that chain. Traders may arrange shipping privately; the platform will not offer it as an option, and the order form must not collect a foreign shipping address.

---

## 8. Entry points

- **QR code** — printed, per listing. Resolves to `/{tenant}/order` on the trader's mini-site. Codes are generated per listing, not per order, and must remain stable once printed (a reprint is a real-world cost to the trader).
- **Listing page** on namibway.com — order CTA where ordering is enabled.
- **Mini-website** — order block placed in the page composition like any other block.

Consistent with the existing rule: booking/ordering surfaces are **widgets over live data**, never content imported into the CMS. The order form reads live product and availability state; it does not read the CMS copy of it.

---

## 9. Products and publishability

Trader products are a light catalogue: name, short description, price, optional image, available/unavailable toggle. No variants, no stock counts in the MVP — availability is binary and trader-controlled.

`ContentSource` governs publishability as everywhere else. Directory-scraped material is non-publishable and must never appear on a trader's site or in an order form. Trader product content is either trader-supplied or agency-produced (Charnette), both publishable.

---

## 10. Build sequencing

Trader orders depend on the block library and renderer from Website Prompt 1. **Do not start before that lands** — otherwise the renderer gets built twice.

Suggested order once the renderer exists:

1. Product catalogue + admin editing (agency model first, as with website editing)
2. Order form block + submission + `pending_confirmation`
3. Notification relay + confirm/decline links + expiry job
4. Payment link issuance + attestation UI
5. QR generation per listing

Estimated one to two weeks of build on top of a working renderer.

---

## 11. Open decisions

- PayToday callback availability (§5) — determines attested vs. observed payment state
- Whether trader onboarding to a payment instrument is bundled into the N$399 subscription or priced as a one-off setup service
- Whether declined orders should auto-suggest alternative traders, and whether that suggestion is ranked or random (ranking creates a preference the platform has to justify)
