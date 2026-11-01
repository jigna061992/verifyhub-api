<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Verification;
use App\Services\KycService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * PDF documents, made on the fly and sent only after the login/role/ownership checks.
 * Nothing is saved on disk, so there is no file that could be reached without logging in.
 *
 *  Retailer (own data only): KYC report, signed consent record, verification receipt
 *  Super Admin: the same KYC report and receipt but WITHOUT personal data (no customer name, no provider result, no consent text)
 */
class ReportPdfController extends Controller
{
    // Only these result fields may appear on a retailer receipt
    private const RECEIPT_FIELDS = [
        'PAN'  => ['name' => 'Name on PAN', 'pan_status' => 'PAN status'],
        'BANK' => ['account_holder' => 'Account holder', 'account_status' => 'Account status'],
        'GST'  => ['legal_name' => 'Legal name', 'gst_status' => 'GST status'],
        'AADHAAR' => ['name' => 'Name as per Aadhaar'],
        'ESIGN'   => ['signed_at' => 'Signed at', 'signed_doc_ref' => 'Signed document reference'],
    ];

    private const CONSENT_TYPES = [
        'collection'    => 'Consent taken by the retailer',
        'signed_letter' => 'Signed consent letter (e-Sign)',
    ];

    public function __construct(private KycService $kyc)
    {
    }

    /* ------------------------------ Retailer ------------------------------ */

    public function kycReport(Request $request, int $id)
    {
        $case = $this->ownCase($request, $id);

        abort_if($case->status !== 'completed', 422, 'The KYC report is available only after the KYC is completed.');

        return $this->render($request, 'pdf.kyc-report', $this->kycData($case, 'retailer'), 'kyc-report-' . $this->short($case->reference), 'kyc.report.downloaded', 'kyc_case', $case->id);
    }

    public function kycConsent(Request $request, int $id)
    {
        $case   = $this->ownCase($request, $id);
        $signed = $case->consents->firstWhere('type', 'signed_letter');
        $esign  = $case->steps->firstWhere('step', 'esign');

        abort_if(! $signed || ! $esign || $esign->status !== 'success', 422, 'The signed consent is available only after the customer has e-signed.');

        $collection = $case->consents->firstWhere('type', 'collection');

        $data = [
            'audience'      => 'retailer',
            'reference'     => $case->reference,
            'retailer'      => $case->retailer?->business_name,
            'customer_name' => $case->customer_name,
            'purpose'       => config('kyc.purposes')[$signed->purpose] ?? $signed->purpose,
            'letter'        => (string) $signed->text,
            'version'       => $signed->text_version,
            'hash'          => $signed->text_hash,
            'signed_at'     => $this->date($signed->accepted_at),
            'sign_request'  => $signed->provider_ref,
            'signed_doc'    => $esign->result['signed_doc_ref'] ?? null,
            'collected_at'  => $this->date($collection?->accepted_at),
            'collected_via' => $collection ? str_replace('_', ' ', $collection->channel) : null,
        ];

        return $this->render($request, 'pdf.consent', $data, 'signed-consent-' . $this->short($case->reference), 'kyc.consent.downloaded', 'kyc_case', $case->id);
    }

    public function receipt(Request $request, int $id)
    {
        $retailer = $this->retailer($request);

        $v = Verification::with(['service:id,code,name', 'retailer:id,business_name'])
            ->where('retailer_id', $retailer->id)
            ->findOrFail($id);

        abort_if($v->status !== 'success', 422, 'A receipt is available only for successful checks.');

        $details = [];
        foreach (self::RECEIPT_FIELDS[$v->service?->code] ?? [] as $key => $label) {
            if (isset($v->result[$key])) {
                $details[$label] = (string) $v->result[$key];
            }
        }

        $data = $this->receiptData($v, 'retailer') + ['details' => $details];

        return $this->render($request, 'pdf.receipt', $data, 'receipt-' . $this->short($v->reference), 'verification.receipt.downloaded', 'verification', $v->id);
    }

    /* ------------------------------ Super Admin ------------------------------ */

    public function adminKycReport(Request $request, int $id)
    {
        $case = KycCase::with(['steps', 'consents', 'retailer:id,business_name'])->findOrFail($id);

        abort_if($case->status === 'in_progress', 422, 'This KYC is still running. The report is available once it is closed.');

        return $this->render($request, 'pdf.kyc-report', $this->kycData($case, 'admin'), 'kyc-report-' . $this->short($case->reference), 'kyc.report.downloaded', 'kyc_case', $case->id);
    }

