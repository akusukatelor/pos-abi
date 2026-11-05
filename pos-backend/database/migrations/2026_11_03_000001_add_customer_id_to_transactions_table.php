<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transactions', 'customer_id')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->foreignId('customer_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('customers')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasCustomerForeignKey = collect(Schema::getForeignKeys('transactions'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['customer_id']);

        if ($hasCustomerForeignKey) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('customer_id');
            });
        }
    }
};
