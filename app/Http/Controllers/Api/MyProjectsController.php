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

class MyProjectsController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            return response()->json(['data' => [], 'meta' => ['stats' => [], 'total' => 0]]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical,urgent'],
            'project_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:end_asc,end_desc,start_asc,start_desc,updated_desc,updated_asc,priority_desc,priority_asc,created_desc'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');
        $table = (new HdProject)->getTable();

        $query = HdProject::query()
            ->inActiveSubCategory()
            ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->with(['subCategory.category', 'reporter.employee', 'assignees.employee'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done'),
            ]);

        if (! empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'backlog') {
                $query->whereIn("{$table}.status", ['backlog', 'pending']);
            } else {
                $query->where("{$table}.status", $status);
            }
        } else {
            $query->whereNotIn("{$table}.status", ['completed', 'archived']);
        }

        if (! empty($filters['project_id'])) {
            $query->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
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

        $this->applySort($query, $filters['sort'] ?? 'end_asc', $table);

        $stats = $this->buildStats(clone $query, $table);
        $items = $query->get();

        $data = $items->map(function (HdProject $project) {
            $arr = $this->hris->hdProjectToArray($project, 0, $user);
            $arr['category'] = $project->subCategory?->category
                ? [
                    'id' => $project->subCategory->category->id,
                    'name' => $project->subCategory->category->name,
                ]
                : null;
            $arr['category_id'] = $project->subCategory?->hd_categories_id;
            $arr['project_id'] = $project->subCategory?->hd_categories_id;
            $arr['is_overdue'] = $project->end_date
                && Carbon::parse($project->end_date)->timezone(AppDateTime::displayTimezone())->isPast()
                && $project->status !== 'done';

            return $arr;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => ['stats' => $stats, 'total' => $data->count()],
        ]);
    }

    protected function applySort($query, string $sort, string $table): void
    {
        match ($sort) {
            'end_desc' => $query->orderByRaw("{$table}.end_date IS NULL")->orderByDesc("{$table}.end_date"),
            'start_asc' => $query->orderByRaw("{$table}.start_date IS NULL, {$table}.start_date ASC"),
            'start_desc' => $query->orderByRaw("{$table}.start_date IS NULL")->orderByDesc("{$table}.start_date"),
            'updated_asc' => $query->orderBy("{$table}.updated_at"),
            'updated_desc' => $query->orderByDesc("{$table}.updated_at"),
            'created_desc' => $query->orderByDesc("{$table}.created_at"),
            'priority_desc' => $query->orderByRaw("FIELD({$table}.priority, 'critical', 'high', 'medium', 'low')"),
            'priority_asc' => $query->orderByRaw("FIELD({$table}.priority, 'low', 'medium', 'high', 'critical')"),
            default => $query->orderByRaw("{$table}.end_date IS NULL, {$table}.end_date ASC")
                ->orderByDesc("{$table}.updated_at"),
        };
    }

    protected function buildStats($query, string $table): array
    {
        $base = (clone $query)->reorder();
        $total = (clone $base)->count();

        $rawStatus = (clone $base)
            ->selectRaw("{$table}.status, COUNT(*) as aggregate")
            ->groupBy("{$table}.status")
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach ($rawStatus as $status => $count) {
            $key = $status === 'pending' ? 'backlog' : $status;
            $byStatus[$key] = ($byStatus[$key] ?? 0) + (int) $count;
        }

        $overdueCount = (clone $base)
            ->whereNotNull("{$table}.end_date")
            ->where("{$table}.end_date", '<', now())
            ->count();

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'overdue_count' => $overdueCount,
        ];
    }
}
