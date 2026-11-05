<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('role');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('stock');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('payment_method', 20)->default('cash')->after('pay_amount');
            $table->decimal('change_amount', 12, 2)->default(0)->after('payment_method');
            $table->string('idempotency_key', 36)->nullable()->unique();
            $table->char('idempotency_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'payment_method',
                'change_amount',
                'idempotency_key',
                'idempotency_hash',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
