<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\HdTicketMessage;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\HrisWorkspaceService;
use App\Services\TicketMessageAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
  public function __construct(
    protected HrisWorkspaceService $hris,
    protected TicketMessageAttachmentService $attachments
  ) {}

  public function store(Request $request, int $project, int $task): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $validated = $request->validate([
        'body' => ['nullable', 'string', 'max:10000', 'required_without:attachment'],
        'attachment' => $this->attachments->rules(),
      ]);

      $category = $this->hris->findCategory($request->user(), $project);
      $ticket = $this->hris->findTicket($category, $task, $request->user());
      $message = $this->hris->addMessage(
        $ticket,
        $request->user(),
        $validated['body'] ?? null,
        $request->file('attachment')
      );

      return response()->json([
        'data' => $this->hris->messageToCommentArray($message->load('user.employee')),
      ], 201);
    }

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
    ]);

    $project = Project::findOrFail($project);
    $task = Task::findOrFail($task);

    if (! $project->hasMember($request->user()) || $task->project_id !== $project->id) {
      abort(403);
    }

    $comment = Comment::create([
      'task_id' => $task->id,
      'user_id' => $request->user()->id,
      'body' => $validated['body'],
    ]);

    ActivityLogger::log(
      $project,
      $request->user(),
      'comment.created',
      "berkomentar pada \"{$task->title}\"",
      Comment::class,
      $comment->id
    );

    return response()->json([
      'data' => new CommentResource($comment->load('user')),
    ], 201);
  }

  public function destroy(Request $request, int $project, int $task, int $comment): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $message = HdTicketMessage::findOrFail($comment);
      if ($message->users_id !== $request->user()->id) {
        abort(403);
      }
      $this->attachments->delete($message->attachment);
      $message->delete();

      return response()->json(['message' => 'Pesan dihapus']);
    }

    $comment = Comment::findOrFail($comment);
    if ($comment->user_id !== $request->user()->id) {
      abort(403);
    }

    $comment->delete();

    return response()->json(['message' => 'Komentar dihapus']);
  }
}
