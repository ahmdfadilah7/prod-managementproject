<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabelResource;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        if (! $project->hasMember($request->user())) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $label = $project->labels()->create($validated);

        return response()->json([
            'data' => new LabelResource($label),
        ], 201);
    }

    public function destroy(Request $request, Project $project, Label $label): JsonResponse
    {
        if (! $project->hasMember($request->user()) || $label->project_id !== $project->id) {
            abort(403);
        }

        $label->delete();

        return response()->json(['message' => 'Label dihapus']);
    }
}
