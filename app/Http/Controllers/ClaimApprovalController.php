<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Services\ClaimService;
use DomainException;
use Illuminate\Http\Request;

class ClaimApprovalController extends Controller
{
    public function __construct(private ClaimService $claims) {}

    /**
     * Pending tab: claims waiting for this approver (never their own), oldest first.
     * Approved / Rejected tabs: claims already decided, latest decision first.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), ['approved', 'rejected'], true) ? $request->query('tab') : 'pending';
        $base = fn () => Claim::query()->visibleTo($user)->where('claims.user_id', '<>', $user->id);

        $counts = [
            'pending' => $base()->where('claims.status', Claim::STATUS_SUBMITTED)->count(),
            'approved' => $base()->where('claims.status', Claim::STATUS_APPROVED)->count(),
            'rejected' => $base()->where('claims.status', Claim::STATUS_REJECTED)->count(),
        ];

        $query = $base()->with(['user.department', 'category', 'project', 'receipts', 'reviewer']);
        $pending = ($tab === 'pending'
            ? $query->where('claims.status', Claim::STATUS_SUBMITTED)->orderBy('submitted_at')
            : $query->where('claims.status', $tab)->orderByDesc('reviewed_at')
        )->paginate(25)->withQueryString();

        return view('approvals.index', compact('pending', 'tab', 'counts'));
    }

    public function approve(Request $request, Claim $claim)
    {
        $this->authorize('review', $claim);
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        return $this->review($claim, $request, true, $data['review_note'] ?? null);
    }

    public function reject(Request $request, Claim $claim)
    {
        $this->authorize('review', $claim);
        $data = $request->validate(
            ['review_note' => ['required', 'string', 'min:3', 'max:1000']],
            ['review_note.required' => 'Please give the reason for rejecting, so the staff knows what to fix.'],
        );

        return $this->review($claim, $request, false, $data['review_note']);
    }

    private function review(Claim $claim, Request $request, bool $approve, ?string $note)
    {
        try {
            $claim = $this->claims->review($claim, $request->user(), $approve, $note);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $next = Claim::query()->visibleTo($request->user())
            ->where('claims.status', Claim::STATUS_SUBMITTED)
            ->where('claims.user_id', '<>', $request->user()->id)
            ->orderBy('submitted_at')
            ->first();

        return redirect()->to($next ? route('claims.show', $next) : route('approvals.index'))
            ->with('success', "Claim {$claim->claim_number} ".($approve ? 'approved.' : 'rejected — the staff can fix it and resubmit.')
                .($next ? ' Showing the next claim waiting for approval.' : ''));
    }
}
