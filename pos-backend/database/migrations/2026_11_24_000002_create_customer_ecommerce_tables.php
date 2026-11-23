<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Akun publik DIPISAH dari users (Owner/Kasir) dan dihubungkan ke master pelanggan.
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->restrictOnDelete();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('online_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('order_number', 50)->unique();
            $table->string('status', 20)->default('pending'); // pending/completed/cancelled
            $table->string('payment_method', 30)->default('cash_on_pickup');
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('total_amount', 12, 2);
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->char('idempotency_key', 36)->unique();
            $table->char('idempotency_hash', 64);
            $table->timestamps();
            $table->index(['customer_account_id', 'created_at'], 'orders_account_created_idx');
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
        });

        Schema::create('online_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('online_order_id')->constrained('online_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name'); // Nama saat pemesanan, tidak mengikuti perubahan master.
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->unique(['online_order_id', 'product_id'], 'order_item_unique_product');
        });

        // Fondasi loyalty saja: belum ada earn/redeem sampai kebijakan disetujui Owner.
        Schema::create('loyalty_point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('online_order_id')->nullable()->constrained('online_orders')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('points_delta');
            $table->string('description', 255);
            $table->string('reference_key', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');
        Schema::dropIfExists('online_order_items');
        Schema::dropIfExists('online_orders');
        Schema::dropIfExists('customer_accounts');
    }
};
