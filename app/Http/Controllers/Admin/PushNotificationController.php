<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DeviceToken;
use App\Models\PushCampaign;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Support\PushSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PushNotificationController extends Controller
{
    /**
     * Compose form + history of past broadcasts.
     */
    public function index(): Response
    {
        return Inertia::render('admin/PushNotifications', [
            'campaigns' => PushCampaign::with('creator:id,name')
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (PushCampaign $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'body' => $c->body,
                    'audience' => $c->audience,
                    'audience_label' => $this->audienceLabel($c),
                    'recipients_count' => $c->recipients_count,
                    'sent_count' => $c->sent_count,
                    'failed_count' => $c->failed_count,
                    'created_by' => $c->creator?->name,
                    'created_at' => $c->created_at?->toIso8601String(),
                ]),
            // Filter options for the targeting UI.
            'cities' => WorkerProfile::whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'categories' => Category::activeNames(),
        ]);
    }

    /**
     * Live worker search for the "specific worker" audience.
     */
    public function searchWorkers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $workers = User::query()
            ->where('role', UserRole::Worker)
            ->when($q !== '', fn (Builder $b) => $b->where(
                fn (Builder $w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'ilike', "%{$q}%")
            ))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
            ]);

        return response()->json(['workers' => $workers]);
    }

    /**
     * Resolve the audience, deliver the push, and log the campaign.
     */
    public function store(Request $request, PushSender $pushSender): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'audience' => ['required', 'in:all,worker,city,category'],
            'worker_id' => ['required_if:audience,worker', 'nullable', 'integer', 'exists:users,id'],
            'city' => ['required_if:audience,city', 'nullable', 'string', 'max:120'],
            'category' => ['required_if:audience,category', 'nullable', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:255'],
        ]);

        $recipientIds = $this->resolveRecipientIds($data);
        $tokens = DeviceToken::whereIn('user_id', $recipientIds)->pluck('token')->all();

        $result = $pushSender->send($tokens, $data['title'], $data['body'], array_filter([
            'type' => 'admin.broadcast',
            'url' => $data['url'] ?? null,
        ], fn ($v) => $v !== null));

        PushCampaign::create([
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'target' => $this->targetPayload($data),
            'url' => $data['url'] ?? null,
            'recipients_count' => count($recipientIds),
            'sent_count' => $result['sent'],
            'failed_count' => $result['failed'],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Push sent to {$result['sent']} device(s).",
        ]);
    }

    /**
     * Worker user IDs matching the chosen audience.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function resolveRecipientIds(array $data): array
    {
        $query = User::query()->where('role', UserRole::Worker)->whereNull('suspended_at');

        $query = match ($data['audience']) {
            'worker' => $query->whereKey($data['worker_id']),
            'city' => $query->whereHas('workerProfile', fn (Builder $p) => $p->where('city', $data['city'])),
            'category' => $query->whereHas('workerProfile', fn (Builder $p) => $p->whereJsonContains('skills', $data['category'])),
            default => $query,
        };

        return $query->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function targetPayload(array $data): ?array
    {
        return match ($data['audience']) {
            'worker' => ['worker_id' => (int) $data['worker_id'], 'worker_name' => User::find($data['worker_id'])?->name],
            'city' => ['city' => $data['city']],
            'category' => ['category' => $data['category']],
            default => null,
        };
    }

    private function audienceLabel(PushCampaign $c): string
    {
        return match ($c->audience) {
            'worker' => 'Worker: '.($c->target['worker_name'] ?? $c->target['worker_id'] ?? '—'),
            'city' => 'City: '.($c->target['city'] ?? '—'),
            'category' => 'Category: '.($c->target['category'] ?? '—'),
            default => 'All workers',
        };
    }
}
