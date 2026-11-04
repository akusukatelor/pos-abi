<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_restock_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('sales_period_days')->default(30);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedInteger('safety_stock')->nullable();
            $table->unsignedSmallInteger('review_interval_days')->nullable();
            $table->unsignedInteger('manual_minimum_stock')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_restock_settings');
    }
};
