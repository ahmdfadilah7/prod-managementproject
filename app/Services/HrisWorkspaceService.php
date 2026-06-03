<?php

namespace App\Services;

use App\Models\HdCategory;
use App\Models\HdProject;
use App\Models\HdProjectTask;
use App\Models\HdProjectTaskAttachment;
use App\Models\HdSubCategory;
use App\Models\HdTicket;
use App\Models\HdTicketMessage;
use App\Models\User;
use App\Support\AppDateTime;
use App\Support\Hris\TicketStatusMapper;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HrisWorkspaceService
{
  public function listCategories(User $user, array $filters = []): LengthAwarePaginator
  {
    $query = HdCategory::query()
      ->accessibleBy($user)
      ->with(['creator.employee', 'subCategories'])
      ->withCount('tickets')
      ->withCount(['tickets as completed_tickets_count' => fn ($q) => $q->where('status', 'closed')]);

    $trashed = $filters['trashed'] ?? null;
    if ($trashed === 'only') {
      $query->onlyTrashed();
    } elseif ($trashed === 'with') {
      $query->withTrashed();
    }

    if (! empty($filters['search'])) {
      $query->where('name', 'like', '%'.$filters['search'].'%');
    }

    return $query->latest('updated_at')->paginate(12);
  }

  /**
   * @return list<HdCategory>
   */
  public function createCategories(User $user, array $data): array
  {
    if (! empty($data['categories']) && is_array($data['categories'])) {
      return collect($data['categories'])
        ->map(fn (array $item) => $this->createOneCategory($user, $item))
        ->all();
    }

    return [$this->createOneCategory($user, $data)];
  }

  public function createCategory(User $user, array $data): HdCategory
  {
    return $this->createOneCategory($user, $data);
  }

  protected function createOneCategory(User $user, array $data): HdCategory
  {
    $companyId = $user->companyId() ?? config('managementpro.default_company_id');

    $category = HdCategory::create([
      'companies_id' => $companyId,
      'name' => $data['name'],
      'users_created' => $user->id,
    ]);

    $this->syncSubCategories($category, $user, $data);

    return $category->load(['creator.employee', 'subCategories']);
  }

  protected function syncSubCategories(HdCategory $category, User $user, array $data): void
  {
    $rows = [];

    if (! empty($data['sub_categories']) && is_array($data['sub_categories'])) {
      $rows = $data['sub_categories'];
    } elseif (! empty($data['sub_category_name'])) {
      $rows = [[
        'name' => $data['sub_category_name'],
        'sla_minutes' => $data['sla_minutes'] ?? 120,
      ]];
    }

    if ($rows === []) {
      $rows = [['name' => 'Umum', 'sla_minutes' => 120]];
    }

    foreach ($rows as $row) {
      $name = trim((string) ($row['name'] ?? ''));
      if ($name === '') {
        continue;
      }

      HdSubCategory::create([
        'hd_categories_id' => $category->id,
        'name' => $name,
        'sla_minutes' => (int) ($row['sla_minutes'] ?? 120),
        'users_created' => $user->id,
      ]);
    }
  }

  public function findCategory(User $user, int $id, bool $withTrashed = false): HdCategory
  {
    $query = HdCategory::with([
      'creator.employee',
      'subCategories' => fn ($q) => $q->orderBy('name'),
    ])
      ->withCount('tickets')
      ->withCount(['tickets as completed_tickets_count' => fn ($q) => $q->where('status', 'closed')]);

    if ($withTrashed) {
      $query->withTrashed();
    }

    $category = $query->findOrFail($id);

    $this->authorizeCategory($user, $category);

    return $category;
  }

  public function updateCategory(User $user, HdCategory $category, array $data): HdCategory
  {
    $this->authorizeCategoryManage($user, $category, 'update');

    if (isset($data['name'])) {
      $category->update([
        'name' => $data['name'],
        'users_updated' => $user->id,
      ]);
    }

    if (array_key_exists('sub_categories', $data) && is_array($data['sub_categories'])) {
      $this->syncSubCategoriesOnUpdate($category, $user, $data['sub_categories']);
    }

    return $category->fresh([
      'creator.employee',
      'subCategories' => fn ($q) => $q->orderBy('name'),
    ]);
  }

  public function softDeleteCategory(User $user, HdCategory $category): void
  {
    $this->authorizeCategoryManage($user, $category, 'delete');

    $category->update(['users_deleted' => $user->id]);
    $category->delete();
  }

  public function restoreCategory(User $user, int $id): HdCategory
  {
    $category = $this->findCategory($user, $id, true);

    if (! $category->trashed()) {
      abort(422, 'Kategori tidak dalam status terhapus.');
    }

    $this->authorizeCategoryManage($user, $category, 'update');
    $category->restore();
    $category->update(['users_deleted' => null, 'users_updated' => $user->id]);

    return $category->fresh([
      'creator.employee',
      'subCategories' => fn ($q) => $q->orderBy('name'),
    ]);
  }

  public function forceDeleteCategory(User $user, int $id): void
  {
    $category = $this->findCategory($user, $id, true);

    if (! $category->trashed()) {
      abort(422, 'Kategori harus dihapus sementara sebelum dihapus permanen.');
    }

    $this->authorizeCategoryManage($user, $category, 'delete');

    $category->load('subCategories');
    foreach ($category->subCategories as $sub) {
      $this->assertSubCategoryDeletable($sub);
    }
    foreach ($category->subCategories as $sub) {
      $sub->delete();
    }
    $category->forceDelete();
  }

  public function createSubCategory(User $user, HdCategory $category, array $data): HdSubCategory
  {
    $this->authorizeCategoryManage($user, $category, 'update');

    return HdSubCategory::create([
      'hd_categories_id' => $category->id,
      'name' => $data['name'],
      'sla_minutes' => (int) ($data['sla_minutes'] ?? 120),
      'users_created' => $user->id,
    ]);
  }

  public function updateSubCategory(User $user, HdCategory $category, int $subId, array $data): HdSubCategory
  {
    $this->authorizeCategoryManage($user, $category, 'update');

    $sub = $this->findSubCategory($category, $subId);

    $sub->update([
      'name' => $data['name'] ?? $sub->name,
      'sla_minutes' => (int) ($data['sla_minutes'] ?? $sub->sla_minutes),
      'users_updated' => $user->id,
    ]);

    return $sub->fresh();
  }

  public function deleteSubCategory(User $user, HdCategory $category, int $subId): void
  {
    $this->authorizeCategoryManage($user, $category, 'delete');

    $sub = $this->findSubCategory($category, $subId);
    $this->assertSubCategoryDeletable($sub);
    $sub->delete();
  }

  protected function syncSubCategoriesOnUpdate(HdCategory $category, User $user, array $rows): void
  {
    foreach ($rows as $row) {
      $name = trim((string) ($row['name'] ?? ''));
      if ($name === '') {
        continue;
      }

      if (! empty($row['id'])) {
        $sub = HdSubCategory::where('hd_categories_id', $category->id)
          ->find($row['id']);

        if (! $sub) {
          continue;
        }

        if (! empty($row['_delete'])) {
          $this->assertSubCategoryDeletable($sub);
          $sub->delete();
          continue;
        }

        $sub->update([
          'name' => $name,
          'sla_minutes' => (int) ($row['sla_minutes'] ?? $sub->sla_minutes),
          'users_updated' => $user->id,
        ]);
      } else {
        HdSubCategory::create([
          'hd_categories_id' => $category->id,
          'name' => $name,
          'sla_minutes' => (int) ($row['sla_minutes'] ?? 120),
          'users_created' => $user->id,
        ]);
      }
    }
  }

  protected function findSubCategory(HdCategory $category, int $subId): HdSubCategory
  {
    return HdSubCategory::where('hd_categories_id', $category->id)->findOrFail($subId);
  }

  protected function assertSubCategoryDeletable(HdSubCategory $sub): void
  {
    $projects = $sub->projects()->count();
    $tickets = $sub->tickets()->count();

    if ($projects === 0 && $tickets === 0) {
      return;
    }

    $parts = [];
    if ($projects > 0) {
      $parts[] = "{$projects} project HRIS";
    }
    if ($tickets > 0) {
      $parts[] = "{$tickets} tiket helpdesk";
    }

    abort(422, 'Sub kategori "'.$sub->name.'" masih digunakan ('.implode(' dan ', $parts).'). Pindahkan atau hapus data terkait terlebih dahulu.');
  }

  public function authorizeCategoryManage(User $user, HdCategory $category, string $action = 'update'): void
  {
    $this->authorizeCategory($user, $category);

    $permission = $action === 'delete' ? 'projects.delete' : 'projects.update';

    if ($user->hasPermission($permission) || $category->users_created === $user->id) {
      return;
    }

    abort(403, 'Tidak memiliki izin mengelola kategori ini.');
  }

  public function emptyTasksByStatus(): array
  {
    return [
      'backlog' => 0,
      'todo' => 0,
      'in_progress' => 0,
      'review' => 0,
      'done' => 0,
    ];
  }

  /**
   * @param  Collection<int, int>|array<int, int>  $categoryIds
   * @return array<int, array<string, int>>
   */
  public function myTasksByStatusForCategories(User $user, Collection|array $categoryIds): array
  {
    $ids = collect($categoryIds)->filter()->values();
    $result = [];

    foreach ($ids as $id) {
      $result[$id] = $this->emptyTasksByStatus();
    }

    if ($ids->isEmpty()) {
      return $result;
    }

    $rows = HdTicket::query()
      ->whereIn('hd_categories_id', $ids)
      ->where('assigned_to', $user->id)
      ->select('hd_categories_id', 'status', DB::raw('count(*) as aggregate'))
      ->groupBy('hd_categories_id', 'status')
      ->get();

    foreach ($rows as $row) {
      $board = TicketStatusMapper::toBoard($row->status);
      $result[$row->hd_categories_id][$board] = ($result[$row->hd_categories_id][$board] ?? 0) + (int) $row->aggregate;
    }

    return $result;
  }

  public function categoryToProjectArray(HdCategory $category, ?User $viewer = null, ?array $myTasksByStatus = null): array
  {
    $total = (int) ($category->tickets_count ?? $category->tickets()->count());
    $done = (int) ($category->completed_tickets_count ?? $category->tickets()->where('status', 'closed')->count());
    $progress = $total > 0 ? (int) round(($done / $total) * 100) : 0;

    $openCount = $category->tickets()->whereIn('status', ['open', 'pending', 'processing'])->count();
    $status = $total === 0 ? 'planning' : ($done === $total ? 'completed' : ($openCount > 0 ? 'active' : 'on_hold'));

    $colors = ['#6366f1', '#ec4899', '#14b8a6', '#f59e0b', '#8b5cf6'];
    $color = $colors[$category->id % count($colors)];

    return [
      'id' => $category->id,
      'name' => $category->name,
      'slug' => Str::slug($category->name).'-'.$category->id,
      'description' => $category->subCategories->pluck('name')->join(', ') ?: 'Kategori helpdesk HRIS',
      'color' => $color,
      'status' => $status,
      'priority' => 'medium',
      'progress' => $progress,
      'start_date' => $category->created_at?->format('Y-m-d'),
      'due_date' => null,
      'owner_id' => $category->users_created,
      'owner' => $category->creator ? $this->userToArray($category->creator) : null,
      'members' => $this->categoryMembers($category),
      'tasks_count' => $total,
      'completed_tasks_count' => $done,
      'is_mine' => $viewer && $category->users_created === $viewer->id,
      'my_role' => ($viewer && $category->users_created === $viewer->id) ? 'owner' : 'member',
      'sub_categories' => $category->subCategories->map(fn ($s) => $this->subCategoryToArray($s))->values()->all(),
      'deleted_at' => $category->deleted_at?->toIso8601String(),
      'is_trashed' => $category->trashed(),
      'my_tasks_by_status' => ($statusCounts = $myTasksByStatus ?? ($viewer
        ? ($this->myTasksByStatusForCategories($viewer, [$category->id])[$category->id] ?? $this->emptyTasksByStatus())
        : $this->emptyTasksByStatus())),
      'my_tasks_count' => array_sum($statusCounts),
      'created_at' => $category->created_at?->toIso8601String(),
      'updated_at' => $category->updated_at?->toIso8601String(),
    ];
  }

  public function subCategoryToArray(HdSubCategory $sub): array
  {
    return [
      'id' => $sub->id,
      'hd_categories_id' => $sub->hd_categories_id,
      'name' => $sub->name,
      'sla_minutes' => $sub->sla_minutes,
    ];
  }

  public function listTickets(HdCategory $category, User $user, array $filters = []): Collection
  {
    $this->authorizeCategory($user, $category);

    $onlyTrashed = ($filters['trashed'] ?? null) === 'only';

    $query = HdTicket::query()
      ->forCategory($category->id)
      ->with(['assignee.employee', 'reporter.employee', 'subCategory'])
      ->withCount('messages')
      ->orderByDesc('updated_at');

    if ($onlyTrashed) {
      $query->onlyTrashed();
    }

    if (! empty($filters['status']) && ! $onlyTrashed) {
      $ticketStatus = TicketStatusMapper::toTicket($filters['status']);
      $query->where('status', $ticketStatus);
    }

    return $query->get();
  }

  public function createTicket(HdCategory $category, User $user, array $data): HdTicket
  {
    $this->authorizeCategory($user, $category);

    $subCategoryId = $data['hd_sub_categories_id']
      ?? $data['sub_category_id']
      ?? $category->subCategories()->value('id');

    if (! $subCategoryId) {
      $sub = HdSubCategory::create([
        'hd_categories_id' => $category->id,
        'name' => 'Umum',
        'sla_minutes' => 120,
        'users_created' => $user->id,
      ]);
      $subCategoryId = $sub->id;
    }

    $subCategory = HdSubCategory::where('hd_categories_id', $category->id)->findOrFail($subCategoryId);
    $boardStatus = $data['status'] ?? 'todo';
    $ticketStatus = TicketStatusMapper::toTicket($boardStatus);
    $priority = TicketStatusMapper::boardToTicketPriority($data['priority'] ?? 'medium');

    $createPayload = [
      'companies_id' => $category->companies_id,
      'ticket_number' => $this->generateTicketNumber(),
      'subject' => $data['title'] ?? $data['subject'],
      'description' => $data['description'] ?? '',
      'reporter_id' => $user->id,
      'hd_categories_id' => $category->id,
      'hd_sub_categories_id' => $subCategory->id,
      'priority' => $priority,
      'status' => $ticketStatus,
      'assigned_to' => $data['assignee_id'] ?? null,
      'sla_deadline' => null,
      'users_created' => $user->id,
    ];

    if ($ticketStatus === 'processing') {
      $createPayload['sla_deadline'] = $this->computeSlaDeadlineFromSubCategory($subCategory->id);
    }

    if ($ticketStatus === 'resolved') {
      $createPayload['resolved_at'] = AppDateTime::now();
    }

    $ticket = HdTicket::create($createPayload);

    return $ticket->load(['assignee.employee', 'reporter.employee', 'subCategory']);
  }

  public function findTicket(HdCategory $category, int $ticketId, User $user, bool $withTrashed = false): HdTicket
  {
    $this->authorizeCategory($user, $category);

    $query = HdTicket::forCategory($category->id)
      ->with(['assignee.employee', 'reporter.employee', 'subCategory', 'messages.user.employee']);

    if ($withTrashed) {
      $query->withTrashed();
    }

    return $query->findOrFail($ticketId);
  }

  public function deleteTicket(HdCategory $category, HdTicket $ticket, User $user): void
  {
    $this->authorizeCategory($user, $category);
    $this->assertTicketDeletable($ticket);

    $ticket->update(['users_deleted' => $user->id]);
    $ticket->delete();
  }

  public function restoreTicket(HdCategory $category, HdTicket $ticket, User $user): HdTicket
  {
    $this->authorizeCategory($user, $category);

    if (! $ticket->trashed()) {
      abort(422, 'Tiket tidak dalam status terhapus.');
    }

    $ticket->restore();
    $ticket->update([
      'users_deleted' => null,
      'users_updated' => $user->id,
    ]);

    return $ticket->fresh(['assignee.employee', 'reporter.employee', 'subCategory']);
  }

  public function forceDeleteTicket(HdCategory $category, HdTicket $ticket, User $user): void
  {
    $this->authorizeCategory($user, $category);

    if (! $ticket->trashed()) {
      abort(422, 'Tiket harus dihapus sementara terlebih dahulu sebelum dihapus permanen.');
    }

    $ticket->forceDelete();
  }

  public function assertTicketDeletable(HdTicket $ticket): void
  {
    if (in_array($ticket->status, ['resolved', 'closed'], true)) {
      abort(422, 'Tiket dengan status Review atau Done tidak dapat dihapus.');
    }
  }

  public function updateTicket(HdTicket $ticket, array $data, ?User $actor = null): HdTicket
  {
    if ($ticket->trashed()) {
      abort(422, 'Tiket terhapus tidak dapat diubah. Pulihkan terlebih dahulu.');
    }

    $payload = [];

    if (isset($data['title'])) {
      $payload['subject'] = $data['title'];
    }
    if (array_key_exists('description', $data)) {
      $payload['description'] = $data['description'];
    }
    $subCategoryOverride = null;

    if (isset($data['status'])) {
      $newTicketStatus = TicketStatusMapper::toTicket($data['status']);
      $payload['status'] = $newTicketStatus;
      $payload = array_merge($payload, $this->resolvedAtPayloadIfEnteredResolved($ticket, $newTicketStatus));
      if ($actor) {
        $payload = array_merge($payload, $this->autoAssignPayloadIfMovedFromPending($ticket, $newTicketStatus, $actor));
      }
    }
    if (isset($data['priority'])) {
      $payload['priority'] = TicketStatusMapper::boardToTicketPriority($data['priority']);
    }
    if (array_key_exists('assignee_id', $data)) {
      $payload['assigned_to'] = $data['assignee_id'];
    }
    if (isset($data['hd_sub_categories_id'])) {
      $payload['hd_sub_categories_id'] = $data['hd_sub_categories_id'];
      $subCategoryOverride = (int) $data['hd_sub_categories_id'];
    }

    if (isset($data['status'])) {
      $newTicketStatus = TicketStatusMapper::toTicket($data['status']);
      $payload = array_merge(
        $payload,
        $this->slaDeadlinePayloadIfLeftProcessing($ticket, $newTicketStatus),
        $this->slaDeadlinePayloadIfEnteredProcessing($ticket, $newTicketStatus, $subCategoryOverride)
      );
    }
    if (array_key_exists('due_date', $data) || array_key_exists('due_at', $data)) {
      $dueInput = $data['due_at'] ?? $data['due_date'] ?? null;
      $payload['sla_deadline'] = AppDateTime::parseDueInput($dueInput);
    }

    $ticket->update($payload);

    return $ticket->fresh(['assignee.employee', 'reporter.employee', 'subCategory', 'messages.user.employee']);
  }

  public function reorderTickets(HdCategory $category, User $user, array $tasks): Collection
  {
    $this->authorizeCategory($user, $category);

    foreach ($tasks as $item) {
      $ticket = HdTicket::forCategory($category->id)->find($item['id'] ?? null);
      if (! $ticket || $ticket->trashed()) {
        continue;
      }

      $newTicketStatus = TicketStatusMapper::toTicket($item['status'] ?? 'todo');
      $payload = [
        'status' => $newTicketStatus,
        ...$this->autoAssignPayloadIfMovedFromPending($ticket, $newTicketStatus, $user),
        ...$this->slaDeadlinePayloadIfLeftProcessing($ticket, $newTicketStatus),
        ...$this->slaDeadlinePayloadIfEnteredProcessing($ticket, $newTicketStatus),
        ...$this->resolvedAtPayloadIfEnteredResolved($ticket, $newTicketStatus),
      ];

      $ticket->update($payload);
    }

    return $this->listTickets($category, $user);
  }

  /**
   * Isi assigned_to dengan user yang menggeser tiket jika masih kosong
   * dan status berubah dari pending (backlog) ke status lain.
   *
   * @return array<string, mixed>
   */
  protected function autoAssignPayloadIfMovedFromPending(HdTicket $ticket, string $newTicketStatus, User $actor): array
  {
    if ($ticket->assigned_to) {
      return [];
    }

    if ($ticket->status !== 'pending') {
      return [];
    }

    if ($newTicketStatus === 'pending') {
      return [];
    }

    return ['assigned_to' => $actor->id];
  }

  /**
   * Kosongkan sla_deadline saat tiket keluar dari processing ke open atau pending.
   *
   * @return array<string, mixed>
   */
  protected function slaDeadlinePayloadIfLeftProcessing(HdTicket $ticket, string $newTicketStatus): array
  {
    if ($ticket->status !== 'processing') {
      return [];
    }

    if (! in_array($newTicketStatus, ['open', 'pending'], true)) {
      return [];
    }

    return ['sla_deadline' => null];
  }

  /**
   * Set sla_deadline saat tiket masuk status processing (in_progress di board),
   * hanya jika belum pernah diisi.
   *
   * @return array<string, mixed>
   */
  /**
   * Isi resolved_at saat tiket masuk status resolved (kolom Review di board).
   *
   * @return array<string, mixed>
   */
  protected function resolvedAtPayloadIfEnteredResolved(HdTicket $ticket, string $newTicketStatus): array
  {
    if ($newTicketStatus !== 'resolved') {
      return [];
    }

    if ($ticket->status === 'resolved') {
      return [];
    }

    return ['resolved_at' => AppDateTime::now()];
  }

  protected function slaDeadlinePayloadIfEnteredProcessing(
    HdTicket $ticket,
    string $newTicketStatus,
    ?int $subCategoryId = null
  ): array {
    if ($ticket->sla_deadline !== null) {
      return [];
    }

    if ($newTicketStatus !== 'processing') {
      return [];
    }

    if ($ticket->status === 'processing') {
      return [];
    }

    return [
      'sla_deadline' => $this->computeSlaDeadlineFromSubCategory(
        $subCategoryId ?? $ticket->hd_sub_categories_id
      ),
    ];
  }

  protected function computeSlaDeadlineFromSubCategory(?int $subCategoryId): \Illuminate\Support\Carbon
  {
    $minutes = 120;

    if ($subCategoryId) {
      $sub = HdSubCategory::find($subCategoryId);
      if ($sub) {
        $minutes = (int) $sub->sla_minutes;
      }
    }

    return now()->addMinutes($minutes);
  }

  public function addMessage(HdTicket $ticket, User $user, ?string $body, ?\Illuminate\Http\UploadedFile $attachment = null): HdTicketMessage
  {
    $attachmentPath = null;
    if ($attachment) {
      $attachmentPath = app(TicketMessageAttachmentService::class)->store($ticket, $attachment);
    }

    return HdTicketMessage::create([
      'hd_tickets_id' => $ticket->id,
      'users_id' => $user->id,
      'message' => $body ?? '',
      'attachment' => $attachmentPath,
    ]);
  }

  public function ticketToTaskArray(HdTicket $ticket, int $position = 0): array
  {
    return [
      'id' => $ticket->id,
      'project_id' => $ticket->hd_categories_id,
      'title' => $ticket->subject,
      'description' => $ticket->description ?? '',
      'status' => TicketStatusMapper::toBoard($ticket->status),
      'priority' => TicketStatusMapper::priorityToBoard($ticket->priority),
      'position' => $position,
      'assignee_id' => $ticket->assigned_to,
      'assignee' => $ticket->assignee ? $this->userToArray($ticket->assignee) : null,
      'reporter_id' => $ticket->reporter_id,
      'created_by' => $ticket->reporter_id,
      'creator' => $ticket->reporter ? $this->userToArray($ticket->reporter) : null,
      'reporter' => $ticket->reporter ? $this->userToArray($ticket->reporter) : null,
      'due_date' => AppDateTime::toDateString($ticket->sla_deadline),
      'due_at' => AppDateTime::toIso($ticket->sla_deadline),
      'completed_at' => AppDateTime::toIso($ticket->resolved_at),
      'ticket_number' => $ticket->ticket_number,
      'hd_sub_categories_id' => $ticket->hd_sub_categories_id,
      'sub_category' => $ticket->subCategory ? [
        'id' => $ticket->subCategory->id,
        'name' => $ticket->subCategory->name,
      ] : null,
      'comments_count' => $ticket->messages_count ?? $ticket->messages()->count(),
      'comments' => $ticket->relationLoaded('messages')
        ? $ticket->messages->map(fn ($m) => $this->messageToCommentArray($m))->values()->all()
        : [],
      'labels' => [],
      'deleted_at' => AppDateTime::toIso($ticket->deleted_at),
      'is_trashed' => $ticket->trashed(),
      'can_delete' => $ticket->trashed() ? false : $this->ticketIsDeletable($ticket),
      'can_restore' => $ticket->trashed(),
      'can_force_delete' => $ticket->trashed(),
      'created_at' => AppDateTime::toIso($ticket->created_at),
      'updated_at' => AppDateTime::toIso($ticket->updated_at),
    ];
  }

  public function ticketIsDeletable(HdTicket $ticket): bool
  {
    if ($ticket->trashed()) {
      return false;
    }

    return ! in_array($ticket->status, ['resolved', 'closed'], true);
  }

  public function messageToCommentArray(HdTicketMessage $message): array
  {
    return [
      'id' => $message->id,
      'task_id' => $message->hd_tickets_id,
      'ticket_id' => $message->hd_tickets_id,
      'body' => $message->message,
      'attachment' => app(TicketMessageAttachmentService::class)->metadata($message),
      'user_id' => $message->users_id,
      'user' => $message->user ? $this->userToArray($message->user) : null,
      'is_mine' => false,
      'created_at' => AppDateTime::toIso($message->created_at),
    ];
  }

  public function messageToArrayForUser(HdTicketMessage $message, User $viewer): array
  {
    $arr = $this->messageToCommentArray($message);
    $arr['is_mine'] = (int) $message->users_id === (int) $viewer->id;

    return $arr;
  }

  /**
   * @return array{data: \Illuminate\Support\Collection, meta: array<string, mixed>}
   */
  public function listMessageConversations(User $user, array $filters = []): array
  {
    $query = HdTicket::query()
      ->inActiveCategory()
      ->whereHas('category', fn ($q) => $q->accessibleBy($user))
      ->with([
        'category:id,name',
        'assignee.employee',
        'reporter.employee',
        'latestMessage.user.employee',
      ])
      ->withCount('messages')
      ->withMax('messages as last_message_at', 'created_at');

    if (! $user->hasPermission('tasks.view')) {
      $query->where(function ($q) use ($user) {
        $q->where('reporter_id', $user->id)
          ->orWhere('assigned_to', $user->id)
          ->orWhereHas('messages', fn ($m) => $m->where('users_id', $user->id));
      });
    }

    $scope = $filters['scope'] ?? 'all';
    match ($scope) {
      'assigned' => $query->where('assigned_to', $user->id),
      'reported' => $query->where('reporter_id', $user->id),
      'participated' => $query->whereHas('messages', fn ($m) => $m->where('users_id', $user->id)),
      default => null,
    };

    if (! empty($filters['project_id'])) {
      $query->where('hd_categories_id', (int) $filters['project_id']);
    }

    if (! empty($filters['search'])) {
      $term = '%'.$filters['search'].'%';
      $query->where(function ($q) use ($term) {
        $q->where('subject', 'like', $term)
          ->orWhere('ticket_number', 'like', $term)
          ->orWhereHas('messages', fn ($m) => $m->where('message', 'like', $term));
      });
    }

    if (($filters['has_messages'] ?? null) === '1') {
      $query->whereHas('messages');
    }

    $query->orderByDesc('last_message_at')->orderByDesc('updated_at');

    $perPage = min(max((int) ($filters['per_page'] ?? 30), 5), 100);
    $paginator = $query->paginate($perPage);

    return [
      'data' => collect($paginator->items())->map(fn (HdTicket $t) => $this->conversationToArray($t, $user)),
      'meta' => [
        'current_page' => $paginator->currentPage(),
        'last_page' => $paginator->lastPage(),
        'per_page' => $paginator->perPage(),
        'total' => $paginator->total(),
      ],
    ];
  }

  public function conversationToArray(HdTicket $ticket, User $viewer): array
  {
    $last = $ticket->latestMessage;

    return [
      'ticket_id' => $ticket->id,
      'project_id' => $ticket->hd_categories_id,
      'ticket_number' => $ticket->ticket_number,
      'title' => $ticket->subject,
      'status' => TicketStatusMapper::toBoard($ticket->status),
      'priority' => TicketStatusMapper::priorityToBoard($ticket->priority),
      'category' => $ticket->category ? [
        'id' => $ticket->category->id,
        'name' => $ticket->category->name,
      ] : null,
      'assignee' => $ticket->assignee ? $this->userToArray($ticket->assignee) : null,
      'reporter' => $ticket->reporter ? $this->userToArray($ticket->reporter) : null,
      'messages_count' => (int) ($ticket->messages_count ?? 0),
      'last_message_at' => AppDateTime::toIso(
        $last?->created_at ?? ($ticket->last_message_at ? \Illuminate\Support\Carbon::parse($ticket->last_message_at) : null)
      ),
      'last_message' => $last ? $this->messageToArrayForUser($last, $viewer) : null,
    ];
  }

  public function findTicketForMessages(User $user, int $ticketId): HdTicket
  {
    $ticket = HdTicket::query()
      ->inActiveCategory()
      ->with(['category', 'assignee.employee', 'reporter.employee', 'subCategory'])
      ->findOrFail($ticketId);

    $this->authorizeTicketForMessages($user, $ticket);

    return $ticket;
  }

  public function authorizeTicketForMessages(User $user, HdTicket $ticket): void
  {
    $category = $ticket->category;
    if (! $category) {
      abort(404, 'Kategori tiket tidak ditemukan.');
    }

    $this->authorizeCategory($user, $category);

    if ($user->hasPermission('tasks.view')) {
      return;
    }

    $involved = (int) $ticket->reporter_id === (int) $user->id
      || (int) $ticket->assigned_to === (int) $user->id
      || $ticket->messages()->where('users_id', $user->id)->exists();

    if (! $involved) {
      abort(403, 'Anda tidak terlibat pada tiket ini.');
    }
  }

  /**
   * @return array{ticket: array<string, mixed>, data: \Illuminate\Support\Collection, meta: array<string, mixed>}
   */
  public function listTicketMessages(HdTicket $ticket, User $user, array $filters = []): array
  {
    $perPage = min(max((int) ($filters['per_page'] ?? 50), 10), 200);
    $paginator = $ticket->messages()
      ->with('user.employee')
      ->orderBy('created_at')
      ->paginate($perPage);

    return [
      'ticket' => $this->conversationToArray($ticket, $user),
      'data' => collect($paginator->items())->map(fn (HdTicketMessage $m) => $this->messageToArrayForUser($m, $user)),
      'meta' => [
        'current_page' => $paginator->currentPage(),
        'last_page' => $paginator->lastPage(),
        'per_page' => $paginator->perPage(),
        'total' => $paginator->total(),
      ],
    ];
  }

  public function categoryMembers(HdCategory $category): array
  {
    $userIds = HdTicket::forCategory($category->id)
      ->whereNotNull('assigned_to')
      ->pluck('assigned_to')
      ->unique()
      ->filter();

    if ($userIds->isEmpty()) {
      return [];
    }

    return User::with('employee')->whereIn('id', $userIds)->get()
      ->map(fn ($u) => $this->userToArray($u))
      ->values()
      ->all();
  }

  public function authorizeCategory(User $user, HdCategory $category): void
  {
    $companyId = $user->companyId();

    if ($companyId && (int) $category->companies_id !== (int) $companyId) {
      abort(403, 'Kategori tidak berada di perusahaan Anda.');
    }

    if ($user->hasPermission('projects.view')) {
      return;
    }

    $hasAccess = $category->users_created === $user->id
      || HdTicket::forCategory($category->id)
        ->where(function ($q) use ($user) {
          $q->where('reporter_id', $user->id)->orWhere('assigned_to', $user->id);
        })
        ->exists();

    if (! $hasAccess) {
      abort(403, 'Anda tidak memiliki akses ke kategori ini.');
    }
  }

  protected function userToArray(User $user): array
  {
    return [
      'id' => $user->id,
      'name' => $user->name,
      'email' => $user->email,
      'avatar' => $user->avatar,
      'job_title' => $user->job_title,
      'initials' => collect(explode(' ', $user->name))
        ->map(fn ($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->join(''),
    ];
  }

  protected function generateTicketNumber(): string
  {
    $prefix = 'TIC-'.now()->format('Ymd');
    $last = HdTicket::where('ticket_number', 'like', $prefix.'%')->count() + 1;

    return sprintf('%s-%03d', $prefix, $last);
  }

  /**
   * Project HRIS yang ditugaskan ke user (pivot hd_project_user), belum selesai.
   *
   * @return array<int, array<string, mixed>>
   */
  public function listMyHdProjects(User $user): array
  {
    $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

    $projects = HdProject::query()
      ->inActiveSubCategory()
      ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
      ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
      ->whereNotIn('status', ['completed', 'archived'])
      ->with(['subCategory.category', 'reporter.employee', 'assignees.employee'])
      ->orderByRaw('end_date IS NULL, end_date ASC')
      ->orderByRaw('start_date IS NULL, start_date ASC')
      ->orderByDesc('updated_at')
      ->get();

    return $projects->map(function (HdProject $project) {
      $arr = $this->hdProjectToArray($project);
      $arr['category'] = $project->subCategory?->category
        ? [
          'id' => $project->subCategory->category->id,
          'name' => $project->subCategory->category->name,
        ]
        : null;

      return $arr;
    })->values()->all();
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function listHdProjects(HdCategory $category, User $user): array
  {
    $this->authorizeCategory($user, $category);

    $projects = HdProject::query()
      ->forCompany($category->companies_id)
      ->inActiveSubCategory()
      ->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $category->id))
      ->with(['subCategory', 'reporter.employee', 'assignees.employee'])
      ->orderBy('position')
      ->orderBy('id')
      ->get();

    $positionByStatus = [];

    return $projects->map(function (HdProject $project) use (&$positionByStatus) {
      $status = $this->normalizeHdProjectStatus($project->status);
      $positionByStatus[$status] = ($positionByStatus[$status] ?? 0) + 1;

      return $this->hdProjectToArray($project, $positionByStatus[$status]);
    })->values()->all();
  }

  public function createHdProject(HdCategory $category, User $user, array $data): HdProject
  {
    $this->authorizeCategory($user, $category);

    $subCategory = HdSubCategory::where('hd_categories_id', $category->id)
      ->findOrFail($data['hd_sub_categories_id']);

    $lifecycleStatus = $this->normalizeHdProjectStatus($data['status'] ?? 'planning');

    $project = HdProject::create([
      'companies_id' => $category->companies_id,
      'hd_sub_categories_id' => $subCategory->id,
      'project_number' => $this->generateProjectNumber($category->companies_id),
      'subject' => $data['subject'],
      'description' => $data['description'] ?? '',
      'reporter_id' => $user->id,
      'priority' => $data['priority'] ?? 'medium',
      'status' => $lifecycleStatus,
      'start_date' => AppDateTime::parseDueInput($data['start_date'] ?? null),
      'end_date' => AppDateTime::parseDueInput($data['end_date'] ?? null),
      'position' => 0,
      'users_created' => $user->id,
    ]);

    $assigneeIds = array_values(array_unique(array_map('intval', $data['assignee_ids'] ?? [])));
    if (! in_array($user->id, $assigneeIds, true)) {
      $assigneeIds[] = $user->id;
    }
    $project->assignees()->sync($assigneeIds);

    return $project->load(['subCategory', 'reporter.employee', 'assignees.employee']);
  }

  public function findHdProject(HdCategory $category, int $projectId, User $user): HdProject
  {
    $this->authorizeCategory($user, $category);

    return HdProject::query()
      ->forCompany($category->companies_id)
      ->inActiveSubCategory()
      ->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $category->id))
      ->with(['subCategory', 'reporter.employee', 'assignees.employee'])
      ->findOrFail($projectId);
  }

  public function deleteHdProject(HdProject $project, User $user): void
  {
    $this->assertCanManageHdProject($project, $user);
    $this->assertHdProjectDeletable($project);
    $project->delete();
  }

  public function hdProjectIsDeletable(HdProject $project): bool
  {
    if ($project->trashed()) {
      return false;
    }

    return $this->normalizeHdProjectStatus($project->status) === 'archived';
  }

  public function assertHdProjectDeletable(HdProject $project): void
  {
    if (! $this->hdProjectIsDeletable($project)) {
      abort(422, 'Hanya project berstatus Arsip yang dapat dihapus.');
    }
  }

  public function restoreHdProject(HdProject $project, User $user): HdProject
  {
    $this->assertCanManageHdProject($project, $user);

    if (! $project->trashed()) {
      abort(422, 'Project tidak dalam status terhapus.');
    }

    $project->restore();
    $project->update(['users_updated' => $user->id]);

    return $project->fresh(['subCategory.category', 'reporter.employee', 'assignees.employee']);
  }

  public function forceDeleteHdProject(HdProject $project, User $user): void
  {
    $this->assertCanManageHdProject($project, $user);

    if (! $project->trashed()) {
      abort(422, 'Project harus dihapus sementara terlebih dahulu sebelum dihapus permanen.');
    }

    $project->forceDelete();
  }

  public function updateHdProject(HdProject $project, array $data, User $user): HdProject
  {
    if ($project->trashed()) {
      abort(422, 'Project terhapus tidak dapat diubah. Pulihkan terlebih dahulu.');
    }

    $payload = ['users_updated' => $user->id];

    if (isset($data['subject'])) {
      $payload['subject'] = $data['subject'];
    }
    if (array_key_exists('description', $data)) {
      $payload['description'] = $data['description'];
    }
    if (isset($data['status'])) {
      $payload['status'] = $this->normalizeHdProjectStatus($data['status']);
    }
    if (isset($data['priority'])) {
      $payload['priority'] = $data['priority'];
    }
    if (isset($data['hd_sub_categories_id'])) {
      $sub = HdSubCategory::with('category')->findOrFail($data['hd_sub_categories_id']);
      $this->authorizeCategory($user, $sub->category);
      $payload['hd_sub_categories_id'] = $sub->id;
    }
    if (array_key_exists('start_date', $data)) {
      $payload['start_date'] = AppDateTime::parseDueInput($data['start_date']);
    }
    if (array_key_exists('end_date', $data)) {
      $payload['end_date'] = AppDateTime::parseDueInput($data['end_date']);
    }

    $project->update($payload);

    if (array_key_exists('start_date', $data) || array_key_exists('end_date', $data)) {
      $project->refresh();
      $this->assertAllHdProjectTasksWithinProjectDates($project);
    }

    if (array_key_exists('assignee_ids', $data)) {
      $assigneeIds = array_values(array_unique(array_map('intval', $data['assignee_ids'] ?? [])));
      if ($assigneeIds) {
        $project->assignees()->sync($assigneeIds);
      }
    }

    return $project->fresh(['subCategory.category', 'reporter.employee', 'assignees.employee']);
  }

  /**
   * @param  array<int, array{id: int, status: string, position: int}>  $items
   * @return array<int, array<string, mixed>>
   */
  public function reorderHdProjects(HdCategory $category, User $user, array $items): array
  {
    $this->authorizeCategory($user, $category);

    foreach ($items as $item) {
      $project = HdProject::query()
        ->forCompany($category->companies_id)
        ->whereHas('subCategory', fn ($q) => $q->where('hd_categories_id', $category->id))
        ->find($item['id'] ?? null);

      if (! $project) {
        continue;
      }

      $project->update([
        'status' => $this->normalizeHdProjectStatus($item['status'] ?? 'active'),
        'position' => (int) ($item['position'] ?? 0),
        'users_updated' => $user->id,
      ]);
    }

    return $this->listHdProjects($category, $user);
  }

  /**
   * @return array{total: int, done: int, progress: int}
   */
  public function hdProjectTaskProgressStats(HdProject $project): array
  {
    if (isset($project->tasks_count)) {
      $total = (int) $project->tasks_count;
      $done = (int) ($project->completed_tasks_count ?? 0);
    } else {
      $total = $project->tasks()->count();
      $done = $project->tasks()->where('status', 'done')->count();
    }

    $progress = $total > 0 ? (int) round(($done / $total) * 100) : 0;

    return [
      'total' => $total,
      'done' => $done,
      'progress' => $progress,
    ];
  }

  public function hdProjectToArray(HdProject $project, int $position = 0, ?User $viewer = null): array
  {
    $categoryId = $project->subCategory?->hd_categories_id;
    $canEditTasks = $viewer ? $this->userCanEditHdProjectTasks($project, $viewer) : false;
    $canManage = $viewer ? $this->userIsHdProjectCreator($project, $viewer) : false;
    $taskStats = $this->hdProjectTaskProgressStats($project);

    return [
      'id' => $project->id,
      'category_id' => $categoryId,
      'project_id' => $categoryId,
      'title' => $project->subject,
      'subject' => $project->subject,
      'description' => $project->description,
      'project_number' => $project->project_number,
      'status' => $this->normalizeHdProjectStatus($project->status),
      'priority' => $project->priority,
      'progress' => $taskStats['progress'],
      'tasks_count' => $taskStats['total'],
      'completed_tasks_count' => $taskStats['done'],
      'position' => $position ?: (int) $project->position,
      'hd_sub_categories_id' => $project->hd_sub_categories_id,
      'sub_category' => $project->subCategory ? [
        'id' => $project->subCategory->id,
        'name' => $project->subCategory->name,
      ] : null,
      'category' => $project->subCategory?->category ? [
        'id' => $project->subCategory->category->id,
        'name' => $project->subCategory->category->name,
      ] : null,
      'reporter_id' => $project->reporter_id,
      'reporter' => $project->reporter ? $this->userToArray($project->reporter) : null,
      'assignees' => $project->relationLoaded('assignees')
        ? $project->assignees->map(fn ($u) => $this->userToArray($u))->values()->all()
        : [],
      'assignee_ids' => $project->relationLoaded('assignees')
        ? $project->assignees->pluck('id')->all()
        : [],
      'start_date' => AppDateTime::toIso($project->start_date),
      'end_date' => AppDateTime::toIso($project->end_date),
      'created_at' => AppDateTime::toIso($project->created_at),
      'updated_at' => AppDateTime::toIso($project->updated_at),
      'can_edit_tasks' => $canEditTasks && ! $project->trashed(),
      'can_manage' => $canManage && ! $project->trashed(),
      'can_delete' => $canManage && $this->hdProjectIsDeletable($project),
      'deleted_at' => AppDateTime::toIso($project->deleted_at),
      'is_trashed' => $project->trashed(),
      'can_restore' => $project->trashed() && $canManage,
      'can_force_delete' => $project->trashed() && $canManage,
    ];
  }

  public function userIsHdProjectCreator(HdProject $project, User $user): bool
  {
    return (int) $project->reporter_id === (int) $user->id;
  }

  public function assertCanManageHdProject(HdProject $project, User $user): void
  {
    if (! $this->userIsHdProjectCreator($project, $user)) {
      abort(403, 'Hanya pembuat project yang dapat mengubah atau menghapus project ini.');
    }
  }

  public function userCanEditHdProjectTasks(HdProject $project, User $user): bool
  {
    if (! $project->relationLoaded('assignees')) {
      $project->load('assignees');
    }

    return $project->assignees->contains('id', $user->id);
  }

  public function assertCanEditHdProjectTasks(HdProject $project, User $user): void
  {
    if (! $this->userCanEditHdProjectTasks($project, $user)) {
      abort(403, 'Hanya anggota project yang dapat mengubah task.');
    }
  }

  public function findAccessibleHdProject(User $user, int $projectId, bool $withTrashed = false): HdProject
  {
    $categoryIds = HdCategory::query()->accessibleBy($user)->pluck('id');

    $query = HdProject::query()
      ->inActiveSubCategory()
      ->whereHas('subCategory', fn ($q) => $q->whereIn('hd_categories_id', $categoryIds))
      ->with(['subCategory.category', 'reporter.employee', 'assignees.employee'])
      ->withCount([
        'tasks',
        'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'done'),
      ]);

    if ($withTrashed) {
      $query->withTrashed();
    }

    return $query->findOrFail($projectId);
  }

  public function createHdProjectForUser(User $user, array $data): HdProject
  {
    $subCategory = HdSubCategory::with('category')->findOrFail($data['hd_sub_categories_id']);
    $this->authorizeCategory($user, $subCategory->category);

    return $this->createHdProject($subCategory->category, $user, $data);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function listHdProjectTasks(HdProject $project, User $user, array $filters = []): array
  {
    $this->findAccessibleHdProject($user, $project->id);

    $onlyTrashed = ($filters['trashed'] ?? null) === 'only';

    $query = HdProjectTask::query()
      ->forProject($project->id)
      ->with([
        'assignee.employee',
        'reporter.employee',
        'attachments' => fn ($q) => $q->with('uploader.employee')->orderBy('created_at'),
      ]);

    if ($onlyTrashed) {
      $query->onlyTrashed()->orderByDesc('deleted_at');
    } else {
      $query->orderBy('position')->orderBy('id');
    }

    $tasks = $query->get();

    $positionByStatus = [];

    return $tasks->map(function (HdProjectTask $task) use (&$positionByStatus, $user) {
      $positionByStatus[$task->status] = ($positionByStatus[$task->status] ?? 0) + 1;

      return $this->hdProjectTaskToArray($task, $positionByStatus[$task->status], $user);
    })->values()->all();
  }

  /**
   * @return array{0: ?Carbon, 1: ?Carbon}
   */
  protected function hdProjectDateBounds(HdProject $project): array
  {
    $tz = AppDateTime::displayTimezone();
    $start = $project->start_date
      ? Carbon::parse($project->start_date)->timezone($tz)->startOfDay()
      : null;
    $end = $project->end_date
      ? Carbon::parse($project->end_date)->timezone($tz)->endOfDay()
      : null;

    return [$start, $end];
  }

  protected function hdProjectTaskInstantInDisplay(?Carbon $date): ?Carbon
  {
    if (! $date) {
      return null;
    }

    return $date->copy()->utc()->timezone(AppDateTime::displayTimezone());
  }

  protected function assertAllHdProjectTasksWithinProjectDates(HdProject $project): void
  {
    $tasks = HdProjectTask::forProject($project->id)->get();

    foreach ($tasks as $task) {
      if (! $task->start_date && ! $task->end_date) {
        continue;
      }

      $this->assertHdProjectTaskDatesWithinProject($project, $task->start_date, $task->end_date);
    }
  }

  protected function assertHdProjectTaskDatesWithinProject(
    HdProject $project,
    ?Carbon $start,
    ?Carbon $end
  ): void {
    [$projectStart, $projectEnd] = $this->hdProjectDateBounds($project);

    if (! $projectStart && ! $projectEnd) {
      return;
    }

    foreach ([
      ['mulai', $start],
      ['selesai', $end],
    ] as [$label, $date]) {
      if (! $date) {
        continue;
      }

      $instant = $this->hdProjectTaskInstantInDisplay($date);
      if (! $instant) {
        continue;
      }

      if ($projectStart && $instant->lt($projectStart)) {
        abort(422, "Tanggal {$label} task tidak boleh sebelum tanggal mulai project.");
      }

      if ($projectEnd && $instant->gt($projectEnd)) {
        abort(422, "Tanggal {$label} task tidak boleh setelah tanggal selesai project.");
      }
    }
  }

  /**
   * @return array{start_date: ?Carbon, end_date: ?Carbon}
   */
  protected function resolveHdProjectTaskDatePayload(
    HdProject $project,
    array $data,
    bool $partial = false,
    ?HdProjectTask $existing = null
  ): array {
    $hasStart = array_key_exists('start_date', $data)
      || (! $partial && (array_key_exists('work_date', $data) || array_key_exists('due_at', $data) || array_key_exists('due_date', $data)));
    $hasEnd = array_key_exists('end_date', $data)
      || (! $partial && (array_key_exists('work_date', $data) || array_key_exists('due_at', $data) || array_key_exists('due_date', $data)));

    if (! $hasStart && ! $hasEnd) {
      return [];
    }

    $start = $hasStart
      ? AppDateTime::parseDueInput(
        $data['start_date'] ?? $data['work_date'] ?? $data['due_at'] ?? $data['due_date'] ?? null
      )
      : null;
    $end = $hasEnd
      ? AppDateTime::parseDueInput(
        $data['end_date'] ?? $data['due_at'] ?? $data['work_date'] ?? $data['due_date'] ?? null
      )
      : null;

    $out = [];
    if ($hasStart) {
      $out['start_date'] = $start;
    }
    if ($hasEnd) {
      $out['end_date'] = $end;
    }

    $effectiveStart = array_key_exists('start_date', $out)
      ? $out['start_date']
      : ($partial && $existing ? $existing->start_date : null);
    $effectiveEnd = array_key_exists('end_date', $out)
      ? $out['end_date']
      : ($partial && $existing ? $existing->end_date : null);

    if ($effectiveStart && $effectiveEnd && $effectiveEnd->lt($effectiveStart)) {
      abort(422, 'Tanggal selesai task tidak boleh lebih awal dari tanggal mulai.');
    }

    if ($effectiveStart || $effectiveEnd) {
      $this->assertHdProjectTaskDatesWithinProject($project, $effectiveStart, $effectiveEnd);
    }

    return $out;
  }

  public function createHdProjectTask(HdProject $project, User $user, array $data): HdProjectTask
  {
    $this->assertCanEditHdProjectTasks($project, $user);

    $status = $data['status'] ?? 'todo';
    $maxPosition = HdProjectTask::forProject($project->id)->where('status', $status)->max('position');

    $task = HdProjectTask::create([
      'hd_projects_id' => $project->id,
      'companies_id' => $project->companies_id,
      'task_number' => $this->generateHdProjectTaskNumber($project->companies_id),
      'subject' => $data['title'] ?? $data['subject'],
      'description' => $data['description'] ?? '',
      'reporter_id' => $user->id,
      'assigned_to' => $data['assignee_id'] ?? null,
      'priority' => $data['priority'] ?? 'medium',
      'status' => $status,
      'position' => ($maxPosition ?? 0) + 1,
      ...$this->resolveHdProjectTaskDatePayload($project, $data),
      'users_created' => $user->id,
    ]);

    return $task->load(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']);
  }

  public function findHdProjectTask(HdProject $project, int $taskId, User $user, bool $withTrashed = false): HdProjectTask
  {
    $this->findAccessibleHdProject($user, $project->id);

    $query = HdProjectTask::forProject($project->id)
      ->with([
        'assignee.employee',
        'reporter.employee',
        'attachments' => fn ($q) => $q->with('uploader.employee')->orderBy('created_at'),
      ]);

    if ($withTrashed) {
      $query->withTrashed();
    }

    return $query->findOrFail($taskId);
  }

  public function deleteHdProjectTask(HdProject $project, HdProjectTask $task, User $user): void
  {
    $this->assertCanEditHdProjectTasks($project, $user);
    $this->assertHdProjectTaskDeletable($task);
    $task->update(['users_updated' => $user->id]);
    $task->delete();
  }

  public function restoreHdProjectTask(HdProject $project, HdProjectTask $task, User $user): HdProjectTask
  {
    $this->assertCanEditHdProjectTasks($project, $user);

    if (! $task->trashed()) {
      abort(422, 'Task tidak dalam status terhapus.');
    }

    $task->restore();
    $task->update(['users_updated' => $user->id]);

    return $task->fresh(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']);
  }

  public function forceDeleteHdProjectTask(HdProject $project, HdProjectTask $task, User $user): void
  {
    $this->assertCanEditHdProjectTasks($project, $user);

    if (! $task->trashed()) {
      abort(422, 'Task harus dihapus sementara terlebih dahulu sebelum dihapus permanen.');
    }

    $task->forceDelete();
  }

  public function hdProjectTaskIsDeletable(HdProjectTask $task): bool
  {
    if ($task->trashed()) {
      return false;
    }

    return ! in_array($task->status, ['review', 'done'], true);
  }

  public function assertHdProjectTaskDeletable(HdProjectTask $task): void
  {
    if (! $this->hdProjectTaskIsDeletable($task)) {
      abort(422, 'Task dengan status Review atau Done tidak dapat dihapus.');
    }
  }

  public function updateHdProjectTask(HdProject $project, HdProjectTask $task, array $data, User $user): HdProjectTask
  {
    $this->assertCanEditHdProjectTasks($project, $user);

    if ($task->trashed()) {
      abort(422, 'Task terhapus tidak dapat diubah. Pulihkan terlebih dahulu.');
    }

    $payload = ['users_updated' => $user->id];

    if (isset($data['title'])) {
      $payload['subject'] = $data['title'];
    }
    if (isset($data['subject'])) {
      $payload['subject'] = $data['subject'];
    }
    if (array_key_exists('description', $data)) {
      $payload['description'] = $data['description'];
    }
    if (isset($data['status'])) {
      $payload['status'] = $data['status'];
    }
    if (isset($data['priority'])) {
      $payload['priority'] = $data['priority'];
    }
    if (array_key_exists('assignee_id', $data)) {
      $payload['assigned_to'] = $data['assignee_id'];
    }
    $payload = array_merge($payload, $this->resolveHdProjectTaskDatePayload($project, $data, true, $task));

    $task->update($payload);

    return $task->fresh(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']);
  }

  /**
   * @param  array<int, array{id: int, status: string, position: int}>  $items
   * @return array<int, array<string, mixed>>
   */
  public function reorderHdProjectTasks(HdProject $project, User $user, array $items): array
  {
    $this->assertCanEditHdProjectTasks($project, $user);

    foreach ($items as $item) {
      $task = HdProjectTask::forProject($project->id)->find($item['id'] ?? null);
      if (! $task) {
        continue;
      }

      $newStatus = $item['status'] ?? 'todo';
      $task->update([
        'status' => $newStatus,
        'position' => (int) ($item['position'] ?? 0),
        'users_updated' => $user->id,
        ...$this->autoAssignHdProjectTaskIfMovedFromBacklog($task, $newStatus, $user),
      ]);
    }

    return $this->listHdProjectTasks($project, $user);
  }

  /**
   * Isi assigned_to dengan anggota yang menggeser task jika masih unassigned
   * dan status berubah dari backlog ke status lain (selaras tiket kategori).
   *
   * @return array<string, mixed>
   */
  protected function autoAssignHdProjectTaskIfMovedFromBacklog(HdProjectTask $task, string $newBoardStatus, User $actor): array
  {
    if ($task->assigned_to) {
      return [];
    }

    if ($task->status !== 'backlog') {
      return [];
    }

    if ($newBoardStatus === 'backlog') {
      return [];
    }

    return ['assigned_to' => $actor->id];
  }

  public function hdProjectTaskAttachmentToNoteArray(
    HdProjectTaskAttachment $attachment,
    int $projectId,
    User $viewer
  ): array {
    $meta = app(HdProjectTaskAttachmentService::class)->metadata($attachment, $projectId);

    return array_merge($meta, [
      'body' => $attachment->description,
      'user_id' => $attachment->users_created,
      'user' => $attachment->uploader ? $this->userToArray($attachment->uploader) : null,
      'is_mine' => (int) $attachment->users_created === (int) $viewer->id,
    ]);
  }

  public function hdProjectTaskToArray(HdProjectTask $task, int $position = 0, ?User $viewer = null): array
  {
    $projectId = $task->hd_projects_id;
    $task->loadMissing('hdProject');
    $hdProject = $task->hdProject;
    $attachments = $task->relationLoaded('attachments') && $viewer
      ? $task->attachments
        ->map(fn ($a) => $this->hdProjectTaskAttachmentToNoteArray($a, $projectId, $viewer))
        ->values()
        ->all()
      : ($task->relationLoaded('attachments')
        ? $task->attachments
          ->map(fn ($a) => app(HdProjectTaskAttachmentService::class)->metadata($a, $projectId))
          ->values()
          ->all()
        : []);
// test
    return [
      'id' => $task->id,
      'hd_projects_id' => $task->hd_projects_id,
      'hris_project_id' => $task->hd_projects_id,
      'project_id' => $task->hd_projects_id,
      'title' => $task->subject,
      'subject' => $task->subject,
      'description' => $task->description,
      'task_number' => $task->task_number,
      'status' => $task->status,
      'priority' => $task->priority,
      'position' => $position ?: (int) $task->position,
      'assignee_id' => $task->assigned_to,
      'assignee' => $task->assignee ? $this->userToArray($task->assignee) : null,
      'reporter_id' => $task->reporter_id,
      'reporter' => $task->reporter ? $this->userToArray($task->reporter) : null,
      'start_date' => AppDateTime::toIso($task->start_date),
      'end_date' => AppDateTime::toIso($task->end_date),
      'hris_project_start_date' => AppDateTime::toIso($hdProject?->start_date),
      'hris_project_end_date' => AppDateTime::toIso($hdProject?->end_date),
      'attachments' => $attachments,
      'deleted_at' => AppDateTime::toIso($task->deleted_at),
      'is_trashed' => $task->trashed(),
      'can_delete' => $this->hdProjectTaskIsDeletable($task),
      'can_restore' => $task->trashed(),
      'can_force_delete' => $task->trashed(),
      'created_at' => AppDateTime::toIso($task->created_at),
      'updated_at' => AppDateTime::toIso($task->updated_at),
    ];
  }

  protected function normalizeHdProjectStatus(string $status): string
  {
    $allowed = ['planning', 'active', 'on_hold', 'completed', 'archived'];
    if (in_array($status, $allowed, true)) {
      return $status;
    }

    $legacy = [
      'pending' => 'planning',
      'backlog' => 'planning',
      'todo' => 'active',
      'in_progress' => 'active',
      'review' => 'active',
      'done' => 'completed',
    ];

    return $legacy[$status] ?? 'planning';
  }

  /** @deprecated gunakan normalizeHdProjectStatus */
  protected function normalizeHdProjectBoardStatus(string $status): string
  {
    return $this->normalizeHdProjectStatus($status);
  }

  protected function generateProjectNumber(int $companyId): string
  {
    $prefix = 'PRJ-'.now()->format('Ymd');
    $last = HdProject::where('companies_id', $companyId)
      ->where('project_number', 'like', $prefix.'%')
      ->count() + 1;

    return sprintf('%s-%03d', $prefix, $last);
  }

  protected function generateHdProjectTaskNumber(int $companyId): string
  {
    $prefix = 'PTSK-'.now()->format('Ymd');
    $last = HdProjectTask::where('companies_id', $companyId)
      ->where('task_number', 'like', $prefix.'%')
      ->count() + 1;

    return sprintf('%s-%03d', $prefix, $last);
  }
}
