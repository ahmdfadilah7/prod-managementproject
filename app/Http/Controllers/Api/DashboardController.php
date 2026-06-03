<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Activity;
use App\Models\HdCategory;
use App\Models\HdProject;
use App\Models\HdProjectTask;
use App\Models\HdTicket;
use App\Models\Project;
use App\Models\User;
use App\Support\AppDateTime;
use App\Models\Task;
use App\Services\HrisWorkspaceService;
use App\Support\Hris\TicketStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
  public function __construct(
    protected HrisWorkspaceService $hris
  ) {}

  public function index(Request $request): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      return $this->hrisDashboard($request);
    }

    $user = $request->user();

    $projectIds = Project::query()
      ->accessibleBy($user)
      ->pluck('id');

    $tasksQuery = Task::whereIn('project_id', $projectIds);

    $stats = [
      'total_projects' => $projectIds->count(),
      'active_projects' => Project::whereIn('id', $projectIds)->where('status', 'active')->count(),
      'total_tasks' => (clone $tasksQuery)->count(),
      'completed_tasks' => (clone $tasksQuery)->where('status', 'done')->count(),
      'in_progress_tasks' => (clone $tasksQuery)->where('status', 'in_progress')->count(),
      'overdue_tasks' => (clone $tasksQuery)
        ->whereNot('status', 'done')
        ->whereDate('due_date', '<', now())
        ->count(),
      'my_tasks' => Task::where('assignee_id', $user->id)
        ->whereNot('status', 'done')
        ->count(),
    ];

    $tasksByStatus = Task::whereIn('project_id', $projectIds)
      ->select('status', DB::raw('count(*) as count'))
      ->groupBy('status')
      ->pluck('count', 'status');

    $recentProjects = Project::with(['owner', 'members'])
      ->withCount('tasks')
      ->withCount(['tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done')])
      ->whereIn('id', $projectIds)
      ->latest()
      ->take(5)
      ->get();

    $myTasks = Task::with(['project:id,name', 'assignee', 'labels'])
      ->where('assignee_id', $user->id)
      ->whereNot('status', 'done')
      ->orderBy('due_date')
      ->take(8)
      ->get();

    $recentActivities = Activity::with('user')
      ->whereIn('project_id', $projectIds)
      ->latest()
      ->take(10)
      ->get();

    return response()->json([
      'stats' => $stats,
      'tasks_by_status' => $tasksByStatus,
      'recent_projects' => ProjectResource::collection($recentProjects),
      'my_tasks' => TaskResource::collection($myTasks),
      'my_project_tasks' => [],
      'my_projects' => [],
      'recent_activities' => ActivityResource::collection($recentActivities),
    ]);
  }

  protected function hrisDashboard(Request $request): JsonResponse
  {
    $user = $request->user();
    $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');
    $ticketsQuery = HdTicket::query()
      ->inActiveCategory()
      ->whereIn('hd_categories_id', $categoryIds);

    $stats = [
      'total_projects' => $categoryIds->count(),
      'active_projects' => HdCategory::whereIn('id', $categoryIds)
        ->whereHas('tickets', fn ($q) => $q->whereIn('status', ['open', 'pending', 'processing']))
        ->count(),
      'total_tasks' => (clone $ticketsQuery)->count(),
      'completed_tasks' => (clone $ticketsQuery)->where('status', 'closed')->count(),
      'in_progress_tasks' => (clone $ticketsQuery)->where('status', 'processing')->count(),
      'overdue_tasks' => (clone $ticketsQuery)
        ->whereNotIn('status', ['closed', 'resolved'])
        ->where('sla_deadline', '<', now())
        ->count(),
      'my_ticket_count' => 0,
      'my_project_task_count' => 0,
      'my_tasks' => 0,
    ];

    $myTicketCount = HdTicket::query()
      ->inActiveCategory()
      ->where('assigned_to', $user->id)
      ->whereNotIn('status', ['closed'])
      ->count();

    $myProjectTaskCount = $this->myHdProjectTasksQuery($user, $categoryIds)->count();

    $stats['my_ticket_count'] = $myTicketCount;
    $stats['my_project_task_count'] = $myProjectTaskCount;
    $stats['my_tasks'] = $myTicketCount + $myProjectTaskCount;

    $tasksByStatus = [
      'backlog' => 0,
      'todo' => 0,
      'in_progress' => 0,
      'review' => 0,
      'done' => 0,
    ];

    $ticketCounts = HdTicket::query()
      ->inActiveCategory()
      ->whereIn('hd_categories_id', $categoryIds)
      ->select('status', DB::raw('count(*) as count'))
      ->groupBy('status')
      ->pluck('count', 'status');

    foreach ($ticketCounts as $ticketStatus => $count) {
      $board = TicketStatusMapper::toBoard($ticketStatus);
      $tasksByStatus[$board] = ($tasksByStatus[$board] ?? 0) + $count;
    }

    $recentCategories = HdCategory::query()
      ->accessibleBy($user)
      ->with(['creator.employee', 'subCategories'])
      ->withCount('tickets')
      ->withCount(['tickets as completed_tickets_count' => fn ($q) => $q->where('status', 'closed')])
      ->latest()
      ->take(5)
      ->get();

    $myTickets = HdTicket::query()
      ->inActiveCategory()
      ->with(['category', 'subCategory', 'assignee.employee', 'reporter.employee'])
      ->where('assigned_to', $user->id)
      ->whereNotIn('status', ['closed'])
      ->orderByRaw('sla_deadline IS NULL, sla_deadline ASC')
      ->orderByDesc('updated_at')
      ->take(6)
      ->get();

    $myProjectTasks = $this->myHdProjectTasksQuery($user, $categoryIds)
      ->orderByRaw('start_date IS NULL, start_date ASC')
      ->orderByDesc('updated_at')
      ->take(6)
      ->get();

    $myProjects = HdProject::query()
      ->inActiveSubCategory()
      ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
      ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
      ->with(['subCategory.category', 'reporter.employee', 'assignees.employee'])
      ->withCount([
        'tasks',
        'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done'),
      ])
      ->whereNotIn('status', ['completed', 'archived'])
      ->orderByDesc('updated_at')
      ->take(6)
      ->get();

    return response()->json([
      'stats' => $stats,
      'tasks_by_status' => $tasksByStatus,
      'recent_projects' => $recentCategories->map(
        fn ($c) => $this->hris->categoryToProjectArray($c, $user)
      )->values(),
      'my_tasks' => $myTickets->map(function (HdTicket $t) {
        $arr = $this->hris->ticketToTaskArray($t);
        $arr['task_type'] = 'ticket';
        $arr['project'] = $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null;
        $arr['project_id'] = $t->hd_categories_id;

        return $arr;
      })->values(),
      'my_project_tasks' => $myProjectTasks->map(
        fn (HdProjectTask $task) => $this->mapDashboardProjectTask($task, $user)
      )->values(),
      'my_projects' => $myProjects->map(
        fn (HdProject $project) => $this->mapDashboardProject($project, $user)
      )->values(),
      'recent_activities' => [],
    ]);
  }

  /**
   * @param  \Illuminate\Support\Collection<int, int>  $categoryIds
   */
  protected function myHdProjectTasksQuery(User $user, $categoryIds)
  {
    return HdProjectTask::query()
      ->where('assigned_to', $user->id)
      ->where('status', '!=', 'done')
      ->whereHas('hdProject', function ($q) use ($categoryIds) {
        $q->inActiveSubCategory()
          ->whereHas('subCategory', fn ($sq) => $sq->whereIn('hd_categories_id', $categoryIds));
      })
      ->with(['hdProject.subCategory.category', 'assignee.employee', 'reporter.employee']);
  }

  protected function mapDashboardProjectTask(HdProjectTask $task, User $user): array
  {
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
  }

  protected function mapDashboardProject(HdProject $project, User $user): array
  {
    $arr = $this->hris->hdProjectToArray($project, 0, $user);
    $arr['is_overdue'] = $project->end_date
      && Carbon::parse($project->end_date)->timezone(AppDateTime::displayTimezone())->isPast()
      && ! in_array($project->status, ['done', 'completed', 'archived'], true);

    return $arr;
  }
}
