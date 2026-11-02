<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Services\ReconciliationService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Super admin: checks whose provider answer is unknown (timeouts). Route group is already limited to super_admin.
class AdminReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $reconciliation)
    {
    }

    // GET /admin/reconciliations?status=open|resolved&search=&page=
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $status  = $request->query('status');
        $search  = trim((string) $request->query('search', ''));
        $overdue = (int) config('verification.reconciliation_overdue_hours', 24);

        $query = Reconciliation::with([
            'retailer:id,business_name',
            'service:id,code,name',
            'verification:id,input_masked,provider_ref,created_at',
            'resolver:id,name',
        ])->latest()->latest('id');

        if (in_array($status, ['open', 'resolved'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('retailer', fn ($r) => $r->where('business_name', 'like', "%{$search}%"))
                    ->orWhereHas('verification', fn ($v) => $v->where('input_masked', 'like', "%{$search}%"));

                // "VRF-000123" or "123" finds one request
                $digits = ltrim(preg_replace('/\D/', '', $search), '0');
                if ($digits !== '') {
                    $q->orWhere('verification_id', (int) $digits);
                }
            });
        }

        return $query->paginate($perPage)->through(function (Reconciliation $r) use ($overdue) {
            $hours = (int) $r->created_at->diffInHours(now());

            return [
                'id'              => $r->id,
                'verification_id' => $r->verification_id,
                'request_no'      => ReconciliationService::requestNo($r->verification_id),
                'business_name'   => $r->retailer?->business_name,
                'service_code'    => $r->service?->code,
                'service_name'    => $r->service?->name,
                'input_masked'    => $r->verification?->input_masked,
                'amount'          => (float) $r->amount,
                'status'          => $r->status,
                'outcome'         => $r->outcome,
                'reason'          => $r->reason,
                'note'            => $r->note,
                'resolved_by'     => $r->resolver?->name,
                'resolved_at'     => $r->resolved_at?->toIso8601String(),
                'created_at'      => $r->created_at?->toIso8601String(),
                'age_hours'       => $hours,
                'overdue'         => $r->status === 'open' && $hours >= $overdue,
                // Which fields the screen must ask for when the admin confirms a success
                'fields'          => $this->fieldsFor($r->service?->code),
            ];
        });
    }

    /**
     * POST /admin/reconciliations/{id}/resolve
     * { outcome: success|failed, note, name?, status? }
     *   success : the provider DID process the check. name (and optional status) are copied from the provider dashboard. The held money is charged.
     *   failed  : the provider did NOT process it. The held money goes back to the wallet.
     */
    public function resolve(Request $request, int $id)
    {
        $rec = Reconciliation::with('service:id,code')->findOrFail($id);

        $rules = [
            'outcome' => ['required', Rule::in(['success', 'failed'])],
            'note'    => ['required', 'string', 'min:5', 'max:200'],
        ];

        if ($request->input('outcome') === 'success') {
            $rules['name']   = ['required', 'string', 'min:2', 'max:150'];
            $rules['status'] = ['nullable', 'string', 'max:30'];
        }

        $data = $request->validate($rules, [
            'note.required' => 'Please write a short note. It is saved in the audit log.',
            'note.min'      => 'Please write a clear note (at least 5 characters). It is saved in the audit log.',
            'name.required' => 'Enter the name shown by the provider.',
        ]);

        $resolved = $this->reconciliation->resolve(
            $rec->id,
            $data['outcome'],
            ['name' => $data['name'] ?? null, 'status' => $data['status'] ?? null],
            trim($data['note']),
            $request->user()
        );

        // The provider name is personal data: it is NOT written to the audit log, only the decision and the note
        Audit::log($request, 'reconciliation.resolved', 'verification', $resolved->verification_id, [
            'reconciliation_id' => $resolved->id,
            'retailer_id'       => $resolved->retailer_id,
            'outcome'           => $resolved->outcome,
            'amount'            => (float) $resolved->amount,
            'note'              => $resolved->note,
        ]);

        return response()->json([
            'message' => $resolved->outcome === 'success'
                ? 'Confirmed. The retailer was charged and the result was saved.'
                : 'Closed. The held amount was returned to the retailer.',
        ]);
    }

    private function fieldsFor(?string $code): array
    {
        return match ($code) {
            'PAN'  => ['name_label' => 'Name on PAN', 'status_label' => 'PAN status', 'status_default' => 'VALID'],
            'BANK' => ['name_label' => 'Account holder name', 'status_label' => 'Account status', 'status_default' => 'ACTIVE'],
            'GST'  => ['name_label' => 'Legal name', 'status_label' => 'GST status', 'status_default' => 'ACTIVE'],
            default => ['name_label' => 'Name', 'status_label' => 'Status', 'status_default' => ''],
        };
    }
}
