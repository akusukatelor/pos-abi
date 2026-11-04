<?php

namespace Tests\Feature;

use App\Models\ServiceMarginLog;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_service_type_and_cashier_cannot_manage_master_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/service-types', [
            'name' => 'PPOB',
            'description' => 'Pembayaran tagihan dan top up',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'PPOB')
            ->assertJsonPath('data.is_active', true);

        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/service-types', [
            'name' => 'BRILink',
        ])->assertForbidden();

        $this->assertDatabaseCount('service_types', 1);
    }

    public function test_cashier_can_record_successful_service_with_unknown_margin(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'BRILink', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $response = $this->postJson('/api/service-transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'service_type_id' => $serviceType->id,
            'reference_number' => 'REF-BRI-001',
            'nominal_amount' => 250000,
            'notes' => 'Transfer berhasil',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.margin_amount', null)
            ->assertJsonPath('data.margin_verified_by', null)
            ->assertJsonPath('data.user_id', $cashier->id);

        $this->assertDatabaseHas('service_transactions', [
            'user_id' => $cashier->id,
            'service_type_id' => $serviceType->id,
            'nominal_amount' => '250000.00',
            'margin_amount' => null,
            'status' => 'completed',
        ]);
    }

    public function test_owner_can_record_service_transaction(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/service-transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'service_type_id' => $serviceType->id,
            'nominal_amount' => 100000,
            'margin_amount' => 2500,
        ])->assertCreated()
            ->assertJsonPath('data.margin_amount', '2500.00')
            ->assertJsonPath('data.margin_verified_by', null);
    }

    public function test_inactive_service_type_cannot_be_used_for_new_transaction(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->deleteJson('/api/service-types/'.$serviceType->id)->assertOk();

        Sanctum::actingAs($cashier);
        $this->postJson('/api/service-transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'service_type_id' => $serviceType->id,
        ])->assertUnprocessable();
    }

    public function test_owner_can_verify_margin_and_system_keeps_margin_history(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'BRILink', 'is_active' => true]);
        $service = ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '500000.00',
            'margin_amount' => null,
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-a'),
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/service-transactions/'.$service->id.'/verify-margin', [
            'margin_amount' => 5000,
        ])->assertOk()
            ->assertJsonPath('data.margin_amount', '5000.00')
            ->assertJsonPath('data.margin_verified_by', $owner->id);

        $this->assertDatabaseHas('service_margin_logs', [
            'service_transaction_id' => $service->id,
            'changed_by' => $owner->id,
            'old_margin_amount' => null,
            'new_margin_amount' => '5000.00',
        ]);

        $this->postJson('/api/service-transactions/'.$service->id.'/verify-margin', [
            'margin_amount' => 6000,
        ])->assertUnprocessable();

        $this->postJson('/api/service-transactions/'.$service->id.'/verify-margin', [
            'margin_amount' => 6000,
            'note' => 'Koreksi komisi dari bukti settlement',
        ])->assertOk()
            ->assertJsonPath('data.margin_amount', '6000.00');

        $this->assertDatabaseCount('service_margin_logs', 2);
        $this->assertDatabaseHas('service_margin_logs', [
            'service_transaction_id' => $service->id,
            'old_margin_amount' => '5000.00',
            'new_margin_amount' => '6000.00',
            'note' => 'Koreksi komisi dari bukti settlement',
        ]);
    }

    public function test_cashier_cannot_verify_margin_or_cancel_service_transaction(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);
        $service = ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-b'),
        ]);

        Sanctum::actingAs($cashier);

        $this->postJson('/api/service-transactions/'.$service->id.'/verify-margin', [
            'margin_amount' => 1000,
        ])->assertForbidden();

        $this->postJson('/api/service-transactions/'.$service->id.'/cancel', [
            'cancellation_reason' => 'Salah input',
        ])->assertForbidden();

        $this->assertDatabaseHas('service_transactions', [
            'id' => $service->id,
            'status' => 'completed',
        ]);
    }

    public function test_owner_can_cancel_service_transaction_with_audit_information(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'BRILink', 'is_active' => true]);
        $service = ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-c'),
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/service-transactions/'.$service->id.'/cancel', [
            'cancellation_reason' => 'Salah pencatatan transaksi',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', $owner->id)
            ->assertJsonPath('data.cancellation_reason', 'Salah pencatatan transaksi');

        $this->assertDatabaseHas('service_transactions', [
            'id' => $service->id,
            'status' => 'cancelled',
            'cancelled_by' => $owner->id,
            'cancellation_reason' => 'Salah pencatatan transaksi',
        ]);
    }

    public function test_service_transaction_idempotency_prevents_duplicate_records(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'service_type_id' => $serviceType->id,
            'nominal_amount' => 75000,
        ];

        $this->postJson('/api/service-transactions', $payload)->assertCreated();
        $this->postJson('/api/service-transactions', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('service_transactions', 1);

        $payload['nominal_amount'] = 80000;
        $this->postJson('/api/service-transactions', $payload)->assertStatus(409);
        $this->assertDatabaseCount('service_transactions', 1);
    }

    public function test_cashier_only_sees_own_service_transactions(): void
    {
        $cashierOne = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $cashierTwo = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);

        foreach ([$cashierOne, $cashierTwo] as $index => $cashier) {
            ServiceTransaction::create([
                'service_type_id' => $serviceType->id,
                'user_id' => $cashier->id,
                'service_at' => now()->addSeconds($index),
                'status' => 'completed',
                'idempotency_key' => (string) Str::uuid(),
                'idempotency_hash' => hash('sha256', 'service-'.$index),
            ]);
        }

        Sanctum::actingAs($cashierOne);
        $this->getJson('/api/service-transactions')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/service-transactions')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }
}
