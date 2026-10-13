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
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Kasir yang bertugas
        $table->string('invoice_number')->unique();
        $table->decimal('total_price', 12, 2);
        $table->decimal('pay_amount', 12, 2);
        $table->foreignId('discount_id')->nullable()->constrained('discounts')->onDelete('set null');
        $table->decimal('discount_amount', 12, 2)->default(0); // Nominal yang terpotong
        $table->enum('payment_status', ['paid', 'unpaid'])->default('paid'); 
        $table->date('due_date')->nullable()->comment('Tanggal jatuh tempo bayar hutang');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
