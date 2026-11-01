@extends('pdf.layout')

@section('title', 'Signed consent ' . $reference)
@section('doc_label', 'SIGNED CONSENT RECORD')

@section('content')
    <h1>Signed consent record</h1>
    <div class="muted mono">{{ $reference }}</div>

    <h2>Consent letter</h2>
    <div class="box">{!! nl2br(e($letter)) !!}</div>

    <h2>Signing evidence</h2>
    <table class="kv">
        <tr>
            <td class="k">Customer</td>
            <td class="v">{{ $customer_name }}</td>
            <td class="k">Retailer</td>
            <td class="v">{{ $retailer }}</td>
        </tr>
        <tr>
            <td class="k">Purpose</td>
            <td class="v">{{ $purpose }}</td>
            <td class="k">Signed at</td>
            <td class="v">{{ $signed_at }}</td>
        </tr>
        <tr>
            <td class="k">Signing request</td>
            <td class="v mono">{{ $sign_request ?? '-' }}</td>
            <td class="k">Signed document ref</td>
            <td class="v mono">{{ $signed_doc ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Consent taken</td>
            <td class="v">{{ $collected_at ?? '-' }} {{ $collected_via ? '(' . $collected_via . ')' : '' }}</td>
            <td class="k">Text version</td>
            <td class="v">{{ $version }}</td>
        </tr>
    </table>

    <p class="mono muted" style="margin-top: 8px;">SHA-256 of the signed text: {{ $hash }}</p>
@endsection

@section('note')
    This is VerifyHub's copy of the signed consent letter with its signing evidence. The document signed with the customer's e-Signature is held by the e-Sign provider under the signed document reference shown above.
@endsection
