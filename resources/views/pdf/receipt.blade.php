@extends('pdf.layout')

@section('title', 'Receipt ' . $reference)
@section('doc_label', 'VERIFICATION RECEIPT')

@section('content')
    <h1>Verification receipt</h1>
    <div class="muted mono">{{ $reference }}</div>

    <h2>Details</h2>
    <table class="kv">
        <tr>
            <td class="k">Service</td>
            <td class="v">{{ $service }}</td>
            <td class="k">Date</td>
            <td class="v">{{ $created_at }}</td>
        </tr>
        <tr>
            <td class="k">Checked</td>
            <td class="v mono">{{ $input_masked }}</td>
            <td class="k">Status</td>
            <td class="v"><span class="badge s-{{ $status }}">{{ $status }}</span></td>
        </tr>
        <tr>
            <td class="k">Retailer</td>
            <td class="v">{{ $retailer }}</td>
            <td class="k">Provider reference</td>
            <td class="v mono">{{ $provider_ref ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Amount charged</td>
            <td class="v">Rs {{ number_format($charged, 2) }}</td>
            @if ($audience === 'admin')
                <td class="k">Provider cost</td>
                <td class="v">Rs {{ number_format($provider_cost, 2) }}</td>
            @else
                <td></td><td></td>
            @endif
        </tr>
    </table>

    @if ($failure_reason)
        <p class="red"><b>Reason:</b> {{ $failure_reason }}</p>
    @endif

    @if (count($details) > 0)
        <h2>Result</h2>
        <table class="kv">
            @foreach ($details as $label => $value)
                <tr>
                    <td class="k">{{ $label }}</td>
                    <td class="v" colspan="3">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
