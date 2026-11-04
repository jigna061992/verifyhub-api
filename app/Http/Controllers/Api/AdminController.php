<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Retailer;
use App\Models\WalletLedger;
use App\Models\Reconciliation;
use App\Models\RetailerPricing;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationService;
use App\Models\Wallet;
use App\Models\WalletTopupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Models\KycCase;
use App\Services\BundlePricing;

class AdminController extends Controller
{
    private const PAN_REGEX = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';
    private const GST_REGEX = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /* ------------------------------------------------------------------
     | Dashboard
     * ------------------------------------------------------------------ */

    public function dashboardStats()
    {
        $now    = now();
        $driver = DB::connection()->getDriverName();
        $monthSql = $driver === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";

        $since30 = $now->copy()->subDays(30);
        $since60 = $now->copy()->subDays(60);

        // Percentage change between two numbers (null when there is nothing to compare with)
        $change = fn ($current, $previous) => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;

        /* ---------- Retailers ---------- */
        $rTotal  = Retailer::count();
        $rActive = Retailer::where('status', 'active')->count();

        /* ---------- Verifications (single checks) ---------- */
        $vTotal   = Verification::count();
        $vSuccess = Verification::where('status', 'success')->count();
        $vFailed  = Verification::where('status', 'failed')->count();
        $vPending = Verification::where('status', 'pending')->count();

        $last30 = Verification::where('created_at', '>=', $since30)->count();
        $prev30 = Verification::whereBetween('created_at', [$since60, $since30])->count();

        $okLast30 = Verification::where('status', 'success')->where('created_at', '>=', $since30)->count();
        $okPrev30 = Verification::where('status', 'success')->whereBetween('created_at', [$since60, $since30])->count();

        $failLast30 = Verification::where('status', 'failed')->where('created_at', '>=', $since30)->count();
        $failPrev30 = Verification::where('status', 'failed')->whereBetween('created_at', [$since60, $since30])->count();

        /* ---------- Money: single checks + completed Complete KYC cases ---------- */
        $revenueOf = fn ($from = null, $to = null) =>
            (float) Verification::where('status', 'success')
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->sum('price')
            + (float) KycCase::where('status', 'completed')
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->sum('price');

        $costOf = fn ($from = null, $to = null) =>
            (float) Verification::where('status', 'success')
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->sum('provider_cost')
            + (float) KycCase::where('status', 'completed')
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->sum('provider_cost');

        $revenueAll = $revenueOf();
        $rev30      = $revenueOf($since30);
        $revPrev30  = $revenueOf($since60, $since30);
        $margin30   = $rev30 - $costOf($since30);

        /* ---------- Today ---------- */
        $todayStart = $now->copy()->startOfDay();
        $todayCount = Verification::where('created_at', '>=', $todayStart)->count();
        $todayBilled = $revenueOf($todayStart);
        $avg7 = Verification::whereBetween('created_at', [$now->copy()->subDays(7)->startOfDay(), $todayStart])->count() / 7;

        /* ---------- Wallet ---------- */
        $ledgerCount    = WalletLedger::count();
        $ledgerCount30  = WalletLedger::where('created_at', '>=', $since30)->count();
        $ledgerPrev30   = WalletLedger::whereBetween('created_at', [$since60, $since30])->count();
        $totalCredited  = (float) WalletLedger::where('type', 'credit')->sum('amount');

        /* ---------- Last 6 months ---------- */
        $months = collect(range(5, 0))->map(fn ($i) => $now->copy()->startOfMonth()->subMonths($i));
        $from6  = $months->first();

        $vByMonth = Verification::where('created_at', '>=', $from6)
            ->selectRaw("{$monthSql} as m, status, COUNT(*) as c, SUM(CASE WHEN status = 'success' THEN price ELSE 0 END) as rev, SUM(CASE WHEN status = 'success' THEN provider_cost ELSE 0 END) as cost")
            ->groupBy('m', 'status')
            ->get()
            ->groupBy('m');

        $kByMonth = KycCase::where('status', 'completed')->where('created_at', '>=', $from6)
            ->selectRaw("{$monthSql} as m, SUM(price) as rev, SUM(provider_cost) as cost")
            ->groupBy('m')
            ->get()
            ->keyBy('m');

        $trend = [];
        $revenueChart = [];

        foreach ($months as $month) {
            $key  = $month->format('Y-m');
            $rows = $vByMonth[$key] ?? collect();
            $count = fn ($status) => (int) optional($rows->firstWhere('status', $status))->c;

            $rev  = (float) $rows->sum('rev') + (float) optional($kByMonth[$key] ?? null)->rev;
            $cost = (float) $rows->sum('cost') + (float) optional($kByMonth[$key] ?? null)->cost;

            $trend[] = [
                'label'    => $month->format('M'),
                'verified' => $count('success'),
                'failed'   => $count('failed'),
                'pending'  => $count('pending'),
            ];

            $revenueChart[] = [
                'label'   => $month->format('M'),
                'revenue' => round($rev, 2),
                'cost'    => round($cost, 2),
                'margin'  => round($rev - $cost, 2),
            ];
        }

        /* ---------- Per service: share and success rate ---------- */
        $perService = Verification::join('verification_services as s', 's.id', '=', 'verifications.service_id')
            ->selectRaw("s.code, s.name, COUNT(*) as total, SUM(CASE WHEN verifications.status = 'success' THEN 1 ELSE 0 END) as ok, SUM(CASE WHEN verifications.status = 'failed' THEN 1 ELSE 0 END) as bad")
            ->groupBy('s.code', 's.name')
            ->get();

        $kycTotal = KycCase::count();
        $kycDone  = KycCase::where('status', 'completed')->count();
        $kycBad   = KycCase::whereIn('status', ['failed', 'expired'])->count();

        $categories = $perService->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'total' => (int) $r->total]);
        if ($kycTotal > 0) {
            $categories->push(['code' => 'COMPLETE_KYC', 'name' => 'Complete KYC', 'total' => $kycTotal]);
        }

        $catSum = max((int) $categories->sum('total'), 1);
        $categories = $categories->sortByDesc('total')->values()->map(fn ($c) => $c + ['percent' => (int) round($c['total'] / $catSum * 100)]);

        $successByService = $perService->map(function ($r) {
            $done = (int) $r->ok + (int) $r->bad;

            return ['code' => $r->code, 'name' => $r->name, 'rate' => $done ? round($r->ok / $done * 100, 1) : null];
        });
        if ($kycDone + $kycBad > 0) {
            $successByService->push(['code' => 'COMPLETE_KYC', 'name' => 'Complete KYC', 'rate' => round($kycDone / ($kycDone + $kycBad) * 100, 1)]);
        }

        /* ---------- Verifications per day (last 7 days) ---------- */
        $daily = Verification::where('created_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $chart = collect(range(6, 0))->map(function ($d) use ($daily, $now) {
            $day = $now->copy()->subDays($d);

            return ['label' => $day->format('D'), 'total' => (int) ($daily[$day->toDateString()] ?? 0)];
        })->values();

        /* ---------- Lists ---------- */
        $recent = Verification::with('retailer:id,business_name', 'service:id,code,name')
            ->latest()->take(8)->get()
            ->map(fn ($v) => [
                'id'            => $v->id,
                'reference'     => strtoupper('VRF' . substr(str_replace('-', '', $v->reference), 0, 10)),
                'business_name' => $v->retailer?->business_name,
                'service_code'  => $v->service?->code,
                'service_name'  => $v->service?->name,
                'document'      => $v->input_masked,
                'status'        => $v->status,
                'amount'        => (float) $v->price,
                'created_at'    => $v->created_at->toIso8601String(),
            ]);

        $topMap = Verification::where('status', 'success')->where('created_at', '>=', $since30)
            ->selectRaw('retailer_id, SUM(price) as billed, COUNT(*) as checks')->groupBy('retailer_id')->get()
            ->keyBy('retailer_id')
            ->map(fn ($r) => ['billed' => (float) $r->billed, 'checks' => (int) $r->checks]);

        KycCase::where('status', 'completed')->where('created_at', '>=', $since30)
            ->selectRaw('retailer_id, SUM(price) as billed, COUNT(*) as checks')->groupBy('retailer_id')->get()
            ->each(function ($r) use ($topMap) {
                $old = $topMap[$r->retailer_id] ?? ['billed' => 0, 'checks' => 0];
                $topMap[$r->retailer_id] = ['billed' => $old['billed'] + (float) $r->billed, 'checks' => $old['checks'] + (int) $r->checks];
            });

        $topIds   = $topMap->sortByDesc('billed')->take(5);
        $names    = Retailer::whereIn('id', $topIds->keys())->pluck('business_name', 'id');
        $topRetailers = $topIds->map(fn ($r, $id) => [
            'id' => $id, 'business_name' => $names[$id] ?? '-', 'billed' => $r['billed'], 'checks' => $r['checks'],
        ])->values();

        $pendingTopups = WalletTopupRequest::with('retailer:id,business_name')
            ->where('status', 'pending')->latest()->take(5)->get()
            ->map(fn ($t) => [
                'id'            => $t->id,
                'business_name' => $t->retailer?->business_name,
                'amount'        => (float) $t->amount,
                'method'        => $t->method,
                'created_at'    => $t->created_at->toIso8601String(),
            ]);

        $recentRetailers = Retailer::with('user:id,name,mobile', 'wallet:id,retailer_id,available_balance')
            ->latest()->take(5)->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'business_name' => $r->business_name,
                'owner'         => $r->user?->name,
                'mobile'        => $r->user?->mobile,
                'balance'       => (float) ($r->wallet?->available_balance ?? 0),
                'status'        => $r->status,
            ]);

        /* ---------- Alerts: things that need the admin's attention ---------- */
        $alerts = [];

        foreach (WalletTopupRequest::with('retailer:id,business_name')->where('status', 'pending')->latest()->take(3)->get() as $t) {
            $alerts[] = [
                'type' => 'topup', 'severity' => 'info', 'link' => '/admin/wallet',
                'title' => 'Fund request · ₹' . number_format((float) $t->amount),
                'message' => $t->retailer?->business_name, 'created_at' => $t->created_at->toIso8601String(),
            ];
        }

        $openRecon = Reconciliation::where('status', 'open')->count();
        if ($openRecon > 0) {
            $alerts[] = [
                'type' => 'reconciliation', 'severity' => 'warning', 'link' => '/admin/reconciliation',
                'title' => $openRecon . ' check(s) waiting for reconciliation',
                'message' => 'Provider answer is unknown. The money is still on hold.', 'created_at' => null,
            ];
        }

        $threshold  = (float) config('verification.low_balance_threshold', 500);
        $lowBalance = Retailer::where('status', 'active')
            ->whereHas('wallet', fn ($q) => $q->where('available_balance', '<', $threshold))->count();
        if ($lowBalance > 0) {
            $alerts[] = [
                'type' => 'low_balance', 'severity' => 'warning', 'link' => '/admin/wallets',
                'title' => $lowBalance . ' retailer(s) with low balance',
                'message' => 'Wallet is below ₹' . number_format($threshold), 'created_at' => null,
            ];
        }

        $day = Verification::where('created_at', '>=', $now->copy()->subDay());
        $dayTotal  = (clone $day)->whereIn('status', ['success', 'failed'])->count();
        $dayFailed = (clone $day)->where('status', 'failed')->count();
        if ($dayTotal >= 5 && $dayFailed / $dayTotal >= 0.25) {
            $alerts[] = [
                'type' => 'failures', 'severity' => 'danger', 'link' => '/admin/verifications',
                'title' => 'High failure rate in the last 24 hours',
                'message' => "{$dayFailed} of {$dayTotal} checks failed.", 'created_at' => null,
            ];
        }

        return response()->json([
            'retailers' => [
                'total'      => $rTotal,
                'active'     => $rActive,
                'inactive'   => Retailer::where('status', 'inactive')->count(),
                'new_30d'    => Retailer::where('created_at', '>=', $since30)->count(),
                'active_pct' => $rTotal ? (int) round($rActive / $rTotal * 100) : 0,
            ],
            'wallet' => [
                'total_balance'         => (float) Wallet::sum('available_balance'),
                'pending_topups_count'  => WalletTopupRequest::where('status', 'pending')->count(),
                'pending_topups_amount' => (float) WalletTopupRequest::where('status', 'pending')->sum('amount'),
                'transactions'          => $ledgerCount,
                'transactions_change'   => $change($ledgerCount30, $ledgerPrev30),
                'total_credited'        => $totalCredited,
            ],
            'verifications' => [
                'total'           => $vTotal,
                'success'         => $vSuccess,
                'failed'          => $vFailed,
                'pending'         => $vPending,
                'success_rate'    => $vTotal ? round($vSuccess / $vTotal * 100, 1) : null,
                'failed_share'    => $vTotal ? round($vFailed / $vTotal * 100, 1) : null,
                'total_change'    => $change($last30, $prev30),
                'success_change'  => $change($okLast30, $okPrev30),
                'failed_change'   => $change($failLast30, $failPrev30),
                'revenue'         => $revenueAll,
                'revenue_change'  => $change($rev30, $revPrev30),
            ],
            'today' => [
                'transactions' => $todayCount,
                'billed'       => $todayBilled,
                'pace_change'  => $avg7 > 0 ? round(($todayCount - $avg7) / $avg7 * 100, 1) : null,
            ],
            'margin' => [
                'amount'  => round($margin30, 2),
                'percent' => $rev30 > 0 ? round($margin30 / $rev30 * 100, 1) : null,
            ],
            'trend'             => $trend,
            'categories'        => $categories,
            'revenue_chart'     => $revenueChart,
            'success_by_service' => $successByService->values(),
            'recent_verifications' => $recent,
            'top_retailers'     => $topRetailers,
            'alerts'            => $alerts,
            'chart'             => $chart,
            'pending_topups'    => $pendingTopups,
            'recent_retailers'  => $recentRetailers,
        ]);
    }

    /* ------------------------------------------------------------------
     | Retailers
     * ------------------------------------------------------------------ */

    // List with search, status filter and pagination
    public function retailers(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $search  = trim((string) $request->query('search', ''));
        $status  = $request->query('status');

        $query = Retailer::with([
            'user:id,name,email,mobile,status',
            'wallet:id,retailer_id,available_balance',
        ])->latest()->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage)->through(fn ($r) => $this->retailerRow($r));
    }

    // Create user + retailer + wallet together
    public function storeRetailer(Request $request)
    {
        $this->normalizeIdentifiers($request);

        $data = $request->validate([
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|max:150|unique:users,email',
            'mobile'        => 'required|digits:10|unique:users,mobile',
            'password'      => ['required', 'string', 'max:100', Password::min(8)->letters()->numbers()],
            'business_name' => 'required|string|max:150',
            'address'       => 'required|string|min:10|max:500',
            'pan'           => ['required', 'regex:' . self::PAN_REGEX],
            'gst'           => ['nullable', 'regex:' . self::GST_REGEX],
            'status'        => 'sometimes|in:active,inactive',
        ], [
            'address.min'  => 'Please enter the complete business address.',
            'pan.required' => 'PAN number is required.',
            'pan.regex'    => 'PAN format is invalid (example: ABCDE1234F).',
            'gst.regex'    => 'GST number format is invalid.',
        ]);

        $status = $data['status'] ?? 'active';

        // All three records are created or none of them
        $retailer = DB::transaction(function () use ($data, $status) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'mobile'   => $data['mobile'],
                'password' => $data['password'],
                'role'     => 'retailer',
                'status'   => $status,
            ]);

            $retailer = Retailer::create([
                'user_id'       => $user->id,
                'business_name' => $data['business_name'],
                'address'       => $data['address'],
                'pan'           => $data['pan'],
                'gst'           => $data['gst'] ?? null,
                'status'        => $status,
            ]);

            $retailer->wallet()->create();

            return $retailer;
        });

        $this->audit($request, 'retailer.created', 'retailer', $retailer->id, [
            'business_name' => $retailer->business_name,
        ]);

        return response()->json($this->retailerRow($retailer->load('user', 'wallet')), 201);
    }

    public function showRetailer($id)
    {
        $retailer = Retailer::with('user', 'wallet')->findOrFail($id);

        return response()->json($this->retailerRow($retailer));
    }

    public function updateRetailer(Request $request, $id)
    {
        $retailer = Retailer::with('user')->findOrFail($id);
        $this->normalizeIdentifiers($request);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'email'         => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($retailer->user_id)],
            'mobile'        => ['sometimes', 'digits:10', Rule::unique('users', 'mobile')->ignore($retailer->user_id)],
            'password'      => ['nullable', 'string', 'max:100', Password::min(8)->letters()->numbers()],
            'business_name' => 'sometimes|string|max:150',
            'address'       => 'sometimes|required|string|min:10|max:500',
            'pan'           => ['nullable', 'regex:' . self::PAN_REGEX],
            'gst'           => ['nullable', 'regex:' . self::GST_REGEX],
            'status'        => 'sometimes|in:active,inactive',
        ], [
            'address.min' => 'Please enter the complete business address.',
            'pan.regex'   => 'PAN format is invalid (example: ABCDE1234F).',
            'gst.regex'   => 'GST number format is invalid.',
        ]);

        DB::transaction(function () use ($retailer, $data) {
            // Login account fields
            $userData = collect($data)->only(['name', 'email', 'mobile', 'status'])->all();
            if (! empty($data['password'])) {
                $userData['password'] = $data['password'];
            }
            if ($userData) {
                $retailer->user->update($userData);
            }

            // Retailer profile fields (PAN/GST are only replaced when a new value is sent)
            $retailerData = collect($data)->only(['business_name', 'address', 'status'])->all();
            if (! empty($data['pan'])) {
                $retailerData['pan'] = $data['pan'];
            }
            if (! empty($data['gst'])) {
                $retailerData['gst'] = $data['gst'];
            }
            if ($retailerData) {
                $retailer->update($retailerData);
            }

            // A deactivated retailer must be signed out everywhere immediately
            if (($data['status'] ?? null) === 'inactive' || ! empty($data['password'])) {
                $retailer->user->tokens()->delete();
            }
        });

        // Never store the password or PAN/GST values in the audit log
        $this->audit($request, 'retailer.updated', 'retailer', $retailer->id, [
            'fields' => array_keys($data),
        ]);

        return response()->json($this->retailerRow($retailer->fresh()->load('user', 'wallet')));
    }

    /* ------------------------------------------------------------------
     | Services and pricing
     * ------------------------------------------------------------------ */

    public function services()
    {
        return VerificationService::active()->orderBy('id')->get()->map(fn ($s) => $this->serviceRow($s));
    }

    public function updateService(Request $request, $id)
    {
        $service = VerificationService::findOrFail($id);

        $data = $request->validate([
            'default_price' => 'sometimes|numeric|min:0|max:100000',
            'provider_cost' => 'sometimes|numeric|min:0|max:100000',
            'enabled'       => 'sometimes|boolean',
        ]);

        // Complete KYC price and cost are calculated from its 4 services. Only "enabled" can be changed by hand.
        if (app(BundlePricing::class)->isBundle($service) && (isset($data['default_price']) || isset($data['provider_cost']))) {
            return response()->json(['message' => 'The Complete KYC price is calculated automatically from Aadhaar, PAN, Bank and e-Sign.'], 422);
        }

        $service->update($data);
        app(BundlePricing::class)->sync();

        $this->audit($request, 'service.updated', 'verification_service', $service->id, $data);

        return response()->json($this->serviceRow($service->fresh()));
    }

    // All services with the default price and this retailer's custom price
    public function retailerPricing($id)
    {
        $retailer = Retailer::findOrFail($id);

        $custom = RetailerPricing::where('retailer_id', $retailer->id)->pluck('custom_price', 'service_id');

        $bundle = app(BundlePricing::class);

        $services = VerificationService::active()->orderBy('id')->get()->map(function ($s) use ($custom, $retailer, $bundle) {
            $auto        = $bundle->isBundle($s);
            $customPrice = ! $auto && isset($custom[$s->id]) ? (float) $custom[$s->id] : null;

            return [
                'service_id'      => $s->id,
                'code'            => $s->code,
                'name'            => $s->name,
                'enabled'         => (bool) $s->enabled,
                'auto_price'      => $auto,
                'provider_cost'   => (float) $s->provider_cost,
                'default_price'   => (float) $s->default_price,
                'custom_price'    => $customPrice,
                // Complete KYC: the sum of this retailer's prices of the 4 services
                'effective_price' => $auto ? $bundle->priceFor($retailer) : ($customPrice ?? (float) $s->default_price),
            ];
        });

        return response()->json([
            'retailer' => ['id' => $retailer->id, 'code' => $this->retailerCode($retailer->id), 'business_name' => $retailer->business_name],
            'services' => $services,
        ]);
    }

    public function setRetailerPricing(Request $request, $id, $serviceId)
    {
        $retailer = Retailer::findOrFail($id);
        $service  = VerificationService::findOrFail($serviceId);

        if (app(BundlePricing::class)->isBundle($service)) {
            return response()->json(['message' => 'The Complete KYC price is calculated automatically. Change the price of its 4 services instead.'], 422);
        }

        $data = $request->validate([
            'custom_price' => 'required|numeric|min:0|max:100000',
        ]);

        RetailerPricing::updateOrCreate(
            ['retailer_id' => $retailer->id, 'service_id' => $service->id],
            ['custom_price' => $data['custom_price']]
        );

        $this->audit($request, 'pricing.set', 'retailer', $retailer->id, [
            'service'      => $service->code,
            'custom_price' => (float) $data['custom_price'],
        ]);

        return response()->json(['custom_price' => (float) $data['custom_price']]);
    }

    public function clearRetailerPricing(Request $request, $id, $serviceId)
    {
        $retailer = Retailer::findOrFail($id);
        $service  = VerificationService::findOrFail($serviceId);

        RetailerPricing::where('retailer_id', $retailer->id)
            ->where('service_id', $service->id)
            ->delete();

        $this->audit($request, 'pricing.cleared', 'retailer', $retailer->id, [
            'service' => $service->code,
        ]);

        return response()->json(['message' => 'Custom price removed. The default price applies now.']);
    }

    /* ------------------------------------------------------------------
     | Pricing management screen
     * ------------------------------------------------------------------ */

    // Everything the Pricing page needs in one call:
    // default rate card (with 30 day usage), retailers list and retailers that have custom prices
    public function pricingOverview()
    {
        // Real numbers from successful checks in the last 30 days (price is the amount actually charged)
        $usage = Verification::where('status', 'success')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('service_id, COUNT(*) as c, SUM(price) as revenue, SUM(price - provider_cost) as margin')
            ->groupBy('service_id')
            ->get()
            ->keyBy('service_id');

        $services = VerificationService::active()->orderBy('id')->get()->map(function ($s) use ($usage) {
            $u = $usage->get($s->id);

            return $this->serviceRow($s) + [
                'usage_30d'   => (int) ($u->c ?? 0),
                'revenue_30d' => round((float) ($u->revenue ?? 0), 2),
                'margin_30d'  => round((float) ($u->margin ?? 0), 2),
            ];
        });

        $retailers = Retailer::orderBy('business_name')->limit(500)->get()->map(fn ($r) => [
            'id'            => $r->id,
            'code'          => $this->retailerCode($r->id),
            'business_name' => $r->business_name,
            'status'        => $r->status,
        ]);

        $custom = RetailerPricing::all()->groupBy('retailer_id');
        $byId   = Retailer::whereIn('id', $custom->keys())->get()->keyBy('id');

        $cards = $custom->map(function ($rows, $retailerId) use ($byId) {
            $r = $byId->get($retailerId);

            if (! $r) {
                return null;
            }

            return [
                'retailer_id'   => $r->id,
                'code'          => $this->retailerCode($r->id),
                'business_name' => $r->business_name,
                'status'        => $r->status,
                'prices'        => $rows->map(fn ($p) => [
                    'service_id' => $p->service_id,
                    'price'      => (float) $p->custom_price,
                ])->values(),
            ];
        })->filter()->sortBy('business_name')->values();

        return response()->json([
            'services'     => $services,
            'retailers'    => $retailers,
            'custom_cards' => $cards,
        ]);
    }

    // "Save defaults": all changed rows of the default rate card in one go (all or nothing)
    public function bulkUpdateServices(Request $request)
    {
        $data = $request->validate([
            'services'                 => 'required|array|min:1|max:50',
            'services.*.id'            => 'required|integer|exists:verification_services,id',
            'services.*.default_price' => 'required|numeric|min:0|max:100000',
            'services.*.provider_cost' => 'required|numeric|min:0|max:100000',
        ]);

        $changes = [];

        DB::transaction(function () use ($data, &$changes) {
            foreach ($data['services'] as $row) {
                $service = VerificationService::findOrFail($row['id']);

                // Complete KYC is calculated, never saved by hand
                if (app(BundlePricing::class)->isBundle($service)) {
                    continue;
                }

                $price = round((float) $row['default_price'], 2);
                $cost  = round((float) $row['provider_cost'], 2);

                if ($price !== round((float) $service->default_price, 2) || $cost !== round((float) $service->provider_cost, 2)) {
                    $changes[$service->code] = ['default_price' => $price, 'provider_cost' => $cost];
                    $service->update(['default_price' => $price, 'provider_cost' => $cost]);
                }
            }
        });

        // The Complete KYC price and cost follow the 4 services
        app(BundlePricing::class)->sync();

        if ($changes) {
            $this->audit($request, 'pricing.defaults_saved', 'verification_service', 0, $changes);
        }

        return $this->pricingOverview();
    }

    // "Save retailer pricing": a price equal to the default price removes the custom price of that service
    public function bulkSetRetailerPricing(Request $request, $id)
    {
        $retailer = Retailer::findOrFail($id);

        $data = $request->validate([
            'prices'              => 'required|array|min:1|max:50',
            'prices.*.service_id' => 'required|integer|exists:verification_services,id',
            'prices.*.price'      => 'required|numeric|min:0|max:100000',
        ]);

        $services = VerificationService::whereIn('id', collect($data['prices'])->pluck('service_id'))->get()->keyBy('id');
        $changes  = [];

        DB::transaction(function () use ($data, $retailer, $services, &$changes) {
            foreach ($data['prices'] as $row) {
                $service = $services[$row['service_id']];
                $price   = round((float) $row['price'], 2);

                // Complete KYC is calculated, never saved by hand
                if (app(BundlePricing::class)->isBundle($service)) {
                    continue;
                }

                $existing = RetailerPricing::where('retailer_id', $retailer->id)->where('service_id', $service->id)->first();

                if ($price === round((float) $service->default_price, 2)) {
                    if ($existing) {
                        $existing->delete();
                        $changes[$service->code] = 'default';
                    }
                } elseif (! $existing || round((float) $existing->custom_price, 2) !== $price) {
                    RetailerPricing::updateOrCreate(
                        ['retailer_id' => $retailer->id, 'service_id' => $service->id],
                        ['custom_price' => $price]
                    );
                    $changes[$service->code] = $price;
                }
            }
        });

        if ($changes) {
            $this->audit($request, 'pricing.bulk_set', 'retailer', $retailer->id, $changes);
        }

        return $this->retailerPricing($retailer->id);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    // Shape sent to the frontend. PAN/GST are always masked.
    private function retailerRow(Retailer $r): array
    {
        return [
            'id'            => $r->id,
            'code'          => $this->retailerCode($r->id),
            'business_name' => $r->business_name,
            'address'       => $r->address,
            'status'        => $r->status,
            'pan_masked'    => $this->mask($r->pan),
            'gst_masked'    => $this->mask($r->gst),
            'balance'       => (float) ($r->wallet?->available_balance ?? 0),
            'created_at'    => $r->created_at?->toIso8601String(),
            'user'          => [
                'id'     => $r->user?->id,
                'name'   => $r->user?->name,
                'email'  => $r->user?->email,
                'mobile' => $r->user?->mobile,
            ],
        ];
    }

    // Display code like RT1007, built from the id (no extra column needed)
    private function retailerCode(int $id): string
    {
        return 'RT' . (1000 + $id);
    }

    private function serviceRow(VerificationService $s): array
    {
        return [
            'id'            => $s->id,
            'code'          => $s->code,
            'name'          => $s->name,
            'provider'      => $s->provider,
            'enabled'       => (bool) $s->enabled,
            'auto_price'    => app(BundlePricing::class)->isBundle($s),
            'default_price' => (float) $s->default_price,
            'provider_cost' => (float) $s->provider_cost,
        ];
    }

    // XXXXXX1234F style masking: only the last 4 characters stay visible
    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $len = strlen($value);

        return $len <= 4 ? str_repeat('X', $len) : str_repeat('X', $len - 4) . substr($value, -4);
    }

    // Upper-case PAN/GST and turn empty strings into null before validation
    private function normalizeIdentifiers(Request $request): void
    {
        foreach (['pan', 'gst'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => $value === '' ? null : strtoupper($value)]);
            }
        }
    }

    private function audit(Request $request, string $action, string $entityType, int $entityId, array $metadata = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id'    => $request->user()?->id,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'metadata'    => json_encode($metadata),
            'ip_address'  => $request->ip(),
            'created_at'  => now(),
        ]);
    }
}