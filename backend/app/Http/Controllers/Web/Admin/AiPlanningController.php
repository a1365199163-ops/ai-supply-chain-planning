<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupplyChain\SupplyChainPlanningAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiPlanningController extends Controller
{
    public function index(Request $request, SupplyChainPlanningAssistant $assistant): Response
    {
        $horizon = (int) $request->query('horizon', 30);
        abort_unless(in_array($horizon, [7, 30, 90], true), 404);

        return Inertia::render('admin/AiPlanning', [
            'planning' => $assistant->snapshot($horizon),
            'refreshedAt' => now()->format('H:i:s'),
            'horizon' => $horizon,
        ]);
    }

    public function ask(Request $request, SupplyChainPlanningAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:500'],
            'horizon' => ['nullable', 'integer', 'in:7,30,90'],
        ]);

        return response()->json($assistant->answer(trim($validated['question']), (int) ($validated['horizon'] ?? 30)));
    }
}
