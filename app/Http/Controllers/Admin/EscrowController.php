<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Services\EscrowService;
use App\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EscrowController extends Controller
{
    public function __construct(private EscrowService $escrows)
    {
    }

    public function index(Request $request, PayoutService $payouts): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,funded,release_requested,released,refunded,disputed'],
        ]);

        $escrows = Escrow::query()
            ->with(['employer:id,name', 'worker:id,name', 'application:id,job_listing_id', 'application.job:id,title'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Escrow $e) => [
                'id' => $e->id,
                'job' => $e->application?->job?->title,
                'employer' => $e->employer?->name,
                'worker' => $e->worker?->name,
                'amount' => $e->amount,
                'commission' => $e->commission,
                'payout_amount' => $e->payout_amount,
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'created_at' => $e->created_at?->diffForHumans(),
            ]);

        return Inertia::render('admin/Escrows', [
            'escrows' => $escrows,
            'filters' => $filters,
            'payoutsConfigured' => $payouts->configured(),
        ]);
    }

    /**
     * Execute the real payout to the worker (RazorpayX). Gated + explicit.
     */
    public function release(Escrow $escrow): RedirectResponse
    {
        try {
            $this->escrows->release($escrow);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Payout sent to :worker.', ['worker' => $escrow->worker?->name]));
    }

    public function refund(Request $request, Escrow $escrow): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->escrows->refund($escrow, $data['note'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Escrow refunded to the employer.'));
    }
}
