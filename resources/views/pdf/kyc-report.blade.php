@extends('pdf.layout')

@section('title', 'KYC report ' . $reference)
@section('doc_label', $audience === 'admin' ? 'KYC AUDIT REPORT' : 'KYC REPORT')

@section('content')
    <h1>{{ $audience === 'admin' ? 'KYC audit report' : 'Complete KYC report' }}</h1>
    <div class="muted mono">{{ $reference }}</div>

    <h2>Summary</h2>
    <table class="kv">
        <tr>
            <td class="k">Status</td>
            <td class="v"><span class="badge s-{{ $status }}">{{ str_replace('_', ' ', $status) }}</span></td>
            <td class="k">Retailer</td>
            <td class="v">{{ $retailer }}</td>
        </tr>
        <tr>
            <td class="k">{{ $audience === 'admin' ? 'Customer mobile' : 'Customer' }}</td>
            <td class="v">
                @if ($audience === 'retailer') {{ $customer_name }}<br>@endif
                <span class="mono">{{ $mobile_masked }}</span>
            </td>
            <td class="k">Started</td>
            <td class="v">{{ $started_at }}</td>
        </tr>
        <tr>
            <td class="k">Bundle price</td>
            <td class="v">Rs {{ number_format($price, 2) }}</td>
            <td class="k">Closed</td>
            <td class="v">{{ $closed_at ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Charged</td>
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

    <h2>Verification steps</h2>
    <table>
        <tr>
            <th style="width: 17%">Step</th>
            <th style="width: 12%">Status</th>
            <th style="width: 20%">Checked</th>
            <th style="width: 18%">Completed</th>
            <th>Result</th>
        </tr>
        @foreach ($steps as $step)
            <tr>
                <td><b>{{ $step['label'] }}</b></td>
                <td><span class="badge s-{{ $step['status'] }}">{{ $step['status'] }}</span></td>
                <td class="mono">{{ $step['input_masked'] ?? '-' }}</td>
                <td>{{ $step['completed_at'] ?? '-' }}</td>
                <td>
                    @foreach ($step['details'] as $label => $value)
                        <span class="muted">{{ $label }}:</span> {{ $value }}<br>
                    @endforeach
                    @if ($step['failure_reason'])
                        <span class="red">{{ $step['failure_reason'] }}</span><br>
                    @endif
                    @if ($step['attempts'] > 0)
                        <span class="muted">{{ $step['attempts'] }} failed {{ $step['attempts'] === 1 ? 'try' : 'tries' }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <h2>Consent log</h2>
    @if (count($consents) === 0)
        <p class="muted">No consent recorded.</p>
    @else
        <table>
            <tr>
                <th style="width: 30%">Consent</th>
                <th style="width: 20%">Purpose</th>
                <th style="width: 10%">Version</th>
                <th style="width: 14%">Channel</th>
                <th>Accepted</th>
            </tr>
            @foreach ($consents as $c)
                <tr>
                    <td>{{ $c['type'] }}<br><span class="mono muted">{{ $c['hash'] }}</span></td>
                    <td>{{ $c['purpose'] }}</td>
                    <td>{{ $c['version'] }}</td>
                    <td>{{ $c['channel'] }}</td>
                    <td>
                        {{ $c['accepted_at'] }}
                        @if ($c['ip'])<br><span class="muted">IP {{ $c['ip'] }}</span>@endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection

@section('note')
    @if ($audience === 'admin')
        Audit copy: customer name, provider results and the consent text are not included.
    @endif
@endsection
