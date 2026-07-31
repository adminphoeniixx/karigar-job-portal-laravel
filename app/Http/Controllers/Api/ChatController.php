<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ChatMessageResource;
use App\Http\Resources\Api\ConversationResource;
use App\Models\Conversation;
use App\Support\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Direct employer ↔ worker chat — the Messages screens in both apps. Threads
 * are scoped to the employer *account*, so team members share one inbox.
 *
 * The rules live in App\Support\Chat, shared with the web Messages screen.
 */
class ChatController extends Controller
{
    public function __construct(private readonly Chat $chat) {}

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
            'unread_total' => $this->chat->unreadTotal($user),
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

        // Each side names the *other* participant.
        $counterpart = $user->isWorker() ? ($data['employer_id'] ?? null) : ($data['worker_id'] ?? null);

        if ($counterpart === null) {
            return response()->json([
                'message' => $user->isWorker()
                    ? __('employer_id is required.')
                    : __('worker_id is required.'),
            ], 422);
        }

        $participants = $this->chat->participants($user, (int) $counterpart);

        if ($participants === null) {
            return response()->json([
                'message' => __('You can only message workers who applied to your job or whose contact you have unlocked.'),
                'code' => 'chat_not_allowed',
            ], 422);
        }

        [$employerId, $workerId] = $participants;

        $conversation = $this->chat->open($employerId, $workerId, $data['job_id'] ?? null);

        if (! empty($data['body'])) {
            $this->chat->push($conversation, $user, $data['body']);
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

        $this->chat->markRead($conversation, $user);

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

        $message = $this->chat->push($conversation, $user, $data['body']);

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

        $this->chat->markRead($conversation, $user);

        return response()->json([
            'unread' => 0,
            'unread_total' => $this->chat->unreadTotal($user),
        ]);
    }
}
