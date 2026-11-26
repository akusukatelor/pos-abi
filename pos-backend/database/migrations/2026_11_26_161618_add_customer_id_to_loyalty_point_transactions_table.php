<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loyalty_point_transactions', function (Blueprint $table): void {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('customer_account_id')->nullable()->change();
        });

        // Sinkronkan data histori poin lama agar terisi customer_id-nya
        DB::statement('
            UPDATE loyalty_point_transactions lpt
            INNER JOIN customer_accounts ca ON ca.id = lpt.customer_account_id
            SET lpt.customer_id = ca.customer_id
            WHERE lpt.customer_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('loyalty_point_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};