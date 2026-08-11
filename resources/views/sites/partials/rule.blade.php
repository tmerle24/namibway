{{--
  The signature: a numbered, letterspaced micro-label over a hairline with an
  accent tick at its left end. Same numbering as the printed flyers, so the two
  read as one house.
--}}
@if ($number)
    <div class="rule">
        <span class="rule__num">{{ $number }}</span>
        <span class="rule__label">{{ $label }}</span>
    </div>
@endif
