<x-payments.layout title="Redirecting to PayGate...">
    <div class="result">
        <p>Redirecting to the payment page&hellip;</p>
    </div>

    <form id="paygate-form" method="POST" action="{{ $processUrl }}">
        <input type="hidden" name="PAY_REQUEST_ID" value="{{ $payRequestId }}">
        <input type="hidden" name="CHECKSUM" value="{{ $checksum }}">
        <noscript>
            <button type="submit" style="margin-top:1rem">Continue to payment</button>
        </noscript>
    </form>

    <script>document.getElementById('paygate-form').submit();</script>
</x-payments.layout>
