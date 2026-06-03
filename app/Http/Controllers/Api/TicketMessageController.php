<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdTicketMessage;
use App\Services\HrisWorkspaceService;
use App\Services\TicketMessageAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketMessageController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris,
        protected TicketMessageAttachmentService $attachments
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'hris_only' => true],
            ]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'in:all,assigned,reported,participated'],
            'project_id' => ['nullable', 'integer'],
            'has_messages' => ['nullable', 'in:0,1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $payload = $this->hris->listMessageConversations($request->user(), $filters);

        return response()->json($payload);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404, 'Fitur pesan tiket hanya tersedia dalam mode HRIS.');
        }

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $model = $this->hris->findTicketForMessages($request->user(), $ticket);
        $payload = $this->hris->listTicketMessages($model, $request->user(), $filters);

        return response()->json($payload);
    }

    public function store(Request $request, int $ticket): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404, 'Fitur pesan tiket hanya tersedia dalam mode HRIS.');
        }

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:10000', 'required_without:attachment'],
            'attachment' => $this->attachments->rules(),
        ]);

        $model = $this->hris->findTicketForMessages($request->user(), $ticket);
        $message = $this->hris->addMessage(
            $model,
            $request->user(),
            $validated['body'] ?? null,
            $request->file('attachment')
        );

        return response()->json([
            'data' => $this->hris->messageToArrayForUser($message->load('user.employee'), $request->user()),
        ], 201);
    }

    public function destroy(Request $request, int $ticket, int $message): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404, 'Fitur pesan tiket hanya tersedia dalam mode HRIS.');
        }

        $this->hris->findTicketForMessages($request->user(), $ticket);

        $model = HdTicketMessage::where('hd_tickets_id', $ticket)->findOrFail($message);

        if ((int) $model->users_id !== (int) $request->user()->id && ! $request->user()->hasPermission('tasks.delete')) {
            abort(403, 'Anda tidak dapat menghapus pesan ini.');
        }

        $this->attachments->delete($model->attachment);
        $model->delete();

        return response()->json(['message' => 'Pesan dihapus']);
    }

    public function attachment(Request $request, int $ticket, int $message): StreamedResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $this->hris->findTicketForMessages($request->user(), $ticket);

        $model = HdTicketMessage::where('hd_tickets_id', $ticket)->findOrFail($message);

        return $this->attachments->download($model);
    }
}
