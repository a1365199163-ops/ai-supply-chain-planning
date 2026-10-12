<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LightProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_dashboard_uses_real_counts_and_excludes_finished_orders(): void
    {
        config(['light_production.enabled'=>true]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin=User::factory()->create(); $admin->assignRole('Admin');
        WorkOrder::factory()->create(['status'=>'IN_PROGRESS']);
        WorkOrder::factory()->create(['status'=>'BLOCKED']);
        WorkOrder::factory()->create(['status'=>'CANCELLED']);
        Issue::factory()->create(['work_order_id'=>null,'status'=>'OPEN']);
        Issue::factory()->create(['work_order_id'=>null,'status'=>'CLOSED']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/LightDashboard')->where('lightProduction',true)
                ->where('overview.active',2)->where('overview.running',1)
                ->where('overview.blocked',1)->where('overview.issues',1)
                ->has('recentOrders',2)->has('recentIssues',1));
        $this->get('/admin/shift-monitor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/LightProgress')->where('orders.total',2)
                ->has('orders.data',2));
    }

    public function test_light_preset_blocks_unneeded_modules_and_keeps_core_routes(): void
    {
        config(['light_production.enabled'=>true]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin=User::factory()->create(); $admin->assignRole('Admin');
        \App\Support\ModuleRegistry::save(['quality']);
        $this->actingAs($admin)->get('/admin/work-orders')->assertOk();
        $this->get('/admin/issues')->assertOk();
        $this->get('/admin/shift-monitor')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/LightProgress')->where('orders.total',0));
        $this->get('/admin/materials')->assertNotFound();
    }
}
