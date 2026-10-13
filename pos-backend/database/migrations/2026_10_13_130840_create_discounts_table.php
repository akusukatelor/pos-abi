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
    Schema::create('discounts', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // cth: "Promo Merdeka"
        $table->enum('type', ['percentage', 'fixed_amount']); // Persen atau nominal tetap
        $table->decimal('value', 12, 2); // Nilai diskonnya (misal: 10 atau 5000)
        $table->date('start_date');
        $table->date('end_date');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
