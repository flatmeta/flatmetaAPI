@extends('layouts.app')
@section('title')
  Payments
@stop
@section('content')
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <h5 class="fs-0 mb-0">Payments</h5>
    <form method="get" class="d-flex gap-2">
      <select name="kind" class="form-select form-select-sm">
        <option value="">All kinds</option>
        @foreach (['subscription' => 'Rent subscriptions', 'rent' => 'Rent payments', 'sale' => 'Marketplace sales', 'payout' => 'Seller payouts', 'refund' => 'Refunds'] as $value => $label)
          <option value="{{ $value }}" {{ $kind === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      <select name="status" class="form-select form-select-sm">
        <option value="">All statuses</option>
        @foreach (['pending', 'completed', 'sent', 'failed', 'unclaimed', 'refunded', 'refund_due', 'cancelled'] as $value)
          <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $value)) }}</option>
        @endforeach
      </select>
      <button class="btn btn-sm btn-primary">Filter</button>
    </form>
  </div>

  @if (session('success'))
    <div class="alert alert-success m-3">{{ session('success') }}</div>
  @endif
  @if ($needsAttention > 0)
    <div class="alert alert-warning m-3">
      {{ $needsAttention }} payment(s) need attention: failed or unclaimed payouts, or refunds that did not go through.
      Fix the seller's PayPal email (or pay them by hand) and press Retry.
    </div>
  @endif

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped fs--1 mb-0">
        <thead class="bg-200 text-900">
          <tr>
            <th>ID</th><th>Kind</th><th>Status</th><th>User</th><th>Land</th><th>Amount</th><th>PayPal ref</th><th>Notes</th><th>Date</th><th></th>
          </tr>
        </thead>
        <tbody>
          @forelse ($payments as $p)
            <tr>
              <td>{{ $p->id }}</td>
              <td>{{ $p->kind }}</td>
              <td>
                <span class="badge {{ in_array($p->status, ['completed', 'sent']) ? 'bg-success' : (in_array($p->status, ['failed', 'unclaimed', 'refund_due']) ? 'bg-danger' : 'bg-secondary') }}">
                  {{ str_replace('_', ' ', $p->status) }}
                </span>
              </td>
              <td>{{ $p->user->username ?? '—' }}<br><small>{{ $p->user->email ?? '' }}</small></td>
              <td>{{ $p->order_id ? '#' . $p->order_id : '—' }}</td>
              <td>{{ number_format($p->amount, 2) }} {{ $p->currency }}</td>
              <td><small>{{ $p->provider_ref }}</small></td>
              <td><small>{{ $p->meta['error'] ?? ($p->meta['email'] ?? ($p->meta['refund_error'] ?? '')) }}</small></td>
              <td>{{ $p->created_at->format('d-m-Y H:i') }}</td>
              <td>
                @if ($p->kind === 'payout' && in_array($p->status, ['failed', 'unclaimed']))
                  <form method="post" action="{{ route('RetryPayout', $p->id) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary">Retry</button>
                  </form>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center py-4">No payments yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer">{{ $payments->withQueryString()->links() }}</div>
</div>
@endsection
