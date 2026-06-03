<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdCategory;
use App\Models\HdProject;
use App\Services\HrisWorkspaceService;
use App\Support\AppDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UnassignedProjectsController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'stats' => []]]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical,urgent'],
            'project_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:end_asc,end_desc,start_asc,start_desc,updated_desc,updated_asc,priority_desc,priority_asc,created_desc'],
            'overdue_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = $request->user();
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');
        $table = (new HdProject)->getTable();

        $query = HdProject::query()
            ->inActiveSubCategory()
            ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
            ->whereDoesntHave('assignees')
            ->whereNotIn("{$table}.status", ['completed', 'archived'])
            ->with(['subCategory.category', 'reporter.employee']);

        if (! empty($filters['project_id'])) {
            $query->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
        }

        if (! empty($filters['status'])) {
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

        if (! empty($filters['overdue_only'])) {
            $query->whereNotNull("{$table}.end_date")
                ->where("{$table}.end_date", '<', now());
        }

        $this->applySort($query, $filters['sort'] ?? 'end_asc');

        $stats = $this->buildStats(clone $query);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function (HdProject $p) {
            $arr = $this->hris->hdProjectToArray($p, 0, $user);
            $arr['category'] = $p->subCategory?->category
                ? ['id' => $p->subCategory->category->id, 'name' => $p->subCategory->category->name]
                : null;
            $arr['category_id'] = $p->subCategory?->hd_categories_id;
            $arr['project_id'] = $p->subCategory?->hd_categories_id;
            $arr['is_overdue'] = $p->end_date
                && Carbon::parse($p->end_date)->timezone(AppDateTime::displayTimezone())->isPast()
                && $p->status !== 'done';

            return $arr;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => $stats,
            ],
        ]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignee_id' => ['required', 'exists:users,id'],
            'projects' => ['required', 'array', 'min:1', 'max:50'],
            'projects.*.project_id' => ['required', 'integer'],
            'projects.*.id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $updated = 0;
        $errors = [];

        foreach ($validated['projects'] as $item) {
            try {
                $project = $this->hris->findAccessibleHdProject($user, (int) $item['id']);
                if ($project->assignees()->exists()) {
                    $errors[] = ['id' => $item['id'], 'message' => 'Sudah memiliki assignee.'];

                    continue;
                }
                $this->hris->updateHdProject($project, [
                    'assignee_ids' => [$validated['assignee_id']],
                ], $user);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $item['id'], 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    protected function applySort($query, string $sort): void
    {
        $table = (new HdProject)->getTable();

        match ($sort) {
            'end_desc' => $query->orderByRaw("{$table}.end_date IS NULL")->orderByDesc("{$table}.end_date"),
            'start_asc' => $query->orderByRaw("{$table}.start_date IS NULL, {$table}.start_date ASC"),
            'start_desc' => $query->orderByRaw("{$table}.start_date IS NULL")->orderByDesc("{$table}.start_date"),
            'updated_asc' => $query->orderBy("{$table}.updated_at"),
            'updated_desc' => $query->orderByDesc("{$table}.updated_at"),
            'created_desc' => $query->orderByDesc("{$table}.created_at"),
            'priority_desc' => $query->orderByRaw(
                "FIELD({$table}.priority, 'critical', 'high', 'medium', 'low')"
            ),
            'priority_asc' => $query->orderByRaw(
                "FIELD({$table}.priority, 'low', 'medium', 'high', 'critical')"
            ),
            default => $query->orderByRaw("{$table}.end_date IS NULL, {$table}.end_date ASC")
                ->orderByDesc("{$table}.updated_at"),
        };
    }

    protected function buildStats($query): array
    {
        $table = (new HdProject)->getTable();
        $base = (clone $query)->reorder();

        $total = (clone $base)->count();

        $byStatus = (clone $base)
            ->selectRaw("{$table}.status, COUNT(*) as aggregate")
            ->groupBy("{$table}.status")
            ->pluck('aggregate', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $byPriority = (clone $base)
            ->selectRaw("{$table}.priority, COUNT(*) as aggregate")
            ->groupBy("{$table}.priority")
            ->pluck('aggregate', 'priority')
            ->map(fn ($c) => (int) $c)
            ->all();

        $overdueCount = (clone $base)
            ->whereNotNull("{$table}.end_date")
            ->where("{$table}.end_date", '<', now())
            ->count();

        $subCounts = (clone $base)
            ->join('hd_sub_categories', "{$table}.hd_sub_categories_id", '=', 'hd_sub_categories.id')
            ->selectRaw('hd_sub_categories.hd_categories_id as cat_id, COUNT(*) as aggregate')
            ->groupBy('hd_sub_categories.hd_categories_id')
            ->pluck('aggregate', 'cat_id');

        $categoryNames = HdCategory::query()
            ->whereIn('id', $subCounts->keys()->filter())
            ->pluck('name', 'id');

        $byCategory = $subCounts
            ->map(fn ($count, $id) => [
                'id' => (int) $id,
                'name' => $categoryNames[$id] ?? '—',
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'overdue_count' => $overdueCount,
            'by_category' => $byCategory,
        ];
    }
}
