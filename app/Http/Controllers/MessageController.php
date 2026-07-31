<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The web Messages inbox — the browser counterpart of Api\ChatController.
 * Both sit on App\Support\Chat, so the rules are identical either way.
 */
class MessageController extends Controller
{
    /**
     * Newest messages shown in a thread before "load older" would be needed.
     */
    private const THREAD_LIMIT = 200;

    /**
     * Threads listed in the sidebar before "load more" would be needed.
     */
    private const INBOX_LIMIT = 50;

    public function __construct(private readonly Chat $chat) {}

    /**
     * The inbox with no thread selected.
     */
    public function index(Request $request): Response
    {
        return $this->inbox($request, null);
    }

    /**
     * The inbox with one thread open; opening it marks it read.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $user = $request->user();
        abort_unless($conversation->isParticipant($user), 403);

        $this->chat->markRead($conversation, $user);

        return $this->inbox($request, $conversation);
    }

    /**
     * Start (or re-open) a thread with a worker — the "Message" button on the
     * applicants screen lands here.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'worker_id' => ['required_without:employer_id', 'integer', 'exists:users,id'],
            'employer_id' => ['required_without:worker_id', 'integer', 'exists:users,id'],
            'job_id' => ['nullable', 'integer', 'exists:job_listings,id'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $counterpart = $user->isWorker() ? ($data['employer_id'] ?? null) : ($data['worker_id'] ?? null);
        $participants = $counterpart === null ? null : $this->chat->participants($user, (int) $counterpart);

        if ($participants === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('You can only message workers who applied to one of your jobs.'),
            ]);
        }

        [$employerId, $workerId] = $participants;

        $conversation = $this->chat->open($employerId, $workerId, $data['job_id'] ?? null);

        if (! empty($data['body'])) {
            $this->chat->push($conversation, $user, $data['body']);
        }

        return redirect()->route('messages.show', $conversation);
    }

    /**
     * Post a message into an open thread.
     */
    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($conversation->isParticipant($user), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $this->chat->push($conversation, $user, $data['body']);

        return back(fallback: route('messages.show', $conversation));
    }

    /**
     * Render the two-pane inbox: every thread on the left, `$active` on the
     * right. Both props are partial-reload friendly — the page polls them.
     */
    private function inbox(Request $request, ?Conversation $active): Response
    {
        $user = $request->user();

        return Inertia::render('messages/Index', [
            'conversations' => fn () => $this->threads($user),
            'active' => fn () => $active === null ? null : [
                'id' => $active->id,
                'counterpart' => $this->counterpart($active, $user),
                'job' => $active->job ? ['id' => $active->job->id, 'title' => $active->job->title] : null,
                'messages' => $this->messages($active, $user),
            ],
        ]);
    }

    /**
     * The thread list, most recent activity first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function threads(User $user): array
    {
        $conversations = Conversation::forUser($user)
            ->with([
                'job:id,title',
                'worker.workerProfile',
                'employer.employerProfile',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::INBOX_LIMIT)
            ->get();

        $unread = $this->unreadPerThread($user, $conversations->modelKeys());

        return $conversations
            ->map(function (Conversation $c) use ($user, $unread) {
                $last = $c->messages->first();

                return [
                    'id' => $c->id,
                    'counterpart' => $this->counterpart($c, $user),
                    'job' => $c->job?->title,
                    'last_message' => $last?->body,
                    'last_is_mine' => $last !== null && $last->sender_id === $user->id,
                    'last_at' => ($c->last_message_at ?? $c->created_at)?->diffForHumans(),
                    'unread' => (int) ($unread[$c->id] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Unread counts for the given threads in one query — "unread" being the
     * messages the *other* side sent (see App\Support\Chat::unreadTotal()).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, int>
     */
    private function unreadPerThread(User $user, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return ChatMessage::query()
            ->join('conversations', 'conversations.id', '=', 'chat_messages.conversation_id')
            ->whereIn('chat_messages.conversation_id', $ids)
            ->whereNull('chat_messages.read_at')
            ->whereColumn('chat_messages.sender_id', $user->isWorker() ? '!=' : '=', 'conversations.worker_id')
            ->groupBy('chat_messages.conversation_id')
            ->selectRaw('chat_messages.conversation_id, count(*) as aggregate')
            ->pluck('aggregate', 'chat_messages.conversation_id');
    }

    /**
     * The messages of one thread, oldest → newest.
     *
     * @return array<int, array<string, mixed>>
     */
    private function messages(Conversation $conversation, User $user): array
    {
        return $conversation->messages()
            ->with('sender:id,name')
            ->latest('id')
            ->limit(self::THREAD_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'mine' => $m->sender_id === $user->id,
                'sender' => $m->sender?->name,
                'at' => $m->created_at?->format('d M, h:i A'),
                'read' => $m->read_at !== null,
            ])
            ->all();
    }

    /**
     * The other side of the thread, as the page needs to show it.
     *
     * @return array<string, mixed>
     */
    private function counterpart(Conversation $conversation, User $user): array
    {
        $other = $conversation->counterpartFor($user);
        $name = $other?->name ?? __('Unknown');

        // Employers see the worker's city; workers see the company name.
        $place = trim(implode(', ', array_filter([
            $other?->workerProfile?->city,
            $other?->workerProfile?->state,
        ])));

        return [
            'id' => $other?->id,
            'name' => $name,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'subtitle' => $conversation->isWorker($user)
                ? $other?->employerProfile?->company_name
                : ($place !== '' ? $place : null),
        ];
    }
}
