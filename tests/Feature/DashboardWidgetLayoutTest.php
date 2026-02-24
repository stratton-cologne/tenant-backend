<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardWidgetLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_and_load_dashboard_widget_layout(): void
    {
        $user = User::query()->create([
            'first_name' => 'Dash',
            'last_name' => 'User',
            'email' => 'dash-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        $save = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson('/api/tenant/dashboard/widgets', [
                'widgets' => [
                    [
                        'widget_key' => 'system.permissions',
                        'x' => 0,
                        'y' => 0,
                        'w' => 3,
                        'h' => 1,
                        'sort_order' => 0,
                        'enabled' => true,
                        'config' => [],
                    ],
                    [
                        'widget_key' => 'system.modules',
                        'x' => 3,
                        'y' => 0,
                        'w' => 6,
                        'h' => 1,
                        'sort_order' => 1,
                        'enabled' => false,
                        'config' => [],
                    ],
                ],
            ]);

        $save->assertOk();
        $save->assertJsonPath('status', 'ok');

        $load = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/dashboard/widgets');

        $load->assertOk();
        $load->assertJsonCount(2, 'data.widgets');
        $load->assertJsonPath('data.widgets.0.widget_key', 'system.permissions');
        $load->assertJsonPath('data.widgets.0.w', 3);
        $load->assertJsonPath('data.widgets.1.widget_key', 'system.modules');
        $load->assertJsonPath('data.widgets.1.enabled', false);
    }
}
