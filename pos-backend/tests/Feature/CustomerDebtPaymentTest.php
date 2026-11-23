<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDebtPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        return $owner;
    }

    private function customerWithDebt(int $totalCents = 1000000): array
    {
        $customer = Customer::create([
            'name' => 'Budi',
            'phone' => '081200000000',
            'total_debt' => number_format($totalCents / 100, 2, '.', ''),
        ]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);

        $transaction = Transaction::create([
            'user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-'.Str::upper(Str::random(8)),
            'total_price' => number_format($totalCents / 100, 2, '.', ''),
            'pay_amount' => '0.00',
            'payment_method' => 'debt',
            'change_amount' => '0.00',
            'discount_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        return [$customer, $transaction];
    }

    private function payload(int $amount, ?string $uuid = null): array
    {
        return [
            'idempotency_key' => $uuid ?? (string) Str::uuid(),
            'amount' => $amount,
            'payment_method' => 'cash',
        ];
    }

    public function test_owner_can_partially_pay_and_audit_an_invoice(): void
    {
        $owner = $this->owner();
        [$customer, $invoice] = $this->customerWithDebt(1000000);

        $this->postJson("/api/customers/{$customer->id}/pay-debt", array_merge(
            $this->payload(3500), ['transaction_id' => $invoice->id]
        ))->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('data.total_debt', '6500.00');

        $this->assertDatabaseHas('transactions', [
            'id' => $invoice->id, 'pay_amount' => '3500.00', 'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('customer_debt_payments', [
            'customer_id' => $customer->id, 'user_id' => $owner->id,
            'amount' => '3500.00', 'payment_method' => 'cash',
        ]);
        $this->assertDatabaseHas('customer_debt_payment_allocations', [
            'transaction_id' => $invoice->id, 'amount' => '3500.00',
        ]);
    }

    public function test_retry_same_uuid_does_not_reduce_balance_twice(): void
    {
        $this->owner();
        [$customer, $invoice] = $this->customerWithDebt();
        $payload = $this->payload(10000);

        $this->postJson("/api/customers/{$customer->id}/pay-debt", $payload)->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/pay-debt", $payload)
            ->assertOk()->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'total_debt' => '0.00']);
        $this->assertDatabaseHas('transactions', ['id' => $invoice->id, 'payment_status' => 'paid']);
        $this->assertDatabaseCount('customer_debt_payments', 1);
        $this->assertDatabaseCount('customer_debt_payment_allocations', 1);
    }

    public function test_reused_uuid_with_different_amount_is_conflict(): void
    {
        $this->owner();
        [$customer] = $this->customerWithDebt();
        $payload = $this->payload(1000);

        $this->postJson("/api/customers/{$customer->id}/pay-debt", $payload)->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/pay-debt", array_merge($payload, [
            'amount' => 2000,
        ]))->assertStatus(409);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'total_debt' => '9000.00']);
        $this->assertDatabaseCount('customer_debt_payments', 1);
    }

    public function test_cashier_cannot_pay_or_view_private_customer_history(): void
    {
        [$customer] = $this->customerWithDebt();
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/customers')->assertOk();
        $this->getJson("/api/customers/{$customer->id}")->assertForbidden();
        $this->postJson("/api/customers/{$customer->id}/pay-debt", $this->payload(1000))
            ->assertForbidden();
        $this->assertDatabaseCount('customer_debt_payments', 0);
    }

    public function test_cannot_allocate_payment_to_another_customer_invoice(): void
    {
        $this->owner();
        [$a] = $this->customerWithDebt();
        [$b, $invoiceB] = $this->customerWithDebt();

        $this->postJson("/api/customers/{$a->id}/pay-debt", array_merge(
            $this->payload(1000), ['transaction_id' => $invoiceB->id]
        ))->assertUnprocessable();

        $this->assertDatabaseHas('customers', ['id' => $a->id, 'total_debt' => '10000.00']);
        $this->assertDatabaseHas('customers', ['id' => $b->id, 'total_debt' => '10000.00']);
        $this->assertDatabaseCount('customer_debt_payments', 0);
    }

    public function test_fifo_payment_allocates_to_multiple_invoices_with_audit_entries(): void
    {
        $this->owner();
        [$customer, $first] = $this->customerWithDebt(500000);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $second = Transaction::create([
            'user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-SECOND',
            'total_price' => '3000.00',
            'pay_amount' => '0.00',
            'payment_method' => 'debt',
            'change_amount' => '0.00',
            'discount_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);
        $customer->update(['total_debt' => '8000.00']);

        $this->postJson("/api/customers/{$customer->id}/pay-debt", $this->payload(6000))
            ->assertCreated()->assertJsonPath('data.total_debt', '2000.00');

        $this->assertDatabaseHas('transactions', ['id' => $first->id, 'pay_amount' => '5000.00', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('transactions', ['id' => $second->id, 'pay_amount' => '1000.00', 'payment_status' => 'unpaid']);
        $this->assertDatabaseCount('customer_debt_payments', 1);
        $this->assertDatabaseCount('customer_debt_payment_allocations', 2);
    }

    public function test_payment_is_rejected_if_outstanding_does_not_match_customer_balance(): void
    {
        $this->owner();
        [$customer] = $this->customerWithDebt();
        $customer->update(['total_debt' => '12000.00']);

        $this->postJson("/api/customers/{$customer->id}/pay-debt", $this->payload(1000))
            ->assertUnprocessable();
        $this->assertDatabaseCount('customer_debt_payments', 0);
    }

    public function test_customer_with_history_cannot_be_deleted_even_after_full_payment(): void
    {
        $this->owner();
        [$customer] = $this->customerWithDebt();
        $this->postJson("/api/customers/{$customer->id}/pay-debt", $this->payload(10000))
            ->assertCreated();
        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_qris_payment_is_rejected_until_verified_settlement_is_implemented(): void
    {
        $this->owner();
        [$customer] = $this->customerWithDebt();
        $this->postJson("/api/customers/{$customer->id}/pay-debt", array_merge(
            $this->payload(1000), ['payment_method' => 'qris_gopay']
        ))->assertUnprocessable();
    }
}
