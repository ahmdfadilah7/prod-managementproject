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
use App\Support\Hris\TicketStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MyTasksController extends Controller
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
        ]);

        $user = $request->user();

        if (config('managementpro.hris_mode')) {
            return $this->indexHris($user, $filters);
        }

        return $this->indexStandard($user, $filters);
    }

    protected function indexHris(User $user, array $filters): JsonResponse
    {
        $tickets = $this->queryMyHrisTickets($user, $filters)->get();
        $projectTasks = $this->queryMyHdProjectTasks($user, $filters)->get();

        $ticketRows = $tickets->map(function (HdTicket $t) {
            $arr = $this->hris->ticketToTaskArray($t);
            $arr['task_type'] = 'ticket';
            $arr['category'] = $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null;
            $arr['category_id'] = $t->hd_categories_id;
            $arr['project'] = $arr['category'];
            $arr['project_id'] = $t->hd_categories_id;

            return $arr;
        });

        $projectTaskRows = $projectTasks->map(function (HdProjectTask $task) use ($user) {
            $arr = $this->hris->hdProjectTaskToArray($task, 0, $user);
            $project = $task->hdProject;
            $category = $project?->subCategory?->category;

            $arr['task_type'] = 'hris_project_task';
            $arr['hris_project_id'] = $task->hd_projects_id;
            $arr['category'] = $category ? ['id' => $category->id, 'name' => $category->name] : null;
            $arr['category_id'] = $category?->id;
            $arr['project'] = $project ? ['id' => $project->id, 'name' => $project->subject] : null;
            $arr['project_id'] = $task->hd_projects_id;
            return $arr;
        });

        $data = $this->sortMyTaskRows($ticketRows->concat($projectTaskRows), $filters['sort'] ?? 'sla_asc');
        $stats = $this->buildMergedHrisStats($ticketRows, $projectTaskRows);

        return response()->json([
            'data' => $data,
            'meta' => ['stats' => $stats, 'total' => $data->count()],
        ]);
    }

    protected function queryMyHrisTickets(User $user, array $filters)
    {
        $query = HdTicket::query()
            ->inActiveCategory()
            ->with(['category', 'subCategory', 'assignee.employee', 'reporter.employee'])
            ->where('assigned_to', $user->id);

        if (! empty($filters['status'])) {
            $query->where('status', TicketStatusMapper::toTicket($filters['status']));
        } else {
            $query->whereNotIn('status', ['closed']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('hd_categories_id', $filters['project_id']);
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

    protected function queryMyHdProjectTasks(User $user, array $filters)
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        $query = HdProjectTask::query()
            ->where('assigned_to', $user->id)
            ->whereHas('hdProject', function ($q) use ($categoryIds) {
                $q->inActiveSubCategory()
                    ->whereHas('subCategory', fn ($sq) => $sq->whereIn('hd_categories_id', $categoryIds));
            })
            ->with(['hdProject.subCategory.category', 'assignee.employee', 'reporter.employee']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', 'done');
        }

        if (! empty($filters['project_id'])) {
            $query->whereHas('hdProject.subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
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
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('end_date')->where('end_date', '<', now());
                    })->orWhere(function ($q2) {
                        $q2->whereNull('end_date')
                            ->whereNotNull('start_date')
                            ->where('start_date', '<', now());
                    });
                });
        }

        return $query;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortMyTaskRows(Collection $rows, string $sort): Collection
    {
        $priorityRank = [
            'critical' => 0,
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
        ];

        return $rows->sort(function (array $a, array $b) use ($sort, $priorityRank) {
            $dueA = ($a['task_type'] ?? '') === 'hris_project_task'
                ? ($a['end_date'] ?? $a['start_date'] ?? null)
                : ($a['due_at'] ?? $a['due_date'] ?? null);
            $dueB = ($b['task_type'] ?? '') === 'hris_project_task'
                ? ($b['end_date'] ?? $b['start_date'] ?? null)
                : ($b['due_at'] ?? $b['due_date'] ?? null);
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
    protected function buildMergedHrisStats(Collection $ticketRows, Collection $projectTaskRows): array
    {
        $byStatus = [];

        foreach ($ticketRows as $row) {
            $status = $row['status'] ?? 'todo';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        foreach ($projectTaskRows as $row) {
            $status = $row['status'] ?? 'todo';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $overdueCount = $ticketRows->filter(fn ($r) => $this->rowIsOverdue($r))->count()
            + $projectTaskRows->filter(fn ($r) => $this->rowIsOverdue($r))->count();

        return [
            'total' => $ticketRows->count() + $projectTaskRows->count(),
            'by_status' => $byStatus,
            'overdue_count' => $overdueCount,
            'ticket_count' => $ticketRows->count(),
            'hris_project_task_count' => $projectTaskRows->count(),
        ];
    }

    protected function rowIsOverdue(array $row): bool
    {
        if (($row['status'] ?? '') === 'done') {
            return false;
        }

        if (($row['task_type'] ?? '') === 'hris_project_task') {
            $at = $row['end_date'] ?? $row['start_date'] ?? null;

            return $at && strtotime($at) < time();
        }

        $due = $row['due_at'] ?? $row['due_date'] ?? null;

        return $due && strtotime($due) < time();
    }

    protected function indexStandard($user, array $filters): JsonResponse
    {
        $projectIds = Project::query()->accessibleBy($user)->pluck('id');

        $query = Task::with(['project:id,name', 'assignee', 'creator', 'labels'])
            ->whereIn('project_id', $projectIds)
            ->where('assignee_id', $user->id);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->whereNot('status', 'done');
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
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
        $items = $query->get();

        return response()->json([
            'data' => TaskResource::collection($items),
            'meta' => ['stats' => $stats, 'total' => $items->count()],
        ]);
    }

    protected function applyHrisSort($query, string $sort): void
    {
        match ($sort) {
            'sla_desc' => $query->orderByRaw('sla_deadline IS NULL')->orderByDesc('sla_deadline'),
            'updated_asc' => $query->orderBy('updated_at'),
            'updated_desc' => $query->orderByDesc('updated_at'),
            'created_desc' => $query->orderByDesc('created_at'),
            'priority_desc' => $query->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')"),
            'priority_asc' => $query->orderByRaw("FIELD(priority, 'low', 'medium', 'high', 'critical')"),
            default => $query->orderByRaw('sla_deadline IS NULL, sla_deadline ASC')->orderByDesc('updated_at'),
        };
    }

    protected function applyStandardSort($query, string $sort): void
    {
        match ($sort) {
            'sla_desc' => $query->orderByRaw('due_date IS NULL')->orderByDesc('due_date'),
            'updated_asc' => $query->orderBy('updated_at'),
            'updated_desc' => $query->orderByDesc('updated_at'),
            'created_desc' => $query->orderByDesc('created_at'),
            'priority_desc' => $query->orderByRaw("FIELD(priority, 'critical', 'urgent', 'high', 'medium', 'low')"),
            'priority_asc' => $query->orderByRaw("FIELD(priority, 'low', 'medium', 'high', 'critical', 'urgent')"),
            default => $query->orderByRaw('due_date IS NULL, due_date ASC')->orderByDesc('updated_at'),
        };
    }

    protected function buildHrisStats($query): array
    {
        $base = (clone $query)->reorder();
        $total = (clone $base)->count();

        $byTicketStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach ($byTicketStatus as $ticketStatus => $count) {
            $board = TicketStatusMapper::toBoard($ticketStatus);
            $byStatus[$board] = ($byStatus[$board] ?? 0) + (int) $count;
        }

        $overdueCount = (clone $base)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->count();

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'overdue_count' => $overdueCount,
        ];
    }

    protected function buildStandardStats($query): array
    {
        $base = (clone $query)->reorder();
        $total = (clone $base)->count();

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $overdueCount = (clone $base)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'overdue_count' => $overdueCount,
        ];
    }
}
