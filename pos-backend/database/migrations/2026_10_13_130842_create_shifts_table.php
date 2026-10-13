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
    Schema::create('shifts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Kasir yang buka shift
        $table->timestamp('opened_at'); // Waktu mulai kerja
        $table->timestamp('closed_at')->nullable(); // Waktu selesai kerja
        $table->decimal('opening_balance', 12, 2)->default(0); // Modal awal di laci
        $table->decimal('closing_balance_system', 12, 2)->nullable(); // Total uang menurut aplikasi
        $table->decimal('closing_balance_actual', 12, 2)->nullable(); // Uang fisik yang dihitung kasir
        $table->enum('status', ['open', 'closed'])->default('open');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
