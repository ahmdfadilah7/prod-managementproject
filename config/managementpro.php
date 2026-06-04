<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Mode integrasi database HRIS (db_ask_hris)
  |--------------------------------------------------------------------------
  |
  | true  → projects/tasks/comments memakai hd_categories, hd_sub_categories,
  |         hd_projects, hd_tickets, hd_ticket_messages
  | false → skema ManagementPro standar (projects, tasks, comments)
  |
  */
  'hris_mode' => env('HRIS_MODE', false),

  'default_company_id' => (int) env('HRIS_DEFAULT_COMPANY_ID', 1),

  /*
  | Lampiran task project (hd_project_task_attachments) — SELALU disk public ManagementPro
  | (storage/app/public/hd-project-tasks/{task_id}/), tidak memakai HRIS_STORAGE_*.
  |
  | Lampiran pesan tiket helpdesk (hd_ticket_messages) — kompatibel Filament HRIS.
  | Path di DB: helpdesk-ticket-messages/01KT6XDAE2SERD2R2R7M1ME5CR.jpg
  | URL publik: {url}{url_prefix}/helpdesk-ticket-messages/{file}
  */
  'hris_storage' => [
    /** URL publik website HRIS (untuk baca lampiran). */
    'url' => rtrim((string) env('HRIS_STORAGE_URL', ''), '/'),
    /** Subfolder di storage/app/public HRIS (Filament FileUpload directory). */
    'path' => trim((string) env('HRIS_STORAGE_PATH', 'helpdesk-ticket-messages'), '/'),
    /**
     * Path absolut ke storage/app/public aplikasi HRIS (WAJIB untuk upload lampiran tiket).
     * Contoh Linux: /var/www/taptask/backend/storage/app/public
     * Jangan kosong dan jangan sama dengan storage ManagementPro.
     */
    'root' => env('HRIS_STORAGE_ROOT'),
    'url_prefix' => '/'.trim((string) env('HRIS_STORAGE_URL_PREFIX', 'storage'), '/'),
    /** true = jika HRIS root gagal, simpan ke storage ManagementPro (dev). Production: false. */
    'allow_local_fallback' => filter_var(
      env('HRIS_STORAGE_ALLOW_LOCAL_FALLBACK', env('APP_ENV') === 'local'),
      FILTER_VALIDATE_BOOL
    ),
  ],

  /*
  | Zona waktu tampilan jika user tidak punya cabang / non-HRIS.
  */
  'fallback_display_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),

  /*
  | URL frontend SPA (link reset password, dll.)
  */
  'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

  /*
  | Login — remember me (Sanctum token expires_at)
  */
  'auth' => [
    'remember_token_days' => (int) env('AUTH_REMEMBER_TOKEN_DAYS', 30),
    'session_token_hours' => (int) env('AUTH_SESSION_TOKEN_HOURS', 8),
  ],

  /*
  | Reset password — set PASSWORD_RESET_SEND_MAIL=true setelah SMTP siap
  */
  'password_reset' => [
    'send_mail' => env('PASSWORD_RESET_SEND_MAIL', false),
    'log_channel' => env('PASSWORD_RESET_LOG_CHANNEL', 'stack'),
  ],

  /*
  | Mapping permission ManagementPro → Spatie HRIS (guard web)
  */
  'permission_map' => [
    'dashboard.view' => 'view_any_hd::ticket',
    'projects.view' => 'view_any_hd::category',
    'projects.create' => 'create_hd::category',
    'projects.update' => 'update_hd::category',
    'projects.delete' => 'delete_hd::category',
    'tasks.view' => 'view_any_hd::ticket',
    'tasks.create' => 'create_hd::ticket',
    'tasks.update' => 'update_hd::ticket',
    'tasks.delete' => 'delete_hd::ticket',
    'users.view' => 'view_any_user',
    'users.create' => 'create_user',
    'users.update' => 'update_user',
    'users.delete' => 'delete_user',
    'users.toggle_status' => 'update_user',
    'roles.view' => 'view_any_role',
    'roles.create' => 'create_role',
    'roles.update' => 'update_role',
    'roles.delete' => 'delete_role',
  ],

  /*
  | Status kanban (frontend) ↔ status ticket HRIS
  */
  'ticket_status_to_board' => [
    'pending' => 'backlog',
    'open' => 'todo',
    'processing' => 'in_progress',
    'resolved' => 'review',
    'closed' => 'done',
  ],

  'board_status_to_ticket' => [
    'backlog' => 'pending',
    'todo' => 'open',
    'in_progress' => 'processing',
    'review' => 'resolved',
    'done' => 'closed',
  ],

  'priority_to_board' => [
    'low' => 'low',
    'medium' => 'medium',
    'high' => 'high',
    'critical' => 'critical',
    'urgent' => 'critical',
  ],

  'board_priority_to_ticket' => [
    'low' => 'low',
    'medium' => 'medium',
    'high' => 'high',
    'critical' => 'critical',
    'urgent' => 'critical',
  ],

  /*
  | Label & warna badge export Excel — status = nilai kolom database (hd_tickets.status)
  */
  'ticket_export_status_styles' => [
    'pending' => ['label' => 'pending', 'font' => '475569', 'background' => 'F1F5F9', 'border' => 'CBD5E1'],
    'open' => ['label' => 'open', 'font' => '1D4ED8', 'background' => 'DBEAFE', 'border' => '93C5FD'],
    'processing' => ['label' => 'processing', 'font' => 'B45309', 'background' => 'FEF3C7', 'border' => 'FCD34D'],
    'resolved' => ['label' => 'resolved', 'font' => '7E22CE', 'background' => 'F3E8FF', 'border' => 'D8B4FE'],
    'closed' => ['label' => 'closed', 'font' => '047857', 'background' => 'D1FAE5', 'border' => '6EE7B7'],
  ],

  'ticket_export_priority_styles' => [
    'low' => ['label' => 'Rendah', 'font' => '334155', 'background' => 'F1F5F9', 'border' => 'CBD5E1'],
    'medium' => ['label' => 'Sedang', 'font' => '0369A1', 'background' => 'E0F2FE', 'border' => '7DD3FC'],
    'high' => ['label' => 'Tinggi', 'font' => 'B45309', 'background' => 'FEF3C7', 'border' => 'FCD34D'],
    'critical' => ['label' => 'Kritis', 'font' => 'B91C1C', 'background' => 'FEE2E2', 'border' => 'FCA5A5'],
  ],

  /** Label status kanban task project di export Excel */
  'project_task_export_status_styles' => [
    'backlog' => ['label' => 'Backlog', 'font' => '475569', 'background' => 'F1F5F9', 'border' => 'CBD5E1'],
    'todo' => ['label' => 'To Do', 'font' => '1D4ED8', 'background' => 'DBEAFE', 'border' => '93C5FD'],
    'in_progress' => ['label' => 'In Progress', 'font' => 'B45309', 'background' => 'FEF3C7', 'border' => 'FCD34D'],
    'review' => ['label' => 'Review', 'font' => '7E22CE', 'background' => 'F3E8FF', 'border' => 'D8B4FE'],
    'done' => ['label' => 'Done', 'font' => '047857', 'background' => 'D1FAE5', 'border' => '6EE7B7'],
  ],

  /** Role bawaan HRIS (Spatie) — tidak boleh dihapus */
  'system_roles' => ['super_admin', 'hr', 'ga', 'employee'],

  'role_colors' => [
    'super_admin' => '#7c3aed',
    'hr' => '#ec4899',
    'ga' => '#14b8a6',
    'employee' => '#6366f1',
  ],

  'role_levels' => [
    'super_admin' => 99,
    'hr' => 80,
    'ga' => 60,
    'employee' => 40,
  ],

  'ticket_message_attachment' => [
    'max_kb' => (int) env('TICKET_MESSAGE_MAX_KB', 10240),
    'mimes' => env('TICKET_MESSAGE_MIMES', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar'),
  ],

  'permission_group_labels' => [
    'hd_category' => 'Helpdesk — Kategori',
    'hd_ticket' => 'Helpdesk — Ticket',
    'hd_sub_category' => 'Helpdesk — Sub Kategori',
    'user' => 'Pengguna',
    'role' => 'Role & Permission',
    'employee' => 'Karyawan',
    'branch' => 'Cabang',
    'company' => 'Perusahaan',
    'asset_assignment' => 'Aset — Penugasan',
    'asset_category' => 'Aset — Kategori',
  ],

];
