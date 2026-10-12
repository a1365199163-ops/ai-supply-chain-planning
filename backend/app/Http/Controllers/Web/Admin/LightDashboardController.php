<?php

namespace App\Http\Controllers\Web\Admin;

use App\Models\Issue;
use App\Models\WorkOrder;
use Inertia\Inertia;
use Inertia\Response;

class LightDashboardController
{
    public function progress(): Response
    {
        return Inertia::render('admin/LightProgress', [
            'orders' => WorkOrder::query()->with('line:id,name')
                ->whereNotIn('status', ['DONE', 'CANCELLED', 'REJECTED'])
                ->orderByDesc('updated_at')->paginate(20, ['id','order_no','line_id','status','planned_qty','produced_qty','due_date']),
            'refreshedAt' => now()->format('H:i:s'),
        ]);
    }

    public function index(): Response
    {
        $active = WorkOrder::query()->whereNotIn('status', ['DONE', 'CANCELLED', 'REJECTED']);
        $canReadIssues = auth()->user()->hasRole('Admin')
            && \App\Support\ModuleRegistry::isModuleEnabled('quality');
        $openIssues = Issue::query()->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);

        return Inertia::render('admin/LightDashboard', [
            'overview' => [
                'active' => (clone $active)->count(),
                'running' => (clone $active)->where('status', 'IN_PROGRESS')->count(),
                'blocked' => (clone $active)->whereIn('status', ['BLOCKED', 'CHANGE_HOLD'])->count(),
                'issues' => $canReadIssues ? (clone $openIssues)->count() : null,
            ],
            'recentOrders' => (clone $active)->orderByDesc('updated_at')->limit(6)
                ->get(['id', 'order_no', 'status', 'planned_qty', 'produced_qty']),
            'recentIssues' => $canReadIssues ? (clone $openIssues)->orderByDesc('id')->limit(4)
                ->get(['id', 'title', 'status']) : [],
            'refreshedAt' => now()->format('H:i:s'),
        ]);
    }
}