    public function adminReceipt(Request $request, int $id)
    {
        $v = Verification::with(['service:id,code,name', 'retailer:id,business_name'])->findOrFail($id);

        abort_if($v->status === 'pending', 422, 'This check is still running.');

        return $this->render($request, 'pdf.receipt', $this->receiptData($v, 'admin') + ['details' => []], 'receipt-' . $this->short($v->reference), 'verification.receipt.downloaded', 'verification', $v->id);
    }

    /* ------------------------------ Data builders ------------------------------ */

    private function kycData(KycCase $case, string $audience): array
    {
        $case->loadMissing(['steps', 'consents', 'retailer:id,business_name']);

        $isRetailer = $audience === 'retailer';

        // The retailer view reuses the same "what may be shown" rules as the KYC screen
        $public = $isRetailer ? collect($this->kyc->present($case)['steps'])->keyBy('step') : collect();

        $steps = $case->steps->map(function ($s) use ($public, $isRetailer) {
            $details = [];

            foreach (($public[$s->step]['result'] ?? []) as $key => $value) {
                $details[$this->label($key)] = $this->show($value);
            }

            return [
                'label'          => KycService::LABELS[$s->step] ?? $s->step,
                'status'         => $s->status,
                'input_masked'   => $s->input_masked,
                'attempts'       => (int) $s->attempts,
                'completed_at'   => $this->date($s->completed_at),
                'failure_reason' => $s->failure_reason,
                'details'        => $isRetailer ? $details : [],
            ];
        })->values()->all();

        $consents = $case->consents->map(fn ($c) => [
            'type'        => self::CONSENT_TYPES[$c->type] ?? $c->type,
            'purpose'     => config('kyc.purposes')[$c->purpose] ?? $c->purpose,
            'version'     => $c->text_version,
            'hash'        => substr((string) $c->text_hash, 0, 16) . '...',
            'channel'     => str_replace('_', ' ', $c->channel),
            'accepted_at' => $this->date($c->accepted_at),
            'ip'          => $isRetailer ? null : $c->ip_address,
        ])->values()->all();

        return [
            'audience'       => $audience,
            'reference'      => $case->reference,
            'status'         => $case->status,
            'retailer'       => $case->retailer?->business_name,
            'customer_name'  => $isRetailer ? $case->customer_name : null,
            'mobile_masked'  => 'XXXXXX' . $case->customer_mobile_last4,
            'price'          => (float) $case->price,
            'charged'        => $case->status === 'completed' ? (float) $case->price : 0.0,
            'provider_cost'  => $isRetailer ? null : (float) $case->provider_cost,
            'failure_reason' => $case->failure_reason,
            'started_at'     => $this->date($case->created_at),
            'closed_at'      => $this->date($case->closed_at),
            'steps'          => $steps,
            'consents'       => $consents,
        ];
    }

    private function receiptData(Verification $v, string $audience): array
    {
        $charged = $v->status === 'success';

        return [
            'audience'       => $audience,
            'reference'      => $v->reference,
            'provider_ref'   => $v->provider_ref,
            'retailer'       => $v->retailer?->business_name,
            'service'        => $v->service?->name,
            'input_masked'   => $v->input_masked,
            'status'         => $v->status,
            'charged'        => $charged ? (float) $v->price : 0.0,
            'provider_cost'  => $audience === 'admin' ? (float) $v->provider_cost : null,
            'failure_reason' => $v->failure_reason,
            'created_at'     => $this->date($v->created_at),
        ];
    }

    /* ------------------------------ Helpers ------------------------------ */

    private function render(Request $request, string $view, array $data, string $name, string $action, string $entityType, int $entityId)
    {
        $pdf = Pdf::loadView($view, $data + ['generatedAt' => now()->format('d M Y, h:i A')])->setPaper('a4');

        Audit::log($request, $action, $entityType, $entityId, ['audience' => $data['audience']]);

        return $pdf->download($name . '.pdf')->header('Cache-Control', 'no-store, private');
    }

    // Only the logged-in retailer's own cases. Anything else is a 404.
    private function ownCase(Request $request, int $id): KycCase
    {
        return KycCase::with(['steps', 'consents', 'retailer:id,business_name'])
            ->where('retailer_id', $this->retailer($request)->id)
            ->findOrFail($id);
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }

    private function short(string $reference): string
    {
        return strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', $reference), 0, 8));
    }

    private function date($value): ?string
    {
        return $value?->format('d M Y, h:i A');
    }

    private function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $value === null || $value === '' ? '-' : (string) $value;
    }
}
