<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdCategory;
use App\Models\HdProject;
use App\Models\HdProjectTask;
use App\Models\Project;
use App\Models\Task;
use App\Services\HrisWorkspaceService;
use App\Support\AppDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $displayTz = AppDateTime::displayTimezone();
        $from = Carbon::create($year, 1, 1, 0, 0, 0, $displayTz)->startOfDay()->utc();
        $to = Carbon::create($year, 12, 31, 23, 59, 59, $displayTz)->endOfDay()->utc();

        $user = $request->user();

        if (config('managementpro.hris_mode')) {
            return $this->indexHris($user, $year, $from, $to);
        }

        return $this->indexStandard($user, $year, $from, $to);
    }

    protected function indexHris($user, int $year, Carbon $from, Carbon $to): JsonResponse
    {
        $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

        $today = Carbon::now(AppDateTime::displayTimezone())->toDateString();

        $hdProjects = HdProject::query()
            ->inActiveSubCategory()
            ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
            ->where(fn ($q) => $q->whereNotNull('start_date')->orWhereNotNull('end_date'))
            ->with(['subCategory.category:id,name'])
            ->orderBy('start_date')
            ->get();

        [$hdProjectRanges, $hdProjectDayMarkers] = $this->buildHdProjectCalendarEvents(
            $hdProjects,
            $from,
            $to,
            $today
        );

        $projectTaskEvents = $this->buildHrisProjectTaskCalendarEvents(
            $categoryIds,
            $from,
            $to,
            $today
        );

        $byDateEvents = $projectTaskEvents->concat($hdProjectDayMarkers);

        return $this->jsonCalendar($year, collect(), $byDateEvents, $hdProjectRanges, $projectTaskEvents);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $categoryIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function buildHrisProjectTaskCalendarEvents($categoryIds, Carbon $from, Carbon $to, string $today)
    {
        $tasks = HdProjectTask::query()
            ->overlappingPeriod($from, $to)
            ->whereHas('hdProject', function ($q) use ($categoryIds) {
                $q->inActiveSubCategory()
                    ->whereHas('subCategory', fn ($sq) => $sq->whereIn('hd_categories_id', $categoryIds));
            })
            ->with(['hdProject.subCategory.category', 'assignee.employee'])
            ->orderBy('start_date')
            ->get();

        return $tasks->map(function (HdProjectTask $task) use ($today) {
            $project = $task->hdProject;
            $category = $project?->subCategory?->category;
            $categoryId = (int) ($category?->id ?? 0);
            $date = AppDateTime::toDateString($task->start_date);
            $status = $task->status;
            $isRunning = ! in_array($status, ['done'], true);
            $endKey = $task->end_date
                ? AppDateTime::toDateString($task->end_date)
                : $date;
            $timing = $endKey < $today ? 'past' : ($date <= $today && $today <= $endKey ? 'today' : ($date > $today ? 'upcoming' : 'past'));
            $projectEnd = $project?->end_date;
            $taskEndForDeadline = $task->end_date ?? $task->start_date;
            $pastProjectDeadline = $projectEnd && $taskEndForDeadline
                && Carbon::parse($taskEndForDeadline)->timezone(AppDateTime::displayTimezone())->startOfDay()
                    ->gt(Carbon::parse($projectEnd)->timezone(AppDateTime::displayTimezone())->endOfDay());

            return [
                'type' => 'hris_project_task',
                'id' => $task->id,
                'hris_project_id' => $task->hd_projects_id,
                'project_id' => $categoryId,
                'title' => $task->subject,
                'task_number' => $task->task_number,
                'description' => $task->description,
                'date' => $date,
                'start_date' => AppDateTime::toIso($task->start_date),
                'end_date' => AppDateTime::toIso($task->end_date),
                'range_start' => AppDateTime::toIso($task->start_date),
                'range_end' => AppDateTime::toIso($task->end_date ?? $task->start_date),
                'hris_project_end_date' => AppDateTime::toIso($projectEnd),
                'status' => $status,
                'priority' => $task->priority,
                'category' => $category?->name,
                'project_title' => $project?->subject,
                'project_number' => $project?->project_number,
                'assignee_name' => $task->assignee?->name,
                'created_at' => AppDateTime::toIso($task->created_at),
                'updated_at' => AppDateTime::toIso($task->updated_at),
                'timing' => $timing,
                'is_running' => $isRunning,
                'is_overdue' => $isRunning && ($pastProjectDeadline || $timing === 'past'),
            ];
        })->values();
    }

    protected function indexStandard($user, int $year, Carbon $from, Carbon $to): JsonResponse
    {
        $projectIds = Project::query()->accessibleBy($user)->pluck('id');

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with(['project:id,name'])
            ->orderBy('due_date')
            ->get();

        $today = Carbon::now(AppDateTime::displayTimezone())->toDateString();

        $events = $tasks->map(function (Task $t) use ($today) {
            $date = AppDateTime::toDateString($t->due_date);

            return $this->eventPayload(
                $t->id,
                $t->project_id,
                $t->title,
                null,
                $date,
                AppDateTime::toIso(AppDateTime::parseDueInput($date)),
                $t->status,
                $t->priority,
                $t->project?->name,
                $today
            );
        });

        return $this->jsonCalendar($year, $events, $events);
    }

    protected function eventPayload(
        int $id,
        int $projectId,
        string $title,
        ?string $ticketNumber,
        string $date,
        ?string $dueAt,
        string $status,
        string $priority,
        ?string $category,
        string $today,
        ?string $description = null
    ): array {
        $timing = $date < $today ? 'past' : ($date === $today ? 'today' : 'upcoming');
        $isRunning = in_array($status, ['todo', 'in_progress', 'review'], true);

        return [
            'type' => 'ticket',
            'id' => $id,
            'project_id' => $projectId,
            'title' => $title,
            'ticket_number' => $ticketNumber,
            'date' => $date,
            'due_at' => $dueAt,
            'status' => $status,
            'priority' => $priority,
            'category' => $category,
            'description' => $description,
            'timing' => $timing,
            'is_running' => $isRunning,
            'is_overdue' => $timing === 'past' && $isRunning,
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    protected function buildHdProjectCalendarEvents(
        $hdProjects,
        Carbon $from,
        Carbon $to,
        string $today
    ): array {
        $ranges = collect();
        $dayMarkers = collect();

        foreach ($hdProjects as $project) {
            $bounds = $this->resolveHdProjectDateBounds($project);
            if (! $bounds) {
                continue;
            }

            [$start, $end] = $bounds;

            if ($end->lt($from) || $start->gt($to)) {
                continue;
            }

            $categoryId = (int) $project->subCategory?->hd_categories_id;
            $categoryName = $project->subCategory?->category?->name;
            $status = $this->hris->normalizeHdProjectLifecycleStatus($project->status);
            $meta = $this->projectRangeMeta($start, $end, $today, $status);

            $ranges->push([
                'type' => 'hd_project',
                'id' => $project->id,
                'hd_project_id' => $project->id,
                'project_id' => $categoryId,
                'title' => $project->subject,
                'project_number' => $project->project_number,
                'date' => $start->toDateString(),
                'range_start' => AppDateTime::toIso($start),
                'range_end' => AppDateTime::toIso($end),
                'start_date' => AppDateTime::toIso($start),
                'end_date' => AppDateTime::toIso($end),
                'status' => $status,
                'priority' => $project->priority,
                'category' => $categoryName,
                'created_at' => AppDateTime::toIso($project->created_at),
                'updated_at' => AppDateTime::toIso($project->updated_at),
                'timing' => $meta['timing'],
                'is_running' => $meta['is_running'],
                'is_overdue' => $meta['is_overdue'],
            ]);

            $cursor = $start->copy()->timezone(AppDateTime::displayTimezone())->startOfDay();
            $endDay = $end->copy()->timezone(AppDateTime::displayTimezone())->startOfDay();
            $clipStart = $cursor->lt($from) ? $from->copy()->startOfDay() : $cursor;
            $clipEnd = $endDay->gt($to) ? $to->copy()->startOfDay() : $endDay;

            if ($clipStart->gt($clipEnd)) {
                continue;
            }

            $current = $clipStart->copy();
            $guard = 0;

            while ($current->lte($clipEnd) && $guard < 400) {
                $date = $current->toDateString();
                $dayTiming = $date < $today ? 'past' : ($date === $today ? 'today' : 'upcoming');

                $dayMarkers->push([
                    'type' => 'hd_project_day',
                    'hd_project_id' => $project->id,
                    'project_id' => $categoryId,
                    'title' => $project->subject,
                    'project_number' => $project->project_number,
                    'date' => $date,
                    'range_start' => AppDateTime::toIso($start),
                    'range_end' => AppDateTime::toIso($end),
                    'status' => $status,
                    'priority' => $project->priority,
                    'category' => $categoryName,
                    'timing' => $dayTiming,
                    'is_running' => $meta['is_running'],
                    'is_overdue' => $meta['is_overdue'],
                ]);

                $current->addDay();
                $guard++;
            }
        }

        return [$ranges->values(), $dayMarkers];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function resolveHdProjectDateBounds(HdProject $project): ?array
    {
        if (! $project->start_date && ! $project->end_date) {
            return null;
        }

        $start = Carbon::parse($project->start_date ?? $project->end_date)->timezone(AppDateTime::displayTimezone())->startOfDay();
        $end = Carbon::parse($project->end_date ?? $project->start_date)->timezone(AppDateTime::displayTimezone())->endOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * @return array{timing: string, is_running: bool, is_overdue: bool}
     */
    protected function projectRangeMeta(Carbon $start, Carbon $end, string $today, string $status): array
    {
        $isRunning = ! in_array($status, ['done', 'completed', 'archived'], true);
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        if ($endStr < $today) {
            $timing = 'past';
        } elseif ($startStr > $today) {
            $timing = 'upcoming';
        } else {
            $timing = 'today';
        }

        return [
            'timing' => $timing,
            'is_running' => $isRunning,
            'is_overdue' => $isRunning && $endStr < $today,
        ];
    }

    protected function jsonCalendar(
        int $year,
        $ticketEvents,
        $byDateEvents = null,
        $hdProjectRanges = null,
        $projectTaskEvents = null
    ): JsonResponse {
        $tickets = $ticketEvents->values();
        $tasks = ($projectTaskEvents ?? collect())->values();
        $ranges = $hdProjectRanges ? $hdProjectRanges->values() : collect();
        $byDateSource = ($byDateEvents ?? $ticketEvents)->values();
        $panelItems = $tickets->concat($tasks)->concat($ranges);

        return response()->json([
            'year' => $year,
            'today' => Carbon::now(AppDateTime::displayTimezone())->toDateString(),
            'data' => $panelItems,
            'hd_project_ranges' => $ranges,
            'by_date' => $byDateSource->groupBy('date')->map->values(),
            'summary' => [
                'total' => $panelItems->count(),
                'tickets' => $tickets->count(),
                'hris_project_tasks' => $tasks->count(),
                'hd_projects' => $ranges->count(),
                'past' => $panelItems->where('timing', 'past')->count(),
                'today' => $panelItems->where('timing', 'today')->count(),
                'upcoming' => $panelItems->where('timing', 'upcoming')->count(),
                'running' => $panelItems->where('is_running', true)->count(),
                'overdue' => $panelItems->where('is_overdue', true)->count(),
            ],
        ]);
    }
}
