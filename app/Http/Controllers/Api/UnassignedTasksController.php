<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\HdCategory;
use App\Models\HdProjectTask;
use App\Models\HdTicket;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\HrisWorkspaceService;
use App\Support\AppDateTime;
use App\Support\Hris\TicketStatusMapper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
class UnassignedTasksController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:backlog,todo,in_progress,review,done'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical,urgent'],
            'project_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:sla_asc,sla_desc,updated_desc,updated_asc,priority_desc,priority_asc,created_desc'],
            'overdue_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        if (config('managementpro.hris_mode')) {
            return $this->indexHris($request, $filters);
        }

        return $this->indexStandard($request, $filters);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignee_id' => ['required', 'exists:users,id'],
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.task_type' => ['nullable', 'string', 'in:ticket,hris_project_task'],
            'tasks.*.project_id' => ['required', 'integer'],
            'tasks.*.id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $updated = 0;
        $errors = [];

        foreach ($validated['tasks'] as $item) {
            try {
                if (config('managementpro.hris_mode')) {
                    $taskType = $item['task_type'] ?? 'ticket';

                    if ($taskType === 'hris_project_task') {
                        $project = $this->hris->findAccessibleHdProject($user, (int) $item['project_id']);
                        $task = $this->hris->findHdProjectTask($project, (int) $item['id'], $user);
                        if ($task->assigned_to) {
                            $errors[] = ['id' => $item['id'], 'message' => 'Sudah memiliki assignee.'];

                            continue;
                        }
                        $this->hris->updateHdProjectTask($project, $task, [
                            'assignee_id' => $validated['assignee_id'],
                        ], $user);
                    } else {
                        $category = $this->hris->findCategory($user, (int) $item['project_id']);
                        $ticket = $this->hris->findTicket($category, (int) $item['id'], $user);
                        if ($ticket->assigned_to) {
                            $errors[] = ['id' => $item['id'], 'message' => 'Sudah memiliki assignee.'];

                            continue;
                        }
                        $this->hris->updateTicket($ticket, ['assignee_id' => $validated['assignee_id']], $user);
                    }
                } else {
                    $project = Project::findOrFail($item['project_id']);
                    if (! $project->hasMember($user)) {
                        $errors[] = ['id' => $item['id'], 'message' => 'Akses proyek ditolak.'];

                        continue;
                    }
                    $task = Task::where('project_id', $project->id)->findOrFail($item['id']);
                    if ($task->assignee_id) {
                        $errors[] = ['id' => $item['id'], 'message' => 'Sudah memiliki assignee.'];

                        continue;
                    }
                    $task->update(['assignee_id' => $validated['assignee_id']]);
                }
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

    protected function indexHris(Request $request, array $filters): JsonResponse
    {
        $user = $request->user();

        $ticketRows = $this->queryUnassignedTickets($filters)->get()->map(function (HdTicket $t) {
            $arr = $this->hris->ticketToTaskArray($t);
            $arr['task_type'] = 'ticket';
            $arr['category'] = $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null;
            $arr['category_id'] = $t->hd_categories_id;
            $arr['project'] = $arr['category'];
            $arr['project_id'] = $t->hd_categories_id;

            return $arr;
        });

        $projectTaskRows = $this->queryUnassignedHdProjectTasks($user, $filters)->get()
            ->map(function (HdProjectTask $task) use ($user) {
                $arr = $this->hris->hdProjectTaskToArray($task, 0, $user);
                $project = $task->hdProject;
                $category = $project?->subCategory?->category;

                $arr['task_type'] = 'hris_project_task';
                $arr['hris_project_id'] = $task->hd_projects_id;
                $arr['category'] = $category ? ['id' => $category->id, 'name' => $category->name] : null;
                $arr['category_id'] = $category?->id;
                $arr['project'] = $project ? ['id' => $project->id, 'name' => $project->subject] : null;
                $arr['project_id'] = $task->hd_projects_id;
                $arr['hris_project_end_date'] = AppDateTime::toIso($project?->end_date);
                $arr['is_past_project_deadline'] = $this->hrisProjectTaskPastProjectDeadline(
                    $task->end_date ?? $task->start_date,
                    $project?->end_date,
                    $task->status
                );

                return $arr;
            });

        $merged = $this->sortUnassignedRows(
            $ticketRows->concat($projectTaskRows),
            $filters['sort'] ?? 'sla_asc'
        );

        $stats = $this->buildMergedUnassignedHrisStats($ticketRows, $projectTaskRows);

        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 25)));
        $total = $merged->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        return response()->json([
            'data' => $merged->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'stats' => $stats,
            ],
        ]);
    }

    protected function queryUnassignedTickets(array $filters)
    {
        $query = HdTicket::query()
            ->inActiveCategory()
            ->whereNull('assigned_to')
            ->whereNotIn('status', ['closed'])
            ->with(['category', 'subCategory', 'reporter.employee']);

        if (! empty($filters['project_id'])) {
            $query->where('hd_categories_id', $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', TicketStatusMapper::toTicket($filters['status']));
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', TicketStatusMapper::boardToTicketPriority($filters['priority']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('ticket_number', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (! empty($filters['overdue_only'])) {
            $query->whereNotNull('sla_deadline')->where('sla_deadline', '<', now());
        }

        return $query;
    }

    protected function queryUnassignedHdProjectTasks(User $user, array $filters)
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        $query = HdProjectTask::query()
            ->whereNull('assigned_to')
            ->where('status', '!=', 'done')
            ->whereHas('hdProject', function ($q) use ($categoryIds) {
                $q->inActiveSubCategory()
                    ->whereHas('subCategory', fn ($sq) => $sq->whereIn('hd_categories_id', $categoryIds));
            })
            ->with(['hdProject.subCategory.category', 'reporter.employee']);

        if (! empty($filters['project_id'])) {
            $query->whereHas('hdProject.subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('task_number', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (! empty($filters['overdue_only'])) {
            $query->where('status', '!=', 'done')
                ->whereHas('hdProject', function ($q) {
                    $q->whereNotNull('end_date')
                        ->whereRaw(
                            'COALESCE(hd_project_tasks.end_date, hd_project_tasks.start_date) > hd_projects.end_date'
                        );
                });
        }

        return $query;
    }

    protected function hrisProjectTaskPastProjectDeadline($taskEndDate, $projectEndDate, string $status): bool
    {
        if ($status === 'done' || ! $taskEndDate || ! $projectEndDate) {
            return false;
        }

        $tz = AppDateTime::displayTimezone();
        $end = Carbon::parse($taskEndDate)->timezone($tz)->startOfDay();
        $deadline = Carbon::parse($projectEndDate)->timezone($tz)->endOfDay();

        return $end->gt($deadline);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortUnassignedRows(Collection $rows, string $sort): Collection
    {
        $priorityRank = [
            'critical' => 0,
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
        ];

        return $rows->sort(function (array $a, array $b) use ($sort, $priorityRank) {
            $dueA = $a['due_at'] ?? $a['due_date'] ?? (($a['task_type'] ?? '') === 'hris_project_task' ? ($a['end_date'] ?? $a['start_date'] ?? null) : null);
            $dueB = $b['due_at'] ?? $b['due_date'] ?? (($b['task_type'] ?? '') === 'hris_project_task' ? ($b['end_date'] ?? $b['start_date'] ?? null) : null);
            $tsA = $dueA ? strtotime($dueA) : PHP_INT_MAX;
            $tsB = $dueB ? strtotime($dueB) : PHP_INT_MAX;

            return match ($sort) {
                'sla_desc' => $tsB <=> $tsA ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
                'updated_asc' => ($a['updated_at'] ?? '') <=> ($b['updated_at'] ?? ''),
                'updated_desc' => ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''),
                'created_desc' => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''),
                'priority_desc' => ($priorityRank[$a['priority'] ?? 'medium'] ?? 9) <=> ($priorityRank[$b['priority'] ?? 'medium'] ?? 9)
                    ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
                'priority_asc' => ($priorityRank[$b['priority'] ?? 'medium'] ?? 9) <=> ($priorityRank[$a['priority'] ?? 'medium'] ?? 9)
                    ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
                default => $tsA <=> $tsB ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
            };
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ticketRows
     * @param  Collection<int, array<string, mixed>>  $projectTaskRows
     * @return array<string, mixed>
     */
    protected function buildMergedUnassignedHrisStats(Collection $ticketRows, Collection $projectTaskRows): array
    {
        $byStatus = [];
        $byPriority = [];

        foreach ($ticketRows->concat($projectTaskRows) as $row) {
            $status = $row['status'] ?? 'todo';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $priority = $row['priority'] ?? 'medium';
            $byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;
        }

        $overdueCount = $ticketRows->filter(fn ($r) => $this->unassignedRowIsOverdue($r))->count()
            + $projectTaskRows->filter(fn ($r) => $this->unassignedRowIsOverdue($r))->count();

        $categoryCounts = [];
        foreach ($ticketRows as $row) {
            $cid = $row['category_id'] ?? null;
            if ($cid) {
                $categoryCounts[$cid] = ($categoryCounts[$cid] ?? 0) + 1;
            }
        }
        foreach ($projectTaskRows as $row) {
            $cid = $row['category_id'] ?? null;
            if ($cid) {
                $categoryCounts[$cid] = ($categoryCounts[$cid] ?? 0) + 1;
            }
        }

        $categoryNames = HdCategory::query()
            ->whereIn('id', array_keys($categoryCounts))
            ->pluck('name', 'id');

        $byCategory = collect($categoryCounts)
            ->map(fn ($count, $id) => [
                'id' => (int) $id,
                'name' => $categoryNames[$id] ?? '—',
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'total' => $ticketRows->count() + $projectTaskRows->count(),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'overdue_count' => $overdueCount,
            'by_category' => $byCategory,
            'ticket_count' => $ticketRows->count(),
            'hris_project_task_count' => $projectTaskRows->count(),
        ];
    }

    protected function unassignedRowIsOverdue(array $row): bool
    {
        if (($row['status'] ?? '') === 'done') {
            return false;
        }

        if (($row['task_type'] ?? '') === 'hris_project_task') {
            return (bool) ($row['is_past_project_deadline'] ?? false);
        }

        $due = $row['due_at'] ?? $row['due_date'] ?? null;

        return $due && strtotime($due) < time();
    }

    protected function indexStandard(Request $request, array $filters): JsonResponse
    {
        $user = $request->user();
        $projectIds = Project::query()->accessibleBy($user)->pluck('id');

        $query = Task::query()
            ->with(['project:id,name', 'creator', 'labels'])
            ->whereIn('project_id', $projectIds)
            ->whereNull('assignee_id')
            ->whereNot('status', 'done');

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where('title', 'like', $term);
        }

        if (! empty($filters['overdue_only'])) {
            $query->whereNotNull('due_date')->where('due_date', '<', now()->toDateString());
        }

        $this->applyStandardSort($query, $filters['sort'] ?? 'sla_asc');

        $stats = $this->buildStandardStats(clone $query);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => TaskResource::collection(collect($paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => $stats,
            ],
        ]);
    }

    protected function applyHrisSort($query, string $sort): void
    {
        match ($sort) {
            'sla_desc' => $query->orderByRaw('sla_deadline IS NULL')->orderByDesc('sla_deadline'),
            'updated_asc' => $query->orderBy('updated_at'),
            'updated_desc' => $query->orderByDesc('updated_at'),
            'created_desc' => $query->orderByDesc('created_at'),
            'priority_desc' => $query->orderByRaw(
                "FIELD(priority, 'critical', 'high', 'medium', 'low')"
            ),
            'priority_asc' => $query->orderByRaw(
                "FIELD(priority, 'low', 'medium', 'high', 'critical')"
            ),
            default => $query->orderByRaw('sla_deadline IS NULL, sla_deadline ASC')
                ->orderByDesc('updated_at'),
        };
    }

    protected function applyStandardSort($query, string $sort): void
    {
        match ($sort) {
            'sla_desc' => $query->orderByRaw('due_date IS NULL')->orderByDesc('due_date'),
            'updated_asc' => $query->orderBy('updated_at'),
            'updated_desc' => $query->orderByDesc('updated_at'),
            'created_desc' => $query->orderByDesc('created_at'),
            'priority_desc' => $query->orderByRaw(
                "FIELD(priority, 'critical', 'urgent', 'high', 'medium', 'low')"
            ),
            'priority_asc' => $query->orderByRaw(
                "FIELD(priority, 'low', 'medium', 'high', 'critical', 'urgent')"
            ),
            default => $query->orderByRaw('due_date IS NULL, due_date ASC')
                ->orderByDesc('updated_at'),
        };
    }

    protected function buildHrisStats($query): array
    {
        $total = (clone $query)->count();

        $byTicketStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach ($byTicketStatus as $ticketStatus => $count) {
            $board = TicketStatusMapper::toBoard($ticketStatus);
            $byStatus[$board] = ($byStatus[$board] ?? 0) + (int) $count;
        }

        $byPriority = [];
        foreach (
            (clone $query)->selectRaw('priority, COUNT(*) as aggregate')->groupBy('priority')->pluck('aggregate', 'priority')
            as $priority => $count
        ) {
            $board = TicketStatusMapper::priorityToBoard($priority);
            $byPriority[$board] = ($byPriority[$board] ?? 0) + (int) $count;
        }

        $overdueCount = (clone $query)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->count();

        $categoryCounts = (clone $query)
            ->selectRaw('hd_categories_id, COUNT(*) as aggregate')
            ->groupBy('hd_categories_id')
            ->pluck('aggregate', 'hd_categories_id');

        $categoryNames = HdCategory::query()
            ->whereIn('id', $categoryCounts->keys()->filter())
            ->pluck('name', 'id');

        $byCategory = $categoryCounts
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

    protected function buildStandardStats($query): array
    {
        $total = (clone $query)->count();

        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $byPriority = (clone $query)
            ->selectRaw('priority, COUNT(*) as aggregate')
            ->groupBy('priority')
            ->pluck('aggregate', 'priority')
            ->map(fn ($c) => (int) $c)
            ->all();

        $overdueCount = (clone $query)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $projectCounts = (clone $query)
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $projectNames = Project::query()
            ->whereIn('id', $projectCounts->keys()->filter())
            ->pluck('name', 'id');

        $byCategory = $projectCounts
            ->map(fn ($count, $id) => [
                'id' => (int) $id,
                'name' => $projectNames[$id] ?? '—',
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
