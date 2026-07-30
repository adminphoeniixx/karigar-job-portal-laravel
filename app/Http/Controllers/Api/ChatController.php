<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ChatMessageResource;
use App\Http\Resources\Api\ConversationResource;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Direct employer ↔ worker chat — the Messages screens in both apps. Threads
 * are scoped to the employer *account*, so team members share one inbox.
 */
class ChatController extends Controller
{
    /**
     * The user's conversation list, newest activity first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $conversations = Conversation::forUser($user)
            ->with(['job:id,title', 'worker.workerProfile', 'employer.employerProfile', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ConversationResource::collection($conversations)->additional([
            'unread_total' => $this->unreadTotal($user),
        ]);
    }

    /**
     * Start (or re-open) a thread. Employers pass `worker_id`, workers pass
     * `employer_id`; `job_id` optionally pins the thread to a job.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'worker_id' => ['required_without:employer_id', 'integer', 'exists:users,id'],
            'employer_id' => ['required_without:worker_id', 'integer', 'exists:users,id'],
            'job_id' => ['nullable', 'integer', 'exists:job_listings,id'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        // Each side names the *other* participant; the caller's own side is
        // always taken from the token, never from the request body.
        $counterpart = $user->isWorker() ? ($data['employer_id'] ?? null) : ($data['worker_id'] ?? null);

        if ($counterpart === null) {
            return response()->json([
                'message' => $user->isWorker()
                    ? __('employer_id is required.')
                    : __('worker_id is required.'),
            ], 422);
        }

        [$employerId, $workerId] = $user->isWorker()
            ? [(int) $counterpart, $user->id]
            : [$user->employerAccount()->id, (int) $counterpart];

        if (! $this->mayChat($employerId, $workerId)) {
            return response()->json([
                'message' => __('You can only message workers who applied to your job or whose contact you have unlocked.'),
                'code' => 'chat_not_allowed',
            ], 422);
        }

        $jobId = $this->resolveJobId($data['job_id'] ?? null, $employerId);

        $conversation = Conversation::firstOrCreate([
            'employer_id' => $employerId,
            'worker_id' => $workerId,
            'job_listing_id' => $jobId,
        ]);

        if (! empty($data['body'])) {
            $this->push($conversation, $user, $data['body']);
        }

        $conversation->load(['job:id,title', 'worker.workerProfile', 'employer.employerProfile', 'messages']);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * One thread's messages (oldest→newest within the page); marks it read.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($conversation->isParticipant($user), 403);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->latest('id')
            ->paginate(30);

        $this->markRead($conversation, $user);

        $conversation->load(['job:id,title', 'worker.workerProfile', 'employer.employerProfile', 'messages']);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages' => ChatMessageResource::collection($messages->getCollection()->reverse()->values()),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Send a message into an existing thread.
     */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($conversation->isParticipant($user), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = $this->push($conversation, $user, $data['body']);

        return response()->json([
            'message' => new ChatMessageResource($message),
        ], 201);
    }

    /**
     * Mark every incoming message in the thread as read.
     */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($conversation->isParticipant($user), 403);

        $this->markRead($conversation, $user);

        return response()->json([
            'unread' => 0,
            'unread_total' => $this->unreadTotal($user),
        ]);
    }

    /**
     * Store a message, bump the thread and notify the other side.
     */
    private function push(Conversation $conversation, User $sender, string $body): ChatMessage
    {
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        $recipient = $conversation->isWorker($sender)
            ? $conversation->employer
            : $conversation->worker;

        $message->setRelation('sender', $sender);
        $recipient?->notify(new NewMessageNotification($message));

        return $message;
    }

    private function markRead(Conversation $conversation, User $user): void
    {
        $conversation->messages()
            ->whereNull('read_at')
            ->when(
                $conversation->isWorker($user),
                fn ($q) => $q->where('sender_id', '!=', $conversation->worker_id),
                fn ($q) => $q->where('sender_id', $conversation->worker_id),
            )
            ->update(['read_at' => now()]);
    }

    /**
     * Employers may only message workers who applied to one of their jobs or
     * whose contact they have already unlocked; workers may reply to those.
     */
    private function mayChat(int $employerId, int $workerId): bool
    {
        return JobApplication::where('worker_id', $workerId)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $employerId))
            ->exists();
    }

    /**
     * Only accept a job id the employer actually owns.
     */
    private function resolveJobId(?int $jobId, int $employerId): ?int
    {
        if ($jobId === null) {
            return null;
        }

        return JobListing::where('id', $jobId)->where('employer_id', $employerId)->exists()
            ? $jobId
            : null;
    }

    private function unreadTotal(User $user): int
    {
        return ChatMessage::whereNull('read_at')
            ->whereHas('conversation', function ($q) use ($user) {
                $q->forUser($user);

                // "Not mine": the worker's own messages for the employer side,
                // and everything but the worker's for the worker side.
                $user->isWorker()
                    ? $q->whereColumn('conversations.worker_id', '!=', 'chat_messages.sender_id')
                    : $q->whereColumn('conversations.worker_id', '=', 'chat_messages.sender_id');
            })
            ->count();
    }
}
