<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdCategory;
use App\Models\HdProject;
use App\Models\HdProjectTask;
use App\Models\HdTicket;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\HrisWorkspaceService;
use App\Support\AppDateTime;
use App\Support\Hris\TicketStatusMapper;
use App\Support\Spreadsheet\ProjectTaskReportExcelExporter;
use App\Support\Spreadsheet\TicketReportExcelExporter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportsController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['required', 'string', 'in:ticket,hris_project_task'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:backlog,todo,in_progress,review,done'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical,urgent'],
            'project_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:sla_asc,sla_desc,updated_desc,updated_asc,priority_desc,priority_asc,created_desc,start_date_asc,start_date_desc,work_date_asc,work_date_desc'],
            'overdue_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $user = $request->user();

        if (config('managementpro.hris_mode')) {
            return $filters['type'] === 'ticket'
                ? $this->indexHrisTickets($user, $filters)
                : $this->indexHrisProjectTasks($user, $filters);
        }

        return $this->indexStandardTasks($user, $filters);
    }

    public function exportTickets(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::parse($validated['date_from'])->startOfDay();
        $to = Carbon::parse($validated['date_to'])->endOfDay();

        $user = $request->user();

        $tickets = $this->queryReportTickets($user, [])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $headers = [
            'Kategori',
            'Sub Kategori',
            'No. Tiket',
            'Pelapor',
            'Subjek',
            'Deskripsi',
            'Prioritas',
            'Status',
            'Ditugaskan Kepada',
        ];

        $rows = $tickets->map(fn (HdTicket $ticket) => [
            $ticket->category?->name ?? '',
            $ticket->subCategory?->name ?? '—',
            $ticket->ticket_number ?? '',
            $ticket->reporter?->name ?? '—',
            $ticket->subject ?? '',
            $this->plainTextDescription($ticket->description),
            (string) ($ticket->priority ?? ''),
            (string) ($ticket->status ?? ''),
            $ticket->assignee?->name ?? '—',
        ])->all();

        $filename = sprintf(
            'laporan-tiket-%s_%s.xlsx',
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );

        try {
            return TicketReportExcelExporter::download(
                $filename,
                'Laporan Task Kategori (Tiket)',
                [
                    ['Periode', $from->format('d/m/Y').' s/d '.$to->format('d/m/Y')],
                    ['Total data', (string) count($rows).' tiket'],
                    ['Diekspor oleh', $user->name],
                    ['Dicetak pada', now()->format('d/m/Y H:i')],
                ],
                $headers,
                $rows
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Gagal membuat file Excel. Silakan coba lagi.',
            ], 500);
        }
    }

    public function exportProjectTasks(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'hd_project_ids' => ['nullable', 'array'],
            'hd_project_ids.*' => ['integer'],
        ]);

        $from = Carbon::parse($validated['date_from'])->startOfDay();
        $to = Carbon::parse($validated['date_to'])->endOfDay();
        $user = $request->user();

        $exportFilters = [];
        $projectFilterLabel = 'Semua project';

        if (! empty($validated['hd_project_ids'])) {
            $allowedIds = $this->resolveAccessibleHdProjectIds($user, $validated['hd_project_ids']);
            if ($allowedIds === []) {
                return response()->json([
                    'message' => 'Project tidak ditemukan atau tidak dapat diakses.',
                ], 422);
            }
            $exportFilters['hd_project_ids'] = $allowedIds;
            $projectFilterLabel = count($allowedIds).' project dipilih';
        }

        $tasks = $this->queryReportHdProjectTasks($user, $exportFilters)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('hd_projects_id')
            ->orderBy('created_at')
            ->get();

        $groups = $this->buildProjectTaskExportGroups($tasks);
        $totalTasks = $tasks->count();

        $filename = sprintf(
            'laporan-task-project-%s_%s.xlsx',
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );

        try {
            return ProjectTaskReportExcelExporter::download(
                $filename,
                'Laporan Task Project',
                [
                    ['Periode (task dibuat)', $from->format('d/m/Y').' s/d '.$to->format('d/m/Y')],
                    ['Filter project', $projectFilterLabel],
                    ['Total project', (string) count($groups)],
                    ['Total task', (string) $totalTasks.' task'],
                    ['Diekspor oleh', $user->name],
                    ['Dicetak pada', now()->format('d/m/Y H:i')],
                ],
                $groups
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Gagal membuat file Excel. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HdProjectTask>  $tasks
     * @return array<int, array{
     *   label: string,
     *   details: array<int, array{0: string, 1: string}>,
     *   tasks: array<int, array<int, string>>
     * }>
     */
    /**
     * @param  array<int, int>  $requestedIds
     * @return array<int, int>
     */
    protected function resolveAccessibleHdProjectIds(User $user, array $requestedIds): array
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        return HdProject::query()
            ->inActiveSubCategory()
            ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
            ->whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function buildProjectTaskExportGroups($tasks): array
    {
        $groups = [];

        $byProject = $tasks->groupBy('hd_projects_id')->sortBy(
            fn ($items) => mb_strtolower($items->first()->hdProject?->subject ?? 'zzz')
        );

        foreach ($byProject as $projectTasks) {
            $project = $projectTasks->first()->hdProject;
            $category = $project?->subCategory?->category;
            $sub = $project?->subCategory;

            $label = trim(($project?->project_number ? $project->project_number.' — ' : '').($project?->subject ?? 'Project'));

            $details = array_values(array_filter([
                $category?->name ? ['Kategori', $category->name] : null,
                $sub?->name ? ['Sub kategori', $sub->name] : null,
                $project?->status ? ['Status project', $this->hrisProjectLifecycleLabel($project->status)] : null,
                $project?->start_date ? ['Tanggal mulai', $this->formatExportDate($project->start_date)] : null,
                $project?->end_date ? ['Tanggal selesai', $this->formatExportDate($project->end_date)] : null,
            ]));

            $taskRows = $projectTasks->sortBy('created_at')->map(function (HdProjectTask $task) {
                return [
                    $task->task_number ?? '',
                    $task->subject ?? '',
                    $this->plainTextDescription($task->description),
                    $this->formatExportDate($task->start_date),
                    $this->formatExportDate($task->end_date),
                    (string) ($task->priority ?? ''),
                    (string) ($task->status ?? ''),
                    $task->reporter?->name ?? '—',
                    $task->assignee?->name ?? '—',
                    $this->formatExportDateTime($task->created_at),
                ];
            })->values()->all();

            $groups[] = [
                'label' => $label,
                'details' => $details,
                'tasks' => $taskRows,
            ];
        }

        return $groups;
    }

    protected function hrisProjectLifecycleLabel(string $status): string
    {
        return match ($status) {
            'planning' => 'Perencanaan',
            'active' => 'Aktif',
            'on_hold' => 'Ditahan',
            'completed' => 'Selesai',
            'archived' => 'Arsip',
            default => $status,
        };
    }

    protected function formatExportDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    protected function formatExportDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->format('d/m/Y H:i');
    }

    protected function plainTextDescription(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $normalized = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $normalized = preg_replace('/<\/p>/i', "\n", $normalized) ?? $normalized;
        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_map(
            fn (string $line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line),
            $lines
        );
        $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));

        return implode("\n", $lines);
    }

    protected function ticketStatusLabel(string $ticketStatus): string
    {
        $board = TicketStatusMapper::toBoard($ticketStatus);

        return match ($board) {
            'backlog' => 'Backlog',
            'todo' => 'To Do',
            'in_progress' => 'In Progress',
            'review' => 'Review',
            'done' => 'Done',
            default => $ticketStatus,
        };
    }

    protected function ticketPriorityLabel(string $priority): string
    {
        $board = TicketStatusMapper::priorityToBoard($priority);

        return match ($board) {
            'low' => 'Rendah',
            'medium' => 'Sedang',
            'high' => 'Tinggi',
            'critical' => 'Kritis',
            default => $priority,
        };
    }

    protected function indexHrisTickets(User $user, array $filters): JsonResponse
    {
        $query = $this->queryReportTickets($user, $filters);
        $allRows = $this->mapTicketRows($query->get());
        $stats = $this->buildTicketReportStats($allRows);
        $sorted = $this->sortReportRows($allRows, $filters['sort'] ?? 'sla_asc', 'ticket');
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, (int) request()->get('page', 1));
        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'type' => 'ticket',
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'stats' => $stats,
            ],
        ]);
    }

    protected function indexHrisProjectTasks(User $user, array $filters): JsonResponse
    {
        $query = $this->queryReportHdProjectTasks($user, $filters);
        $allRows = $this->mapProjectTaskRows($query->get(), $user);
        $stats = $this->buildProjectTaskReportStats($allRows);
        $sorted = $this->sortReportRows($allRows, $filters['sort'] ?? 'start_date_asc', 'hris_project_task');
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, (int) request()->get('page', 1));
        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'type' => 'hris_project_task',
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'stats' => $stats,
            ],
        ]);
    }

    protected function indexStandardTasks(User $user, array $filters): JsonResponse
    {
        $projectIds = Project::query()->accessibleBy($user)->pluck('id');

        $query = Task::with(['project:id,name', 'assignee', 'creator'])
            ->whereIn('project_id', $projectIds);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where('title', 'like', $term);
        }

        if (! empty($filters['overdue_only'])) {
            $query->whereNotNull('due_date')->where('due_date', '<', now()->toDateString());
        }

        $tasks = $query->orderByDesc('updated_at')->get();
        $rows = $tasks->map(fn (Task $t) => [
            'type' => 'task',
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'priority' => $t->priority,
            'project' => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name] : null,
            'project_id' => $t->project_id,
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'due_at' => AppDateTime::toIso(AppDateTime::parseDueInput($t->due_date)),
            'updated_at' => AppDateTime::toIso($t->updated_at),
        ]);

        return response()->json([
            'type' => 'task',
            'data' => $rows->values(),
            'meta' => [
                'total' => $rows->count(),
                'stats' => ['total' => $rows->count()],
            ],
        ]);
    }

    protected function queryReportTickets(User $user, array $filters)
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        $query = HdTicket::query()
            ->inActiveCategory()
            ->whereIn('hd_categories_id', $categoryIds)
            ->with(['category', 'subCategory', 'assignee.employee', 'reporter.employee']);

        if (! empty($filters['status'])) {
            $query->where('status', TicketStatusMapper::toTicket($filters['status']));
        }

        if (! empty($filters['project_id'])) {
            $query->where('hd_categories_id', $filters['project_id']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', TicketStatusMapper::boardToTicketPriority($filters['priority']));
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assigned_to', $filters['assignee_id']);
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

        return $query->orderByDesc('updated_at');
    }

    protected function queryReportHdProjectTasks(User $user, array $filters)
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        $query = HdProjectTask::query()
            ->whereHas('hdProject', function ($q) use ($categoryIds) {
                $q->inActiveSubCategory()
                    ->whereHas('subCategory', fn ($sq) => $sq->whereIn('hd_categories_id', $categoryIds));
            })
            ->with(['hdProject.subCategory.category', 'assignee.employee', 'reporter.employee']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['project_id'])) {
            $query->whereHas('hdProject.subCategory', fn ($q) => $q->where('hd_categories_id', $filters['project_id']));
        }

        if (! empty($filters['hd_project_ids'])) {
            $query->whereIn('hd_projects_id', $filters['hd_project_ids']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assigned_to', $filters['assignee_id']);
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

        return $query->orderByDesc('updated_at');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HdTicket>  $tickets
     * @return Collection<int, array<string, mixed>>
     */
    protected function mapTicketRows($tickets): Collection
    {
        return $tickets->map(function (HdTicket $t) {
            $arr = $this->hris->ticketToTaskArray($t);
            $arr['task_type'] = 'ticket';
            $arr['category'] = $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null;
            $arr['category_id'] = $t->hd_categories_id;
            $arr['sub_category'] = $t->subCategory ? ['id' => $t->subCategory->id, 'name' => $t->subCategory->name] : null;
            $arr['is_unassigned'] = $t->assigned_to === null;
            $arr['is_overdue'] = $this->ticketRowIsOverdue($arr);

            return $arr;
        })->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HdProjectTask>  $tasks
     * @return Collection<int, array<string, mixed>>
     */
    protected function mapProjectTaskRows($tasks, User $user): Collection
    {
        return $tasks->map(function (HdProjectTask $task) use ($user) {
            $arr = $this->hris->hdProjectTaskToArray($task, 0, $user);
            $project = $task->hdProject;
            $category = $project?->subCategory?->category;

            $arr['task_type'] = 'hris_project_task';
            $arr['hris_project_id'] = $task->hd_projects_id;
            $arr['category'] = $category ? ['id' => $category->id, 'name' => $category->name] : null;
            $arr['category_id'] = $category?->id;
            $arr['project'] = $project ? ['id' => $project->id, 'name' => $project->subject] : null;
            $arr['project_number'] = $project?->project_number;
            $arr['hris_project_end_date'] = AppDateTime::toIso($project?->end_date);
            $arr['is_unassigned'] = $task->assigned_to === null;
            $arr['is_overdue'] = $this->projectTaskRowIsOverdue($arr, $project?->end_date, $task->status);

            return $arr;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function buildTicketReportStats(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'by_status' => $this->countByKey($rows, 'status'),
            'by_priority' => $this->countByKey($rows, 'priority'),
            'overdue_count' => $rows->where('is_overdue', true)->count(),
            'unassigned_count' => $rows->where('is_unassigned', true)->count(),
            'done_count' => $rows->where('status', 'done')->count(),
            'by_category' => $this->countByCategory($rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function buildProjectTaskReportStats(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'by_status' => $this->countByKey($rows, 'status'),
            'by_priority' => $this->countByKey($rows, 'priority'),
            'overdue_count' => $rows->where('is_overdue', true)->count(),
            'unassigned_count' => $rows->where('is_unassigned', true)->count(),
            'done_count' => $rows->where('status', 'done')->count(),
            'by_category' => $this->countByCategory($rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, count: int}>
     */
    protected function countByCategory(Collection $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $cid = $row['category_id'] ?? null;
            if (! $cid) {
                continue;
            }
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
        }

        $names = HdCategory::query()->whereIn('id', array_keys($counts))->pluck('name', 'id');

        return collect($counts)
            ->map(fn ($count, $id) => [
                'id' => (int) $id,
                'name' => $names[$id] ?? '—',
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function countByKey(Collection $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $k = $row[$key] ?? 'unknown';
            $out[$k] = ($out[$k] ?? 0) + 1;
        }

        return $out;
    }

    protected function ticketRowIsOverdue(array $row): bool
    {
        if (($row['status'] ?? '') === 'done') {
            return false;
        }

        $due = $row['due_at'] ?? $row['due_date'] ?? null;

        return $due && strtotime($due) < time();
    }

    protected function projectTaskRowIsOverdue(array $row, $projectEndDate, string $status): bool
    {
        if ($status === 'done') {
            return false;
        }

        $taskEnd = $row['end_date'] ?? $row['start_date'] ?? null;
        if (! $taskEnd || ! $projectEndDate) {
            return false;
        }

        $tz = AppDateTime::displayTimezone();
        $end = Carbon::parse($taskEnd)->timezone($tz)->startOfDay();
        $deadline = Carbon::parse($projectEndDate)->timezone($tz)->endOfDay();

        return $end->gt($deadline);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortReportRows(Collection $rows, string $sort, string $type): Collection
    {
        $priorityRank = [
            'critical' => 0,
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
        ];

        return $rows->sort(function (array $a, array $b) use ($sort, $priorityRank, $type) {
            $dueA = $type === 'hris_project_task'
                ? ($a['end_date'] ?? $a['start_date'] ?? null)
                : ($a['due_at'] ?? $a['due_date'] ?? null);
            $dueB = $type === 'hris_project_task'
                ? ($b['end_date'] ?? $b['start_date'] ?? null)
                : ($b['due_at'] ?? $b['due_date'] ?? null);
            $tsA = $dueA ? strtotime($dueA) : PHP_INT_MAX;
            $tsB = $dueB ? strtotime($dueB) : PHP_INT_MAX;

            return match ($sort) {
                'start_date_desc', 'work_date_desc' => $tsB <=> $tsA ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
                'start_date_asc', 'work_date_asc' => $tsA <=> $tsB ?: (($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '')),
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
}
