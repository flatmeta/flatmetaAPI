<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Billing;
use Illuminate\Http\Request;

/** CMS: every PayPal money movement, with retry for failed seller payouts. */
class PaymentsAdminController extends Controller
{
    public function index(Request $request)
    {
        $kind = (string) $request->query('kind', '');
        $status = (string) $request->query('status', '');
        $payments = Payment::with('user')
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(50);
        $needsAttention = Payment::where(function ($q) {
            $q->where('kind', 'payout')->whereIn('status', ['failed', 'unclaimed']);
        })->orWhere('status', 'refund_due')->count();

        return view('Payments.list', compact('payments', 'kind', 'status', 'needsAttention'));
    }

    public function retryPayout(Billing $billing, $id)
    {
        $payout = Payment::where('kind', Payment::KIND_PAYOUT)->findOrFail($id);
        $sale = Payment::findOrFail($payout->related_payment_id);
        $result = $billing->payoutSeller($sale);
        $message = $result->status === 'sent'
            ? "Payout #{$payout->id} sent again."
            : "Payout #{$payout->id} still failing: " . ($result->meta['error'] ?? 'unknown error');
        return redirect()->route('Payments')->with('success', $message);
    }
}
