<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdCategory;
use App\Models\HdProject;
use App\Models\HdSubCategory;
use App\Services\HrisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrisProjectController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            return response()->json(['data' => []]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical,urgent'],
            'project_id' => ['nullable', 'integer'],
            'hd_sub_categories_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:end_asc,end_desc,updated_desc,created_desc,priority_desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'trashed' => ['nullable', 'string', 'in:only'],
        ]);

        $user = $request->user();
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');
        $table = (new HdProject)->getTable();
        $onlyTrashed = ($filters['trashed'] ?? null) === 'only';

        $query = HdProject::query()
            ->inActiveSubCategory()
            ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
            ->with(['subCategory.category', 'reporter.employee', 'assignees.employee'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done'),
            ]);

        if ($onlyTrashed) {
            $query->onlyTrashed();
        }

        if (! empty($filters['project_id'])) {
            $query->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
        }

        if (! empty($filters['hd_sub_categories_id'])) {
            $query->where("{$table}.hd_sub_categories_id", $filters['hd_sub_categories_id']);
        }

        if (! empty($filters['status']) && ! $onlyTrashed) {
            $query->where("{$table}.status", $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where("{$table}.priority", $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term, $table) {
                $q->where("{$table}.subject", 'like', $term)
                    ->orWhere("{$table}.project_number", 'like', $term)
                    ->orWhere("{$table}.description", 'like', $term);
            });
        }

        match ($filters['sort'] ?? 'updated_desc') {
            'end_asc' => $query->orderByRaw("{$table}.end_date IS NULL, {$table}.end_date ASC"),
            'end_desc' => $query->orderByRaw("{$table}.end_date IS NULL")->orderByDesc("{$table}.end_date"),
            'created_desc' => $query->orderByDesc("{$table}.created_at"),
            'priority_desc' => $query->orderByRaw("FIELD({$table}.priority, 'critical', 'high', 'medium', 'low')"),
            default => $query->orderByDesc("{$table}.updated_at"),
        };

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn ($p) => $this->hris->hdProjectToArray($p, 0, $user))->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'hd_sub_categories_id' => ['required', 'integer', 'exists:hd_sub_categories,id'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        if (empty($validated['assignee_ids'])) {
            $validated['assignee_ids'] = [$user->id];
        }

        $created = $this->hris->createHdProjectForUser($user, $validated);

        return response()->json([
            'data' => $this->hris->hdProjectToArray($created, 0, $user),
        ], 201);
    }

    public function show(Request $request, int $hris_project): JsonResponse
    {
        $user = $request->user();
        $item = $this->hris->findAccessibleHdProject($user, $hris_project, $request->boolean('trashed'));

        return response()->json(['data' => $this->hris->hdProjectToArray($item, 0, $user)]);
    }

    public function update(Request $request, int $hris_project): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'hd_sub_categories_id' => ['sometimes', 'integer'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assignee_ids' => ['sometimes', 'array', 'min:1'],
            'assignee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $item = $this->hris->findAccessibleHdProject($user, $hris_project);
        $this->hris->assertCanManageHdProject($item, $user);
        $item = $this->hris->updateHdProject($item, $validated, $user);

        return response()->json(['data' => $this->hris->hdProjectToArray($item, 0, $user)]);
    }

    public function destroy(Request $request, int $hris_project): JsonResponse
    {
        $user = $request->user();
        $item = $this->hris->findAccessibleHdProject($user, $hris_project);
        $this->hris->deleteHdProject($item, $user);

        return response()->json(['message' => 'Project dihapus (soft delete). Dapat dipulihkan dari tab Terhapus.']);
    }

    public function restore(Request $request, int $hris_project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $user = $request->user();
        $item = $this->hris->findAccessibleHdProject($user, $hris_project, true);
        $item = $this->hris->restoreHdProject($item, $user);

        return response()->json([
            'message' => 'Project dipulihkan',
            'data' => $this->hris->hdProjectToArray($item, 0, $user),
        ]);
    }

    public function forceDestroy(Request $request, int $hris_project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $user = $request->user();
        $item = $this->hris->findAccessibleHdProject($user, $hris_project, true);
        $this->hris->forceDeleteHdProject($item, $user);

        return response()->json(['message' => 'Project dihapus permanen']);
    }

    /** Sub-kategori & kategori untuk form create (HRIS). */
    public function formOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $categories = HdCategory::query()
            ->accessibleBy($user)
            ->with(['subCategories' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->filter(fn ($c) => $c->subCategories->isNotEmpty())
            ->values();

        return response()->json([
            'data' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sub_categories' => $c->subCategories->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'sla_minutes' => $s->sla_minutes,
                ])->values(),
            ])->values(),
        ]);
    }
}
