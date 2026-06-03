<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HrisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HdProjectController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request, int $project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            return response()->json(['data' => []]);
        }

        $cat = $this->hris->findCategory($request->user(), $project);
        $items = $this->hris->listHdProjects($cat, $request->user());

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, int $project): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'hd_sub_categories_id' => ['required', 'integer'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'status' => ['sometimes', 'in:backlog,todo,in_progress,review,done'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $cat = $this->hris->findCategory($request->user(), $project);
        $created = $this->hris->createHdProject($cat, $request->user(), $validated);

        return response()->json([
            'data' => $this->hris->hdProjectToArray($created),
        ], 201);
    }

    public function show(Request $request, int $project, int $hdProject): JsonResponse
    {
        $cat = $this->hris->findCategory($request->user(), $project);
        $item = $this->hris->findHdProject($cat, $hdProject, $request->user());

        return response()->json([
            'data' => $this->hris->hdProjectToArray($item),
        ]);
    }

    public function update(Request $request, int $project, int $hdProject): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'hd_sub_categories_id' => ['sometimes', 'integer'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'status' => ['sometimes', 'in:backlog,todo,in_progress,review,done'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $cat = $this->hris->findCategory($request->user(), $project);
        $item = $this->hris->findHdProject($cat, $hdProject, $request->user());
        $item = $this->hris->updateHdProject($item, $validated, $request->user());

        return response()->json([
            'data' => $this->hris->hdProjectToArray($item),
        ]);
    }

    public function reorder(Request $request, int $project): JsonResponse
    {
        $validated = $request->validate([
            'projects' => ['required', 'array'],
            'projects.*.id' => ['required', 'integer'],
            'projects.*.status' => ['required', 'in:backlog,todo,in_progress,review,done'],
            'projects.*.position' => ['required', 'integer', 'min:0'],
        ]);

        $cat = $this->hris->findCategory($request->user(), $project);
        $items = $this->hris->reorderHdProjects($cat, $request->user(), $validated['projects']);

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, int $project, int $hdProject): JsonResponse
    {
        $cat = $this->hris->findCategory($request->user(), $project);
        $item = $this->hris->findHdProject($cat, $hdProject, $request->user());
        $item->delete();

        return response()->json(['message' => 'Project dihapus']);
    }
}
