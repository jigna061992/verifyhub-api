<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Models\VerificationService;
use App\Services\VerificationRunner;
use Illuminate\Http\Request;

// Retailer side: run a verification and see own history
class VerificationController extends Controller
{
    private const PAN_REGEX  = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';
    private const GST_REGEX  = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
    private const IFSC_REGEX = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    // One-call checks (this controller)
    public const SUPPORTED = ['PAN', 'BANK', 'GST'];

    // Checks with more than one step (VerificationSessionController): Aadhaar OTP, e-Sign
    public const SESSION = ['AADHAAR', 'ESIGN'];

    // Every single check a retailer can see in lists and filters
    public const ALL = ['PAN', 'BANK', 'AADHAAR', 'GST', 'ESIGN'];

    public function __construct(private VerificationRunner $runner)
    {
    }

    // Enabled services with the price THIS retailer will pay
    public function services(Request $request)
    {
        $retailer = $this->retailer($request);

        return VerificationService::whereIn('code', self::ALL)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'code'  => $s->code,
                'name'  => $s->name,
                'price' => $this->runner->priceFor($retailer, $s),
                // instant = one form, otp = Aadhaar OTP screen, esign = customer signs on their phone
                'flow'  => match ($s->code) {
                    'AADHAAR' => 'otp',
                    'ESIGN'   => 'esign',
                    default   => 'instant',
                },
            ]);
    }

    public function index(Request $request)
    {
        $retailer = $this->retailer($request);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);
        $status   = $request->query('status');
        $code     = $request->query('service');

        $query = Verification::with('service:id,code,name')
            ->where('retailer_id', $retailer->id)
            ->latest()
            ->latest('id');

        if (in_array($status, ['pending', 'success', 'failed'], true)) {
            $query->where('status', $status);
        }

        if ($code) {
            $query->whereHas('service', fn ($s) => $s->where('code', $code));
        }

        return $query->paginate($perPage)->through(fn ($v) => $this->row($v));
    }

    public function store(Request $request)
    {
        $retailer = $this->retailer($request);

        $request->validate(['service_code' => 'required|string']);

        $code = strtoupper(trim($request->input('service_code')));

        $service = VerificationService::where('code', $code)->where('enabled', true)->first();

        // Aadhaar and e-Sign have their own routes (OTP / signing steps)
        if (! $service || ! in_array($code, self::SUPPORTED, true)) {
            return response()->json(['message' => 'This service is not available right now.'], 422);
        }

        // Clean the input, then validate the fields of the chosen service
        $this->normalize($request);
        [$rules, $messages] = $this->rulesFor($code);
        $input = $request->validate($rules, $messages);

        try {
            $verification = $this->runner->run($retailer, $service, $input);
        } catch (InsufficientBalanceException) {
            $price = $this->runner->priceFor($retailer, $service);

            return response()->json([
                'code'    => 'insufficient_balance',
                'message' => 'Insufficient wallet balance. This check costs Rs ' . number_format($price, 2) . '. Please add money to your wallet.',
            ], 422);
        }

        $verification->load('service:id,code,name');

        return response()->json(
            $this->row($verification, withResult: true) + [
                'wallet_available' => (float) ($retailer->fresh()->wallet?->available_balance ?? 0),
            ],
            201
        );
    }

    private function rulesFor(string $code): array
    {
        return match ($code) {
            'PAN' => [
                ['pan' => ['required', 'regex:' . self::PAN_REGEX]],
                ['pan.regex' => 'PAN format is invalid (example: ABCDE1234F).'],
            ],
            'BANK' => [
                [
                    'account_number' => ['required', 'regex:/^\d{9,18}$/'],
                    'ifsc'           => ['required', 'regex:' . self::IFSC_REGEX],
                ],
                [
                    'account_number.regex' => 'Account number must be 9 to 18 digits.',
                    'ifsc.regex'           => 'IFSC code is invalid (example: SBIN0001234).',
                ],
            ],
            'GST' => [
                ['gstin' => ['required', 'regex:' . self::GST_REGEX]],
                ['gstin.regex' => 'GST number format is invalid.'],
            ],
        };
    }

    private function normalize(Request $request): void
    {
        foreach (['pan', 'ifsc', 'gstin'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => strtoupper(trim((string) $request->input($field)))]);
            }
        }

        if ($request->has('account_number')) {
            $request->merge(['account_number' => trim((string) $request->input('account_number'))]);
        }
    }

    // The provider result (name etc.) is only returned right after the check, not in the history list
    private function row(Verification $v, bool $withResult = false): array
    {
        return [
            'id'             => $v->id,
            'service_code'   => $v->service?->code,
            'service_name'   => $v->service?->name,
            'status'         => $v->status,
            'input_masked'   => $v->input_masked,
            'price'          => (float) $v->price,
            'charged'        => $v->status === 'success' ? (float) $v->price : 0.0,
            'failure_reason' => $v->failure_reason,
            'stage'          => $v->status === 'pending' ? $v->stage : null,
            'result'         => $withResult && $v->status === 'success' ? $v->result : null,
            'created_at'     => $v->created_at?->toIso8601String(),
        ];
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }
}
