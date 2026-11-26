<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('online_orders', 'payment_reference')) {
            Schema::table('online_orders', function (Blueprint $table): void {
                $table->string('payment_reference', 100)->nullable()->after('payment_method');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('online_orders', 'payment_reference')) {
            Schema::table('online_orders', function (Blueprint $table): void {
                $table->dropColumn('payment_reference');
            });
        }
    }
};