<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_categories', function (Blueprint $table) {
            // 一行一个关键词，收据上出现就自动选这个 category
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
