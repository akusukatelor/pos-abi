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
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        // Relasi ke tabel categories
        $table->foreignId('category_id')->constrained('categories')->onDelete('cascade');
        $table->string('sku_code')->unique();
        $table->string('name');
        $table->decimal('buy_price', 12, 2)->default(0);
        $table->decimal('sell_price', 12, 2)->default(0);
        $table->integer('stock')->default(0);
        // Letakkan di bawah category_id
        $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('set null');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
