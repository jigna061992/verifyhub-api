<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        /* dompdf supports CSS 2.1 only: tables for layout, no flexbox or grid */
        @page { margin: 34px 38px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 6px 6px 6px 0; }
        td { padding: 6px 6px 6px 0; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .brand td { border: 0; padding: 0; }
        .brand-name { font-size: 17px; font-weight: bold; color: #10704f; }
        .brand-sub { font-size: 8px; letter-spacing: 2px; color: #64748b; }
        .doc-label { text-align: right; font-size: 9px; color: #64748b; }
        .rule { border-top: 3px solid #10704f; margin: 8px 0 14px 0; }
        h1 { font-size: 16px; margin: 0 0 3px 0; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #475569; margin: 18px 0 6px 0; }
        .muted { color: #64748b; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9.5px; }
        .kv td { border: 0; padding: 3px 6px 3px 0; }
        .kv .k { width: 18%; color: #64748b; }
        .kv .v { width: 32%; font-weight: bold; }
        .badge { font-size: 9px; font-weight: bold; padding: 2px 7px; border-radius: 8px; background: #e2e8f0; color: #334155; text-transform: uppercase; }
        .s-completed, .s-success { background: #d1fae5; color: #065f46; }
        .s-failed { background: #fee2e2; color: #991b1b; }
        .s-pending, .s-in_progress, .s-awaiting, .s-processing { background: #fef3c7; color: #92400e; }
        .box { border: 1px solid #cbd5e1; padding: 10px 12px; background: #f8fafc; }
        .test { border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; padding: 6px 10px; margin-bottom: 10px; font-weight: bold; text-align: center; }
        .note { margin-top: 18px; font-size: 8.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .red { color: #b91c1c; }
    </style>
</head>
<body>
    @if (config('kyc.driver') === 'mock')
        <div class="test">TEST DOCUMENT - made with the mock provider. Not valid as a real KYC record.</div>
    @endif

    <table class="brand">
        <tr>
            <td>
                <div class="brand-name">VerifyHub</div>
                <div class="brand-sub">KYC VERIFICATION PLATFORM</div>
            </td>
            <td class="doc-label">
                @yield('doc_label')<br>
                Generated {{ $generatedAt }}
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    @yield('content')

    <div class="note">
        This document shows masked identifiers only. The full Aadhaar number, OTPs and passwords are never stored by VerifyHub.
        It is a system generated record and is valid only for the reference shown above.
        @yield('note')
    </div>
</body>
</html>
