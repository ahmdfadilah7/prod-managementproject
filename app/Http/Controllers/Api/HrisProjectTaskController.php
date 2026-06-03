<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HrisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrisProjectTaskController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request, int $hris_project): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $items = $this->hris->listHdProjectTasks($project, $request->user(), $request->only('trashed'));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, int $hris_project): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required_without:subject', 'string', 'max:255'],
            'subject' => ['required_without:title', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:backlog,todo,in_progress,review,done'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $task = $this->hris->createHdProjectTask($project, $request->user(), $validated);

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray(
                $task->load(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']),
                0,
                $request->user()
            ),
        ], 201);
    }

    public function show(Request $request, int $hris_project, int $task): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask(
            $project,
            $task,
            $request->user(),
            $request->boolean('trashed')
        );

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray($item, 0, $request->user()),
        ]);
    }

    public function update(Request $request, int $hris_project, int $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:backlog,todo,in_progress,review,done'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());
        $item = $this->hris->updateHdProjectTask($project, $item, $validated, $request->user());

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray(
                $item->load(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']),
                0,
                $request->user()
            ),
        ]);
    }

    public function reorder(Request $request, int $hris_project): JsonResponse
    {
        $validated = $request->validate([
            'tasks' => ['required', 'array'],
            'tasks.*.id' => ['required', 'integer'],
            'tasks.*.status' => ['required', 'in:backlog,todo,in_progress,review,done'],
            'tasks.*.position' => ['required', 'integer', 'min:0'],
        ]);

        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $items = $this->hris->reorderHdProjectTasks($project, $request->user(), $validated['tasks']);

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, int $hris_project, int $task): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());
        $this->hris->deleteHdProjectTask($project, $item, $request->user());

        return response()->json(['message' => 'Task project dihapus']);
    }

    public function restore(Request $request, int $hris_project, int $task): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user(), true);
        $item = $this->hris->restoreHdProjectTask($project, $item, $request->user());

        return response()->json([
            'message' => 'Task project dipulihkan',
            'data' => $this->hris->hdProjectTaskToArray($item, 0, $request->user()),
        ]);
    }

    public function forceDestroy(Request $request, int $hris_project, int $task): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user(), true);
        $this->hris->forceDeleteHdProjectTask($project, $item, $request->user());

        return response()->json(['message' => 'Task project dihapus permanen']);
    }
}
