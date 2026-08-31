<?php

namespace Tests\Feature;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminResellerRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_enable_and_disable_reseller_role(): void
    {
        $admin = $this->makeUser('admin@example.test', ['is_admin' => true]);
        $referrer = $this->makeUser('referrer@example.test');
        $customer = $this->makeUser('customer@example.test', [
            'invite_user_id' => $referrer->id,
        ]);
        Sanctum::actingAs($admin);

        $fetch = $this->getJson('/api/v2/Huy2006/user/fetch?current=1&pageSize=50');
        $fetch->assertOk();
        $row = collect($fetch->json('data'))->firstWhere('id', $customer->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_reseller']);

        $this->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'is_reseller' => true,
        ])->assertOk()->assertJson([
            'status' => 'success',
            'data' => true,
        ]);

        $customer->refresh();
        $this->assertIsBool($customer->is_reseller);
        $this->assertTrue($customer->is_reseller);
        $this->assertSame($referrer->id, $customer->invite_user_id);

        $this->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'is_reseller' => false,
        ])->assertOk()->assertJson([
            'status' => 'success',
            'data' => true,
        ]);

        $customer->refresh();
        $this->assertFalse($customer->is_reseller);
        $this->assertSame($referrer->id, $customer->invite_user_id);
    }

    public function test_reseller_role_defaults_to_false_and_rejects_invalid_values(): void
    {
        $admin = $this->makeUser('validation-admin@example.test', ['is_admin' => true]);
        $customer = $this->makeUser('validation-customer@example.test');
        Sanctum::actingAs($admin);

        $this->assertFalse($customer->fresh()->is_reseller);

        $this->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'is_reseller' => 'not-a-boolean',
        ])->assertStatus(422)->assertJsonValidationErrors('is_reseller');

        $this->assertFalse($customer->fresh()->is_reseller);
    }

    public function test_admin_can_filter_and_sort_users_by_reseller_role(): void
    {
        $admin = $this->makeUser('filter-admin@example.test', ['is_admin' => true]);
        $lastAlphabetically = $this->makeUser('z-reseller@example.test', ['is_reseller' => true]);
        $firstAlphabetically = $this->makeUser('a-reseller@example.test', ['is_reseller' => true]);
        $this->makeUser('ordinary-customer@example.test');
        Sanctum::actingAs($admin);

        $fetch = $this->postJson('/api/v2/Huy2006/user/fetch', [
            'current' => 1,
            'pageSize' => 50,
            'filter' => [[
                'id' => 'is_reseller',
                'value' => [true],
            ]],
            'sort' => [[
                'id' => 'email',
                'desc' => false,
            ]],
        ]);

        $fetch->assertOk();
        $this->assertSame(
            [$firstAlphabetically->id, $lastAlphabetically->id],
            collect($fetch->json('data'))->pluck('id')->all()
        );

        $this->postJson('/api/v2/Huy2006/user/fetch', [
            'filter' => [[
                'id' => 'password',
                'value' => 'hash-fragment',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('filter.0.id');
    }

    public function test_non_admin_and_reseller_only_accounts_cannot_change_roles(): void
    {
        $ordinaryActor = $this->makeUser('ordinary-actor@example.test');
        $staffActor = $this->makeUser('staff-actor@example.test', ['is_staff' => true]);
        $resellerActor = $this->makeUser('reseller-actor@example.test', ['is_reseller' => true]);
        $target = $this->makeUser('permission-target@example.test');

        foreach ([$ordinaryActor, $staffActor, $resellerActor] as $actor) {
            Sanctum::actingAs($actor);

            $this->postJson('/api/v2/Huy2006/user/update', [
                'id' => $target->id,
                'is_reseller' => true,
            ])->assertForbidden();

            $this->assertFalse($target->fresh()->is_reseller);
        }
    }

    public function test_referral_owner_changes_only_when_explicitly_requested(): void
    {
        $admin = $this->makeUser('ownership-admin@example.test', ['is_admin' => true]);
        $originalReferrer = $this->makeUser('original-referrer@example.test');
        $replacementReferrer = $this->makeUser('replacement-referrer@example.test');
        $customer = $this->makeUser('owned-customer@example.test', [
            'invite_user_id' => $originalReferrer->id,
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('Content-Language', 'vi-VN')->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => 'missing-referrer@example.test',
        ])->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => 'Người giới thiệu không tồn tại',
        ]);
        $this->assertSame($originalReferrer->id, $customer->fresh()->invite_user_id);

        $this->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => $replacementReferrer->email,
        ])->assertOk();
        $this->assertSame($replacementReferrer->id, $customer->fresh()->invite_user_id);

        $this->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => '',
        ])->assertOk();
        $this->assertNull($customer->fresh()->invite_user_id);
    }

    public function test_referral_owner_cannot_be_the_user_itself(): void
    {
        $admin = $this->makeUser('self-cycle-admin@example.test', ['is_admin' => true]);
        $originalReferrer = $this->makeUser('self-cycle-original@example.test');
        $customer = $this->makeUser('self-cycle-customer@example.test', [
            'invite_user_id' => $originalReferrer->id,
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('Content-Language', 'en-US')->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => $customer->email,
        ])->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => 'The referral relationship would create a cycle',
        ]);

        $this->assertSame($originalReferrer->id, $customer->fresh()->invite_user_id);
    }

    public function test_two_node_referral_cycle_is_rejected_without_changing_owner(): void
    {
        $admin = $this->makeUser('two-cycle-admin@example.test', ['is_admin' => true]);
        $originalReferrer = $this->makeUser('two-cycle-original@example.test');
        $customer = $this->makeUser('two-cycle-customer@example.test', [
            'invite_user_id' => $originalReferrer->id,
        ]);
        $descendant = $this->makeUser('two-cycle-descendant@example.test', [
            'invite_user_id' => $customer->id,
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('Content-Language', 'vi-VN')->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => $descendant->email,
        ])->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => 'Quan hệ người giới thiệu sẽ tạo thành vòng lặp',
        ]);

        $this->assertSame($originalReferrer->id, $customer->fresh()->invite_user_id);
    }

    public function test_three_node_referral_cycle_is_rejected_without_changing_owner(): void
    {
        $admin = $this->makeUser('three-cycle-admin@example.test', ['is_admin' => true]);
        $originalReferrer = $this->makeUser('three-cycle-original@example.test');
        $customer = $this->makeUser('three-cycle-customer@example.test', [
            'invite_user_id' => $originalReferrer->id,
        ]);
        $middleDescendant = $this->makeUser('three-cycle-middle@example.test', [
            'invite_user_id' => $customer->id,
        ]);
        $deepDescendant = $this->makeUser('three-cycle-deep@example.test', [
            'invite_user_id' => $middleDescendant->id,
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('Content-Language', 'vi-VN')->postJson('/api/v2/Huy2006/user/update', [
            'id' => $customer->id,
            'invite_user_email' => $deepDescendant->email,
        ])->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => 'Quan hệ người giới thiệu sẽ tạo thành vòng lặp',
        ]);

        $this->assertSame($originalReferrer->id, $customer->fresh()->invite_user_id);
    }

    public function test_reseller_schema_has_the_ownership_query_index(): void
    {
        $this->assertTrue(Schema::hasColumn('v2_user', 'is_reseller'));
        $this->assertTrue(Schema::hasIndex('v2_user', 'v2_user_referral_roles_id_idx'));
    }

    public function test_reseller_migration_up_and_down_are_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_31_120000_add_is_reseller_to_users.php');

        try {
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('v2_user', 'is_reseller'));
            $this->assertFalse(Schema::hasIndex('v2_user', 'v2_user_referral_roles_id_idx'));

            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasColumn('v2_user', 'is_reseller'));
            $this->assertTrue(Schema::hasIndex('v2_user', 'v2_user_referral_roles_id_idx'));
        } finally {
            // Keep the shared test schema valid even if an assertion fails.
            $migration->up();
        }
    }

    private function makeUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('reseller-test-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => false,
            'is_admin' => false,
            'is_staff' => false,
            'expired_at' => 0,
            'remind_expire' => true,
            'remind_traffic' => true,
        ], $overrides));
    }
}
