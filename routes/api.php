<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminBadgeController;
use App\Http\Controllers\Api\AdminAuditController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminKycCaseController;
use App\Http\Controllers\Api\AdminKycServiceController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminTopupController;
use App\Http\Controllers\Api\AdminVerificationController;
use App\Http\Controllers\Api\AdminWalletController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\KycWebhookController;
use App\Http\Controllers\Api\RetailerController;
use App\Http\Controllers\Api\ReportPdfController;
use App\Http\Controllers\Api\RetailerExportController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\VerificationSessionController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\EnsureRole;

// Public routes (no throttle)
Route::post('/login', [AuthController::class, 'login']);

// Provider callback. No login: it is trusted only if the HMAC signature in the header is correct.
Route::post('/webhooks/esign', [KycWebhookController::class, 'esign'])->middleware('throttle:60,1');

// Any logged-in user (admin or retailer)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'getUser']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // My account (profile details + change password)
    Route::get('/account', [AccountController::class, 'show']);
    Route::put('/account/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1');
});

// Super Admin only
Route::middleware(['auth:sanctum', EnsureRole::class . ':super_admin'])->group(function () {
    Route::get('/admin/dashboard-stats', [AdminController::class, 'dashboardStats']);
    Route::get('/admin/badges', [AdminBadgeController::class, 'index']);

    // Retailers
    Route::get('/admin/retailers', [AdminController::class, 'retailers']);
    Route::post('/admin/retailers', [AdminController::class, 'storeRetailer']);
    Route::get('/admin/retailers/{id}', [AdminController::class, 'showRetailer']);
    Route::put('/admin/retailers/{id}', [AdminController::class, 'updateRetailer']);

    // KYC Services overview (cards with usage and success rate)
    Route::get('/admin/kyc-services', [AdminKycServiceController::class, 'index']);

    // Services and pricing
    Route::get('/admin/services', [AdminController::class, 'services']);
    Route::put('/admin/services/{id}', [AdminController::class, 'updateService']);

    // Pricing management screen (these three were missing after the step 7 routes file replaced the old one)
    Route::get('/admin/pricing', [AdminController::class, 'pricingOverview']);
    Route::put('/admin/services', [AdminController::class, 'bulkUpdateServices']);
    Route::put('/admin/retailers/{id}/pricing', [AdminController::class, 'bulkSetRetailerPricing']);
    Route::get('/admin/retailers/{id}/pricing', [AdminController::class, 'retailerPricing']);
    Route::put('/admin/retailers/{id}/pricing/{serviceId}', [AdminController::class, 'setRetailerPricing']);
    Route::delete('/admin/retailers/{id}/pricing/{serviceId}', [AdminController::class, 'clearRetailerPricing']);

    // Wallet top-up approvals
    Route::get('/admin/topups', [AdminTopupController::class, 'index']);
    Route::post('/admin/topups/{id}/approve', [AdminTopupController::class, 'approve']);
    Route::post('/admin/topups/{id}/reject', [AdminTopupController::class, 'reject']);

    // Retailer wallets: balances, manual add/deduct (reason + audit), statements
    Route::get('/admin/wallets', [AdminWalletController::class, 'index']);
    Route::get('/admin/wallets/{id}/ledger', [AdminWalletController::class, 'ledger'])->whereNumber('id');
    Route::post('/admin/wallets/{id}/adjust', [AdminWalletController::class, 'adjust'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/admin/wallets/{id}/export', [AdminWalletController::class, 'export'])->whereNumber('id')->middleware('throttle:10,1');

    // Audit log (read only)
    Route::get('/admin/audit-logs', [AdminAuditController::class, 'index']);
    Route::get('/admin/audit-logs/actions', [AdminAuditController::class, 'actions']);

    // Verifications (all retailers)
    Route::get('/admin/verifications', [AdminVerificationController::class, 'index']);

    // Complete KYC cases (all retailers)
    Route::get('/admin/kyc-cases', [AdminKycCaseController::class, 'index']);
    Route::get('/admin/kyc-cases/{id}', [AdminKycCaseController::class, 'show'])->whereNumber('id');
    Route::get('/admin/kyc-cases/{id}/report', [ReportPdfController::class, 'adminKycReport'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/admin/verifications/{id}/receipt', [ReportPdfController::class, 'adminReceipt'])->whereNumber('id')->middleware('throttle:20,1');

    // Reports
    Route::get('/admin/reports', [AdminReportController::class, 'summary']);
    Route::get('/admin/reports/export', [AdminReportController::class, 'export'])->middleware('throttle:10,1');
});

// Retailer only
Route::middleware(['auth:sanctum', EnsureRole::class . ':retailer'])->group(function () {
    Route::get('/retailer/dashboard-stats', [RetailerController::class, 'dashboardStats']);

    // Wallet
    Route::get('/retailer/wallet', [WalletController::class, 'summary']);
    Route::get('/retailer/wallet/ledger', [WalletController::class, 'ledger']);
    Route::get('/retailer/topups', [WalletController::class, 'topups']);
    Route::post('/retailer/topups', [WalletController::class, 'storeTopup']);

    // CSV downloads (own data only)
    Route::get('/retailer/export/wallet-ledger', [RetailerExportController::class, 'ledger'])->middleware('throttle:10,1');
    Route::get('/retailer/export/verifications', [RetailerExportController::class, 'verifications'])->middleware('throttle:10,1');

    // Single verifications (max 30 checks per minute per retailer)
    Route::get('/retailer/services', [VerificationController::class, 'services']);
    Route::get('/retailer/verifications', [VerificationController::class, 'index']);
    Route::post('/retailer/verifications', [VerificationController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/retailer/verifications/{id}/receipt', [ReportPdfController::class, 'receipt'])->whereNumber('id')->middleware('throttle:20,1');

    // Stand-alone Aadhaar (OTP) and e-Sign checks
    Route::get('/retailer/verifications/options', [VerificationSessionController::class, 'options']);
    Route::post('/retailer/verifications/aadhaar', [VerificationSessionController::class, 'aadhaarStart'])->middleware('throttle:20,1');
    Route::post('/retailer/verifications/esign', [VerificationSessionController::class, 'esignStart'])->middleware('throttle:20,1');
    Route::get('/retailer/verifications/{id}', [VerificationSessionController::class, 'show'])->whereNumber('id');
    Route::post('/retailer/verifications/{id}/aadhaar/resend', [VerificationSessionController::class, 'aadhaarResend'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/retailer/verifications/{id}/aadhaar/verify', [VerificationSessionController::class, 'aadhaarVerify'])->whereNumber('id')->middleware('throttle:15,1');
    Route::post('/retailer/verifications/{id}/esign/simulate', [VerificationSessionController::class, 'esignSimulate'])->whereNumber('id');
    Route::post('/retailer/verifications/{id}/cancel', [VerificationSessionController::class, 'cancel'])->whereNumber('id');

    // History screen: single checks AND Complete KYC cases in one list
    Route::get('/retailer/history', [HistoryController::class, 'index']);
    Route::get('/retailer/history/services', [HistoryController::class, 'services']);

    // Complete KYC: Aadhaar OTP -> PAN -> Bank -> e-Sign
    Route::get('/retailer/kyc/options', [KycController::class, 'options']);
    Route::get('/retailer/kyc', [KycController::class, 'index']);
    Route::post('/retailer/kyc', [KycController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/retailer/kyc/{id}', [KycController::class, 'show'])->whereNumber('id');
    Route::post('/retailer/kyc/{id}/aadhaar/otp', [KycController::class, 'aadhaarOtp'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/retailer/kyc/{id}/aadhaar/verify', [KycController::class, 'aadhaarVerify'])->whereNumber('id')->middleware('throttle:15,1');
    Route::post('/retailer/kyc/{id}/pan', [KycController::class, 'pan'])->whereNumber('id')->middleware('throttle:15,1');
    Route::post('/retailer/kyc/{id}/bank', [KycController::class, 'bank'])->whereNumber('id')->middleware('throttle:15,1');
    Route::post('/retailer/kyc/{id}/esign/start', [KycController::class, 'esignStart'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/retailer/kyc/{id}/esign/simulate', [KycController::class, 'esignSimulate'])->whereNumber('id');
    Route::post('/retailer/kyc/{id}/cancel', [KycController::class, 'cancel'])->whereNumber('id');

    // PDF documents (own cases only)
    Route::get('/retailer/kyc/{id}/report', [ReportPdfController::class, 'kycReport'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/retailer/kyc/{id}/consent', [ReportPdfController::class, 'kycConsent'])->whereNumber('id')->middleware('throttle:20,1');
});