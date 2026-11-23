<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            // P0 hanya menerima pelunasan tunai; QRIS harus memiliki alur verifikasi sendiri.
            $table->string('payment_method', 20)->default('cash');
            $table->uuid('idempotency_key')->unique();
            $table->char('idempotency_hash', 64);
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('customer_debt_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_debt_payment_id');
            // Explicit FK name: nama otomatis Laravel melewati limit identifier MySQL (64 karakter).
            $table->foreign('customer_debt_payment_id', 'debt_alloc_payment_fk')
                ->references('id')->on('customer_debt_payments')->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['customer_debt_payment_id', 'transaction_id'], 'debt_payment_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debt_payment_allocations');
        Schema::dropIfExists('customer_debt_payments');
    }
};
