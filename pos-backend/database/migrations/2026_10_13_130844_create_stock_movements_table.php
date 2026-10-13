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
    Schema::create('stock_movements', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Siapa yang ubah stok
        $table->enum('type', ['in', 'out']); // 'in' untuk kulakan, 'out' untuk rusak/retur
        $table->integer('quantity');
        $table->string('description')->nullable();
        // Letakkan sebelum/sesudah user_id
        $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
