<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Models\VerificationService;
use App\Models\Wallet;
use App\Services\SingleVerificationService;
use App\Services\VerificationRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    // Consent shown for PAN / Bank / GST (Aadhaar and e-Sign use config('verification.consent.*'))
    private const DEFAULT_CONSENT = [
        'version' => 'v1',
        'text'    => 'I confirm that the customer has given explicit consent for this verification, and it is being performed for a lawful KYC purpose ({purpose}) in line with the acceptable use policy of {retailer}.',
    ];

    public function __construct(private VerificationRunner $runner)
    {
    }

    // One service for its own page: price, wallet, today's count, consent text, purposes and (test mode only) sample values
    public function show(Request $request, string $code)
    {
        $retailer = $this->retailer($request);
        $code     = strtoupper(trim($code));

        abort_unless(in_array($code, self::ALL, true), 404, 'Unknown service.');

        $service = VerificationService::where('code', $code)->where('enabled', true)->first();

        abort_if(! $service, 404, 'This service is not available right now.');

        $session = in_array($code, self::SESSION, true);
        $dev     = $this->isDevMode($code);

        return response()->json([
            'code'             => $service->code,
            'name'             => $service->name,
            'flow'             => match ($code) {
                'AADHAAR' => 'otp',
                'ESIGN'   => 'esign',
                default   => 'instant',
            },
            'price'            => $this->runner->priceFor($retailer, $service),
            'wallet_available' => (float) (Wallet::where('retailer_id', $retailer->id)->value('available_balance') ?? 0),
            'today_count'      => Verification::where('retailer_id', $retailer->id)
                ->where('service_id', $service->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
            'retailer'         => $retailer->business_name,
            'purposes'         => collect(config('kyc.purposes'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'consent'          => $this->consentFor($code),
            'ttl_minutes'      => $session ? (int) config('verification.sessions.' . strtolower($code) . '_ttl_minutes') : null,
            'name_match'       => in_array($code, ['PAN', 'BANK', 'GST', 'AADHAAR'], true),
            'dev_mode'         => $dev,
            'samples'          => $dev ? $this->samplesFor($code) : [],
        ]);
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

        // Customer name (optional, used for the name match), purpose and the consent tick
        $extra = $request->validate([
            'customer_name' => ['nullable', 'string', 'min:2', 'max:100', "regex:/^[\\pL][\\pL .'-]*$/u"],
            'purpose'       => ['nullable', Rule::in(array_keys(config('kyc.purposes')))],
            'consent'       => ['accepted'],
        ], [
            'customer_name.regex' => 'Use letters and spaces only.',
            'consent.accepted'    => 'Customer consent must be confirmed before verifying.',
        ]);

        $purpose = $extra['purpose'] ?? array_key_first(config('kyc.purposes'));
        $consent = $this->consentFor($code);
        $text    = strtr((string) $consent['text'], [
            '{retailer}' => $retailer->business_name,
            '{purpose}'  => config('kyc.purposes')[$purpose] ?? '',
        ]);
        $name = trim((string) ($extra['customer_name'] ?? ''));

        try {
            $verification = $this->runner->run($retailer, $service, $input, [
                'customer_name' => $name !== '' ? $name : null,
                'consent'       => [
                    'text'       => $text,
                    'version'    => $consent['version'],
                    'purpose'    => $purpose,
                    'user_id'    => $request->user()->id,
                    'ip'         => $request->ip(),
                    'user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 250, ''),
                ],
            ]);
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

    // Consent text and version of a service
    private function consentFor(string $code): array
    {
        $configured = config('verification.consent.' . strtolower($code));

        return [
            'text'    => (string) ($configured['text'] ?? self::DEFAULT_CONSENT['text']),
            'version' => (string) ($configured['version'] ?? self::DEFAULT_CONSENT['version']),
        ];
    }

    // True only with a mock provider outside production: the page then shows "Try sample" buttons
    private function isDevMode(string $code): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return in_array($code, SingleVerificationService::CODES, true)
            ? config('kyc.driver') === 'mock'
            : config('verification.driver') === 'mock';
    }

    // Values that give each result with the MOCK provider (see MockProvider / MockKycProvider)
    private function samplesFor(string $code): array
    {
        return match ($code) {
            'PAN' => [
                ['label' => 'Valid PAN',         'values' => ['pan' => 'ABCPK1234L']],
                ['label' => 'Record not found',  'values' => ['pan' => 'ABCPK1234Z']],
                ['label' => 'Provider timeout',  'values' => ['pan' => 'ABCPK1234E']],
            ],
            'BANK' => [
                ['label' => 'Valid account',     'values' => ['account_number' => '123456789012', 'ifsc' => 'SBIN0001234']],
                ['label' => 'Account not found', 'values' => ['account_number' => '123456789010', 'ifsc' => 'SBIN0001234']],
                ['label' => 'Provider timeout',  'values' => ['account_number' => '123456789019', 'ifsc' => 'SBIN0001234']],
            ],
            'GST' => [
                ['label' => 'Valid GSTIN',       'values' => ['gstin' => '24ABCPK1234L1Z5']],
                ['label' => 'GSTIN not found',   'values' => ['gstin' => '24ABCPK1234L1Z0']],
            ],
            'AADHAAR' => [
                ['label' => 'Valid Aadhaar',     'values' => ['aadhaar' => '234567890121']],
                ['label' => 'Not found',         'values' => ['aadhaar' => '234567890120']],
            ],
            default => [],
        };
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
            'customer_name'  => $v->meta['customer_name'] ?? null,
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
