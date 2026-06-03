<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\HdTicket;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\HrisWorkspaceService;
use App\Support\AppDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
  public function __construct(
    protected HrisWorkspaceService $hris
  ) {}

  public function index(Request $request, int $project): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $tickets = $this->hris->listTickets($category, $request->user(), $request->only('status', 'trashed'));

      return response()->json([
        'data' => $tickets->values()->map(
          fn (HdTicket $t, int $i) => $this->hris->ticketToTaskArray($t, $i + 1)
        ),
      ]);
    }

    $project = Project::findOrFail($project);
    $this->authorizeProject($request, $project);

    $query = $project->tasks()
      ->with(['assignee', 'labels', 'creator'])
      ->withCount('comments')
      ->orderBy('position');

    if ($status = $request->get('status')) {
      $query->where('status', $status);
    }

    if ($assignee = $request->get('assignee_id')) {
      $query->where('assignee_id', $assignee);
    }

    if ($search = $request->get('search')) {
      $query->where('title', 'like', "%{$search}%");
    }

    return response()->json([
      'data' => TaskResource::collection($query->get()),
    ]);
  }

  public function store(Request $request, int $project): JsonResponse
  {
    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'status' => ['nullable', 'in:backlog,todo,in_progress,review,done'],
      'priority' => ['nullable', 'in:low,medium,high,critical,urgent'],
      'assignee_id' => ['nullable', 'exists:users,id'],
      'due_date' => ['nullable', 'date'],
      'hd_sub_categories_id' => ['nullable', 'integer'],
      'sub_category_id' => ['nullable', 'integer'],
      'story_points' => ['nullable', 'integer', 'min:0'],
      'estimated_hours' => ['nullable', 'integer', 'min:0'],
      'label_ids' => ['nullable', 'array'],
      'label_ids.*' => ['exists:labels,id'],
    ]);

    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $ticket = $this->hris->createTicket($category, $request->user(), $validated);

      return response()->json([
        'data' => $this->hris->ticketToTaskArray($ticket),
      ], 201);
    }

    $project = Project::findOrFail($project);
    $this->authorizeProject($request, $project);

    $maxPosition = $project->tasks()
      ->where('status', $validated['status'] ?? 'todo')
      ->max('position') ?? 0;

    $task = $project->tasks()->create([
      ...$validated,
      'created_by' => $request->user()->id,
      'status' => $validated['status'] ?? 'todo',
      'position' => $maxPosition + 1,
      'completed_at' => ($validated['status'] ?? 'todo') === 'done' ? now() : null,
    ]);

    if (! empty($validated['label_ids'])) {
      $task->labels()->sync($validated['label_ids']);
    }

    ActivityLogger::log(
      $project,
      $request->user(),
      'task.created',
      "membuat task \"{$task->title}\"",
      Task::class,
      $task->id
    );

    $this->syncProjectProgress($project);

    return response()->json([
      'data' => new TaskResource($task->load(['assignee', 'labels', 'creator'])),
    ], 201);
  }

  public function show(Request $request, int $project, int $task): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $ticket = $this->hris->findTicket(
        $category,
        $task,
        $request->user(),
        $request->boolean('trashed')
      );

      return response()->json([
        'data' => $this->hris->ticketToTaskArray($ticket),
      ]);
    }

    $project = Project::findOrFail($project);
    $task = Task::findOrFail($task);
    $this->authorizeProject($request, $project);
    $this->ensureTaskBelongsToProject($task, $project);

    return response()->json([
      'data' => new TaskResource(
        $task->load(['assignee', 'labels', 'creator', 'comments'])
      ),
    ]);
  }

  public function restore(Request $request, int $project, int $task): JsonResponse
  {
    if (! config('managementpro.hris_mode')) {
      abort(404);
    }

    $category = $this->hris->findCategory($request->user(), $project);
    $ticket = $this->hris->findTicket($category, $task, $request->user(), true);
    $ticket = $this->hris->restoreTicket($category, $ticket, $request->user());

    return response()->json([
      'message' => 'Tiket dipulihkan',
      'data' => $this->hris->ticketToTaskArray($ticket),
    ]);
  }

  public function forceDestroy(Request $request, int $project, int $task): JsonResponse
  {
    if (! config('managementpro.hris_mode')) {
      abort(404);
    }

    $category = $this->hris->findCategory($request->user(), $project);
    $ticket = $this->hris->findTicket($category, $task, $request->user(), true);
    $this->hris->forceDeleteTicket($category, $ticket, $request->user());

    return response()->json(['message' => 'Tiket dihapus permanen']);
  }

  public function update(Request $request, int $project, int $task): JsonResponse
  {
    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'status' => ['sometimes', 'in:backlog,todo,in_progress,review,done'],
      'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
      'position' => ['sometimes', 'integer', 'min:0'],
      'assignee_id' => ['nullable', 'exists:users,id'],
      'due_date' => ['nullable', 'date'],
      'due_at' => ['nullable', 'string'],
      'hd_sub_categories_id' => ['nullable', 'integer'],
      'story_points' => ['nullable', 'integer', 'min:0'],
      'estimated_hours' => ['nullable', 'integer', 'min:0'],
      'logged_hours' => ['sometimes', 'integer', 'min:0'],
      'label_ids' => ['nullable', 'array'],
      'label_ids.*' => ['exists:labels,id'],
    ]);

    if (isset($validated['due_at']) && ! isset($validated['due_date'])) {
      $validated['due_date'] = $validated['due_at'];
    }

    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $ticket = $this->hris->findTicket($category, $task, $request->user());
      $ticket = $this->hris->updateTicket($ticket, $validated, $request->user());

      return response()->json([
        'data' => $this->hris->ticketToTaskArray($ticket),
      ]);
    }

    $project = Project::findOrFail($project);
    $task = Task::findOrFail($task);
    $this->authorizeProject($request, $project);
    $this->ensureTaskBelongsToProject($task, $project);

    if (isset($validated['status'])) {
      $validated['completed_at'] = $validated['status'] === 'done' ? now() : null;

      if (! $task->assignee_id
        && $task->status === 'backlog'
        && $validated['status'] !== 'backlog') {
        $validated['assignee_id'] = $request->user()->id;
      }
    }

    if (array_key_exists('due_at', $validated)) {
      $parsed = AppDateTime::parseDueInput($validated['due_at']);
      $validated['due_date'] = $parsed?->toDateString();
    }

    $task->update(collect($validated)->except(['label_ids', 'due_at'])->toArray());

    if (array_key_exists('label_ids', $validated)) {
      $task->labels()->sync($validated['label_ids'] ?? []);
    }

    ActivityLogger::log(
      $project,
      $request->user(),
      'task.updated',
      "memperbarui task \"{$task->title}\"",
      Task::class,
      $task->id
    );

    $this->syncProjectProgress($project);

    return response()->json([
      'data' => new TaskResource($task->fresh(['assignee', 'labels', 'creator'])),
    ]);
  }

  public function reorder(Request $request, int $project): JsonResponse
  {
    $validated = $request->validate([
      'tasks' => ['required', 'array'],
      'tasks.*.id' => ['required', 'integer'],
      'tasks.*.status' => ['required', 'in:backlog,todo,in_progress,review,done'],
      'tasks.*.position' => ['required', 'integer', 'min:0'],
    ]);

    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $tickets = $this->hris->reorderTickets($category, $request->user(), $validated['tasks']);

      return response()->json([
        'data' => $tickets->values()->map(
          fn (HdTicket $t, int $i) => $this->hris->ticketToTaskArray($t, $i + 1)
        ),
      ]);
    }

    $project = Project::findOrFail($project);
    $this->authorizeProject($request, $project);

    foreach ($validated['tasks'] as $item) {
      $task = Task::where('id', $item['id'])
        ->where('project_id', $project->id)
        ->first();

      if (! $task) {
        continue;
      }

      $update = [
        'status' => $item['status'],
        'position' => $item['position'],
        'completed_at' => $item['status'] === 'done' ? now() : null,
      ];

      if (! $task->assignee_id
        && $task->status === 'backlog'
        && $item['status'] !== 'backlog') {
        $update['assignee_id'] = $request->user()->id;
      }

      $task->update($update);
    }

    $this->syncProjectProgress($project);

    $tasks = $project->tasks()
      ->with(['assignee', 'labels'])
      ->orderBy('position')
      ->get();

    return response()->json([
      'data' => TaskResource::collection($tasks),
    ]);
  }

  public function destroy(Request $request, int $project, int $task): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $category = $this->hris->findCategory($request->user(), $project);
      $ticket = $this->hris->findTicket($category, $task, $request->user());
      $this->hris->deleteTicket($category, $ticket, $request->user());

      return response()->json(['message' => 'Tiket dihapus (soft delete). Dapat dipulihkan dari tab Terhapus.']);
    }

    $project = Project::findOrFail($project);
    $task = Task::findOrFail($task);
    $this->authorizeProject($request, $project);
    $this->ensureTaskBelongsToProject($task, $project);

    $title = $task->title;
    $task->delete();

    ActivityLogger::log(
      $project,
      $request->user(),
      'task.deleted',
      "menghapus task \"{$title}\""
    );

    $this->syncProjectProgress($project);

    return response()->json(['message' => 'Task dihapus']);
  }

  private function authorizeProject(Request $request, Project $project): void
  {
    if (! $project->hasMember($request->user())) {
      abort(403);
    }
  }

  private function ensureTaskBelongsToProject(Task $task, Project $project): void
  {
    if ($task->project_id !== $project->id) {
      abort(404);
    }
  }

  private function syncProjectProgress(Project $project): void
  {
    $total = $project->tasks()->count();
    if ($total === 0) {
      $project->update(['progress' => 0]);

      return;
    }

    $done = $project->tasks()->where('status', 'done')->count();
    $project->update(['progress' => (int) round(($done / $total) * 100)]);
  }
}
