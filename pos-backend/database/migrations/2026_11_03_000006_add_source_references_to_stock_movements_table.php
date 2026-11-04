<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('purchase_receipt_item_id')
                ->nullable()
                ->unique()
                ->constrained('purchase_receipt_items')
                ->restrictOnDelete();
            $table->foreignId('stock_opname_item_id')
                ->nullable()
                ->unique()
                ->constrained('stock_opname_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['purchase_receipt_item_id']);
            $table->dropUnique(['purchase_receipt_item_id']);
            $table->dropForeign(['stock_opname_item_id']);
            $table->dropUnique(['stock_opname_item_id']);
            $table->dropColumn(['purchase_receipt_item_id', 'stock_opname_item_id']);
        });
    }
};
