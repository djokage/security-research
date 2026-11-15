<?php

namespace Tests\Feature;

use App\Livewire\GlobalHookProbe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalHookJsonAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('update-document', function (User $user, int $documentId): bool {
            $ownerId = DB::table('documents')->where('id', $documentId)->value('owner_id');

            return (bool) $user->is_admin || (int) $user->id === (int) $ownerId;
        });
    }

    public function test_json_method_side_effect_happens_before_global_hook_rejects_call(): void
    {
        [$member, $admin, $documentId] = $this->createAdminOwnedDocument();

        Livewire::actingAs($member)
            ->test(GlobalHookProbe::class)
            ->call('updateJson', $documentId)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'owner_id' => $admin->id,
            'value' => 'modified-by-json',
            'changed_by_user_id' => $member->id,
        ]);
    }

    public function test_ordinary_method_is_rejected_before_its_side_effect(): void
    {
        [$member, $admin, $documentId] = $this->createAdminOwnedDocument();

        Livewire::actingAs($member)
            ->test(GlobalHookProbe::class)
            ->call('updateOrdinary', $documentId)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'owner_id' => $admin->id,
            'value' => 'admin-owned-original',
            'changed_by_user_id' => null,
        ]);
    }

    public function test_inline_gate_authorization_prevents_json_side_effect(): void
    {
        [$member, $admin, $documentId] = $this->createAdminOwnedDocument();

        Livewire::actingAs($member)
            ->test(GlobalHookProbe::class)
            ->call('updateJsonWithInlineGate', $documentId)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'owner_id' => $admin->id,
            'value' => 'admin-owned-original',
            'changed_by_user_id' => null,
        ]);
    }

    public function test_builtin_authorize_attribute_prevents_json_side_effect(): void
    {
        [$member, $admin, $documentId] = $this->createAdminOwnedDocument();

        Livewire::actingAs($member)
            ->test(GlobalHookProbe::class)
            ->call('updateJsonWithAuthorize', $documentId)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'owner_id' => $admin->id,
            'value' => 'admin-owned-original',
            'changed_by_user_id' => null,
        ]);
    }

    public function test_administrator_can_update_the_record(): void
    {
        [, $admin, $documentId] = $this->createAdminOwnedDocument();

        Livewire::actingAs($admin)
            ->test(GlobalHookProbe::class)
            ->call('updateJson', $documentId)
            ->assertStatus(200);

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'owner_id' => $admin->id,
            'value' => 'modified-by-json',
            'changed_by_user_id' => $admin->id,
        ]);
    }

    private function createAdminOwnedDocument(): array
    {
        $member = User::factory()->create();
        $member->forceFill(['is_admin' => false])->save();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $documentId = DB::table('documents')->insertGetId([
            'owner_id' => $admin->id,
            'value' => 'admin-owned-original',
            'changed_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$member, $admin, $documentId];
    }
}
