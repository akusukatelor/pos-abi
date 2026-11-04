<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('qris_verified_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('qris_verified_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['qris_verified_by']);
            $table->dropColumn([
                'qris_verified_by',
                'qris_verified_at',
                'payment_reference',
            ]);
        });
    }
};
