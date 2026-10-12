<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_categories', function (Blueprint $table) {
            // Words that identify this category on a receipt (one per line), used to auto-select the category.
            $table->text('keywords')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('claim_categories', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
