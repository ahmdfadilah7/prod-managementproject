<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HrisProjectController;
use App\Http\Controllers\Api\HrisProjectTaskAttachmentController;
use App\Http\Controllers\Api\HrisProjectTaskController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\MyProjectsController;
use App\Http\Controllers\Api\MyTasksController;
use App\Http\Controllers\Api\UnassignedProjectsController;
use App\Http\Controllers\Api\UnassignedTasksController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketMessageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [ProfileController::class, 'update']);

        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:dashboard.view');

        Route::get('/calendar', [CalendarController::class, 'index'])
            ->middleware('permission:tasks.view');

        Route::get('/reports', [ReportsController::class, 'index'])
            ->middleware('permission:tasks.view');
        Route::get('/reports/export/tickets', [ReportsController::class, 'exportTickets'])
            ->middleware('permission:tasks.view');
        Route::get('/reports/export/project-tasks', [ReportsController::class, 'exportProjectTasks'])
            ->middleware('permission:tasks.view');

        Route::get('/my-tasks', [MyTasksController::class, 'index'])
            ->middleware('permission:tasks.view');

        Route::get('/my-projects', [MyProjectsController::class, 'index'])
            ->middleware('permission:tasks.view');

        Route::get('/unassigned-tasks', [UnassignedTasksController::class, 'index'])
            ->middleware('permission:tasks.view');
        Route::post('/unassigned-tasks/bulk-assign', [UnassignedTasksController::class, 'bulkAssign'])
            ->middleware('permission:tasks.update');

        Route::get('/unassigned-projects', [UnassignedProjectsController::class, 'index'])
            ->middleware('permission:tasks.view');
        Route::post('/unassigned-projects/bulk-assign', [UnassignedProjectsController::class, 'bulkAssign'])
            ->middleware('permission:tasks.update');

        Route::get('/ticket-messages', [TicketMessageController::class, 'index'])
            ->middleware('permission:tasks.view');
        Route::get('/ticket-messages/{ticket}', [TicketMessageController::class, 'show'])
            ->middleware('permission:tasks.view');
        Route::post('/ticket-messages/{ticket}', [TicketMessageController::class, 'store'])
            ->middleware('permission:tasks.view');
        Route::delete('/ticket-messages/{ticket}/{message}', [TicketMessageController::class, 'destroy'])
            ->middleware('permission:tasks.view');
        Route::get('/ticket-messages/{ticket}/{message}/attachment', [TicketMessageController::class, 'attachment'])
            ->middleware('permission:tasks.view');

        Route::get('/users', [UserController::class, 'index']);

        Route::get('/hris-projects/form-options', [HrisProjectController::class, 'formOptions']);
        Route::post('/hris-projects/{hris_project}/restore', [HrisProjectController::class, 'restore']);
        Route::delete('/hris-projects/{hris_project}/force', [HrisProjectController::class, 'forceDestroy']);
        Route::apiResource('hris-projects', HrisProjectController::class);
        Route::get('/hris-projects/{hris_project}/tasks', [HrisProjectTaskController::class, 'index']);
        Route::post('/hris-projects/{hris_project}/tasks', [HrisProjectTaskController::class, 'store']);
        Route::post('/hris-projects/{hris_project}/tasks/reorder', [HrisProjectTaskController::class, 'reorder']);
        Route::get('/hris-projects/{hris_project}/tasks/{task}', [HrisProjectTaskController::class, 'show']);
        Route::put('/hris-projects/{hris_project}/tasks/{task}', [HrisProjectTaskController::class, 'update']);
        Route::delete('/hris-projects/{hris_project}/tasks/{task}', [HrisProjectTaskController::class, 'destroy']);
        Route::post('/hris-projects/{hris_project}/tasks/{task}/restore', [HrisProjectTaskController::class, 'restore']);
        Route::delete('/hris-projects/{hris_project}/tasks/{task}/force', [HrisProjectTaskController::class, 'forceDestroy']);
        Route::post('/hris-projects/{hris_project}/tasks/{task}/attachments', [HrisProjectTaskAttachmentController::class, 'store']);
        Route::put('/hris-projects/{hris_project}/tasks/{task}/attachments/{attachment}', [HrisProjectTaskAttachmentController::class, 'update']);
        Route::delete('/hris-projects/{hris_project}/tasks/{task}/attachments/{attachment}', [HrisProjectTaskAttachmentController::class, 'destroy']);
        Route::get('/hris-projects/{hris_project}/tasks/{task}/attachments/{attachment}', [HrisProjectTaskAttachmentController::class, 'download']);

        Route::apiResource('projects', ProjectController::class);
        Route::post('/projects/{project}/restore', [ProjectController::class, 'restore']);
        Route::delete('/projects/{project}/force', [ProjectController::class, 'forceDestroy']);
        Route::post('/projects/{project}/sub-categories', [ProjectController::class, 'storeSubCategory']);
        Route::put('/projects/{project}/sub-categories/{subCategory}', [ProjectController::class, 'updateSubCategory']);
        Route::delete('/projects/{project}/sub-categories/{subCategory}', [ProjectController::class, 'destroySubCategory']);
        Route::get('/projects/{project}/activities', [ProjectController::class, 'activities']);
        Route::get('/projects/{project}/members', [ProjectController::class, 'members']);
        Route::post('/projects/{project}/members', [ProjectController::class, 'addMember']);

        Route::get('/projects/{project}/tasks', [TaskController::class, 'index']);
        Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
        Route::post('/projects/{project}/tasks/reorder', [TaskController::class, 'reorder']);
        Route::get('/projects/{project}/tasks/{task}', [TaskController::class, 'show']);
        Route::put('/projects/{project}/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/projects/{project}/tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('/projects/{project}/tasks/{task}/restore', [TaskController::class, 'restore']);
        Route::delete('/projects/{project}/tasks/{task}/force', [TaskController::class, 'forceDestroy']);

        Route::post('/projects/{project}/tasks/{task}/comments', [CommentController::class, 'store']);
        Route::delete('/projects/{project}/tasks/{task}/comments/{comment}', [CommentController::class, 'destroy']);

        Route::post('/projects/{project}/labels', [LabelController::class, 'store']);
        Route::delete('/projects/{project}/labels/{label}', [LabelController::class, 'destroy']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    });
});
