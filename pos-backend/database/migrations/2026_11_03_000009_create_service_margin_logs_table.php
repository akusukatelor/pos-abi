<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_margin_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_transaction_id')->constrained('service_transactions')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->decimal('old_margin_amount', 12, 2)->nullable();
            $table->decimal('new_margin_amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['service_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_margin_logs');
    }
};
