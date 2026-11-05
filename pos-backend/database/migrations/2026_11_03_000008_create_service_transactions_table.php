<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->timestamp('service_at');
            // Nominal pokok layanan bukan pendapatan/margin toko.
            $table->decimal('nominal_amount', 12, 2)->nullable();
            // NULL berarti margin belum diketahui; 0 berarti margin diketahui nol.
            $table->decimal('margin_amount', 12, 2)->nullable();
            $table->foreignId('margin_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('margin_verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('completed');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->char('idempotency_key', 36)->unique();
            $table->char('idempotency_hash', 64);
            $table->timestamps();

            $table->index(['status', 'service_at']);
            $table->index(['service_type_id', 'service_at']);
            $table->index(['margin_verified_by', 'service_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_transactions');
    }
};
