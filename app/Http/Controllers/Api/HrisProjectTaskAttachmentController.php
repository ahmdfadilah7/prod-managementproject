<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HdProjectTaskAttachment;
use App\Services\HdProjectTaskAttachmentService;
use App\Services\HrisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrisProjectTaskAttachmentController extends Controller
{
    public function __construct(
        protected HrisWorkspaceService $hris,
        protected HdProjectTaskAttachmentService $attachments
    ) {}

    public function store(Request $request, int $hris_project, int $task): JsonResponse
    {
        $request->validate([
            'attachment' => $this->attachments->fileRules(),
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $hasFile = $request->hasFile('attachment');
        $hasDescription = trim((string) $request->input('description', '')) !== '';

        if (! $hasFile && ! $hasDescription) {
            return response()->json([
                'message' => 'Isi deskripsi atau pilih lampiran.',
            ], 422);
        }

        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());

        if ($item->trashed()) {
            abort(422, 'Task terhapus tidak dapat menerima catatan. Pulihkan terlebih dahulu.');
        }

        $this->attachments->store(
            $item,
            $request->file('attachment'),
            $request->user()->id,
            $request->input('description')
        );

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray(
                $item->fresh(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']),
                0,
                $request->user()
            ),
        ], 201);
    }

    public function update(Request $request, int $hris_project, int $task, int $attachment): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());

        $file = HdProjectTaskAttachment::where('hd_project_tasks_id', $item->id)->findOrFail($attachment);

        if ((int) $file->users_created !== (int) $request->user()->id) {
            abort(403, 'Hanya pengirim yang dapat mengubah catatan ini.');
        }

        $file = $this->attachments->updateDescription($file, $validated['description'] ?? null);

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray(
                $item->fresh(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']),
                0,
                $request->user()
            ),
        ]);
    }

    public function destroy(Request $request, int $hris_project, int $task, int $attachment): JsonResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());

        $file = HdProjectTaskAttachment::where('hd_project_tasks_id', $item->id)->findOrFail($attachment);

        if ((int) $file->users_created !== (int) $request->user()->id) {
            abort(403, 'Hanya pengirim yang dapat menghapus catatan ini.');
        }

        $this->attachments->delete($file);

        return response()->json([
            'data' => $this->hris->hdProjectTaskToArray(
                $item->fresh(['assignee.employee', 'reporter.employee', 'attachments.uploader.employee']),
                0,
                $request->user()
            ),
        ]);
    }

    public function download(Request $request, int $hris_project, int $task, int $attachment): StreamedResponse
    {
        $project = $this->hris->findAccessibleHdProject($request->user(), $hris_project);
        $item = $this->hris->findHdProjectTask($project, $task, $request->user());

        $file = HdProjectTaskAttachment::where('hd_project_tasks_id', $item->id)->findOrFail($attachment);

        return $this->attachments->download($file);
    }
}
