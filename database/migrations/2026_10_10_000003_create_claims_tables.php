<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per month; locked with SELECT ... FOR UPDATE when issuing a claim number.
        Schema::create('claim_sequences', function (Blueprint $table) {
            $table->string('period', 6)->primary(); // e.g. 202610
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number', 30)->unique();
            // restrictOnDelete: history must never disappear because a user/category/project was deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Nullable only so an incomplete draft can exist; required before a claim is recorded.
            $table->foreignId('category_id')->nullable()->constrained('claim_categories')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('merchant_name')->nullable();
            $table->date('expense_date')->nullable();
            $table->time('expense_time')->nullable();
            $table->string('location')->nullable();
            $table->string('business_purpose', 500)->nullable();
            $table->char('currency', 3)->default('MYR');
            // Calculated by the backend from claim_items only.
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            // Total printed on the receipt as confirmed by the user; compared against total_amount.
            $table->decimal('receipt_total_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('expense_date');
            $table->index('status');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('file_hash', 64)->nullable()->index();
            $table->longText('ocr_text')->nullable();
            $table->string('ocr_status', 20)->default('pending');
            $table->string('ocr_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('claim_items');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('claim_sequences');
    }
};
