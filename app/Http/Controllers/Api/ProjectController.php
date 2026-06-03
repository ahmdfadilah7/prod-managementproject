<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\LabelResource;
use App\Http\Resources\MilestoneResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use App\Models\HdCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\ActivityLogger;
use App\Services\HrisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            $paginator = $this->hris->listCategories($request->user(), [
                'search' => $request->string('search')->trim()->toString(),
                'trashed' => $request->string('trashed')->trim()->toString() ?: null,
            ]);

            $items = collect($paginator->items());
            $myTasksByCategory = $this->hris->myTasksByStatusForCategories(
                $request->user(),
                $items->pluck('id')
            );

            $data = $items->map(
                fn (HdCategory $c) => $this->hris->categoryToProjectArray(
                    $c,
                    $request->user(),
                    $myTasksByCategory[$c->id] ?? null
                )
            );

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

        $user = $request->user();
        $query = Project::query()
            ->accessibleBy($user)
            ->with(['owner', 'members'])
            ->withCount('tasks')
            ->withCount(['tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done')]);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->string('status')->trim()->toString()) {
            $query->where('status', $status);
        }

        if ($priority = $request->string('priority')->trim()->toString()) {
            $query->where('priority', $priority);
        }

        $sort = $request->get('sort', 'updated_at');
        $allowedSorts = ['name', 'status', 'priority', 'progress', 'created_at', 'updated_at', 'due_date'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'updated_at';
        }

        $direction = strtolower($request->get('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        $paginator = $query->paginate(12);
        $myTasksByProject = $this->myTasksByStatusForProjects($user, $paginator->getCollection()->pluck('id'));

        $paginator->getCollection()->transform(function (Project $project) use ($myTasksByProject) {
            $project->my_tasks_by_status = $myTasksByProject[$project->id] ?? [
                'backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0,
            ];
            $project->my_tasks_count = array_sum($project->my_tasks_by_status);

            return $project;
        });

        return ProjectResource::collection($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $hris = config('managementpro.hris_mode');

        $validated = $request->validate($hris ? [
            'name' => ['required_without:categories', 'string', 'max:255'],
            'categories' => ['sometimes', 'array', 'min:1', 'max:30'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.sub_categories' => ['sometimes', 'array', 'min:1'],
            'categories.*.sub_categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.sub_categories.*.sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'sub_categories' => ['sometimes', 'array', 'min:1'],
            'sub_categories.*.name' => ['required', 'string', 'max:255'],
            'sub_categories.*.sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'sub_category_name' => ['nullable', 'string', 'max:255'],
            'sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ] : [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:7'],
            'status' => ['nullable', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['nullable', 'in:low,medium,high,critical,urgent'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'sub_category_name' => ['nullable', 'string', 'max:255'],
            'sla_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($hris) {
            $categories = $this->hris->createCategories($request->user(), $validated);
            $data = collect($categories)
                ->map(fn (HdCategory $c) => $this->hris->categoryToProjectArray($c, $request->user()))
                ->values()
                ->all();

            return response()->json([
                'data' => count($data) === 1 ? $data[0] : $data,
                'created_count' => count($data),
            ], 201);
        }

        $project = Project::create([
            ...$validated,
            'owner_id' => $request->user()->id,
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'status' => $validated['status'] ?? 'planning',
            'color' => $validated['color'] ?? '#6366f1',
        ]);

        $project->syncOwnerAsMember();

        ActivityLogger::log(
            $project,
            $request->user(),
            'project.created',
            "membuat proyek \"{$project->name}\""
        );

        return response()->json([
            'data' => new ProjectResource($project->load(['owner', 'members'])),
        ], 201);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            $category = $this->hris->findCategory(
                $request->user(),
                $project,
                $request->boolean('trashed')
            );

            return response()->json([
                'data' => $this->hris->categoryToProjectArray($category, $request->user()),
                'labels' => $category->subCategories->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'sla_minutes' => $s->sla_minutes,
                    'color' => '#6366f1',
                ]),
                'milestones' => [],
            ]);
        }

        $project = Project::findOrFail($project);
        $this->authorizeProject($request->user(), $project);

        $project->load(['owner', 'members', 'labels', 'milestones'])
            ->loadCount('tasks')
            ->loadCount(['tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done')]);

        return response()->json([
            'data' => new ProjectResource($project),
            'labels' => LabelResource::collection($project->labels),
            'milestones' => MilestoneResource::collection($project->milestones),
        ]);
    }

    public function update(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            $category = $this->hris->findCategory($request->user(), $project);
            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'sub_categories' => ['sometimes', 'array'],
                'sub_categories.*.id' => ['nullable', 'integer'],
                'sub_categories.*.name' => ['required_with:sub_categories', 'string', 'max:255'],
                'sub_categories.*.sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
                'sub_categories.*._delete' => ['sometimes', 'boolean'],
            ]);

            $category = $this->hris->updateCategory($request->user(), $category, $validated);

            return response()->json([
                'data' => $this->hris->categoryToProjectArray($category, $request->user()),
            ]);
        }

        $project = Project::findOrFail($project);
        $this->authorizeProject($request->user(), $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:7'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['sometimes', 'in:low,medium,high,critical,urgent'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        $project->update($validated);

        ActivityLogger::log(
            $project,
            $request->user(),
            'project.updated',
            "memperbarui proyek \"{$project->name}\""
        );

        return response()->json([
            'data' => new ProjectResource($project->fresh(['owner', 'members'])),
        ]);
    }

    public function destroy(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            $category = $this->hris->findCategory($request->user(), $project);
            $this->hris->softDeleteCategory($request->user(), $category);

            return response()->json(['message' => 'Kategori dihapus (soft delete)']);
        }

        $project = Project::findOrFail($project);
        if ($project->owner_id !== $request->user()->id) {
            abort(403, 'Hanya pemilik yang dapat menghapus proyek.');
        }

        $project->delete();

        return response()->json(['message' => 'Proyek dihapus']);
    }

    public function restore(Request $request, int $project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $category = $this->hris->restoreCategory($request->user(), $project);

        return response()->json([
            'message' => 'Kategori dipulihkan',
            'data' => $this->hris->categoryToProjectArray($category, $request->user()),
        ]);
    }

    public function forceDestroy(Request $request, int $project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $this->hris->forceDeleteCategory($request->user(), $project);

        return response()->json(['message' => 'Kategori dihapus permanen']);
    }

    public function storeSubCategory(Request $request, int $project): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $category = $this->hris->findCategory($request->user(), $project);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $sub = $this->hris->createSubCategory($request->user(), $category, $validated);

        return response()->json([
            'data' => $this->hris->subCategoryToArray($sub),
        ], 201);
    }

    public function updateSubCategory(Request $request, int $project, int $subCategory): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $category = $this->hris->findCategory($request->user(), $project);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $sub = $this->hris->updateSubCategory($request->user(), $category, $subCategory, $validated);

        return response()->json([
            'data' => $this->hris->subCategoryToArray($sub),
        ]);
    }

    public function destroySubCategory(Request $request, int $project, int $subCategory): JsonResponse
    {
        if (! config('managementpro.hris_mode')) {
            abort(404);
        }

        $category = $this->hris->findCategory($request->user(), $project);
        $this->hris->deleteSubCategory($request->user(), $category, $subCategory);

        return response()->json(['message' => 'Sub kategori dihapus']);
    }

    public function activities(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            return response()->json(['data' => []]);
        }

        $project = Project::findOrFail($project);
        $this->authorizeProject($request->user(), $project);

        $activities = $project->activities()->with('user')->paginate(20);

        return response()->json([
            'data' => ActivityResource::collection($activities),
        ]);
    }

    public function members(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            $category = $this->hris->findCategory($request->user(), $project);

            return response()->json([
                'data' => $this->hris->categoryMembers($category),
            ]);
        }

        $project = Project::findOrFail($project);
        $this->authorizeProject($request->user(), $project);

        return response()->json([
            'data' => UserResource::collection($project->members),
        ]);
    }

    public function addMember(Request $request, int $project): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            abort(501, 'Penambahan anggota via email belum didukung di mode HRIS. Assign user pada ticket.');
        }

        $project = Project::findOrFail($project);
        $this->authorizeProject($request->user(), $project);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['nullable', 'in:admin,member,viewer'],
        ]);

        $member = User::where('email', $validated['email'])->firstOrFail();

        if ($project->hasMember($member)) {
            return response()->json(['message' => 'User sudah menjadi anggota'], 422);
        }

        $project->members()->attach($member->id, [
            'role' => $validated['role'] ?? 'member',
        ]);

        ActivityLogger::log(
            $project,
            $request->user(),
            'member.added',
            "menambahkan {$member->name} ke proyek",
            User::class,
            $member->id
        );

        return response()->json([
            'data' => UserResource::collection($project->fresh()->members),
        ]);
    }

    private function authorizeProject(User $user, Project $project): void
    {
        if (! $project->hasMember($user)) {
            abort(403, 'Anda tidak memiliki akses ke proyek ini.');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $projectIds
     * @return array<int, array<string, int>>
     */
    private function myTasksByStatusForProjects(User $user, $projectIds): array
    {
        $ids = collect($projectIds)->filter()->values();
        $empty = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0];
        $result = [];

        foreach ($ids as $id) {
            $result[$id] = $empty;
        }

        if ($ids->isEmpty()) {
            return $result;
        }

        $rows = Task::query()
            ->whereIn('project_id', $ids)
            ->where('assignee_id', $user->id)
            ->select('project_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('project_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $status = in_array($row->status, array_keys($empty), true) ? $row->status : 'todo';
            $result[$row->project_id][$status] = ($result[$row->project_id][$status] ?? 0) + (int) $row->aggregate;
        }

        return $result;
    }
}
