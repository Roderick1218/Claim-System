<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 加入审批流程：draft → submitted → approved / rejected，被 reject 的可以改了再 submit。
 * 原本只有 "recorded" 状态，旧数据一律转成 submitted，当作等待审批。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('recorded_at', 'submitted_at');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 1000)->nullable()->after('reviewed_at'); // reject 原因或 approve 备注
        });

        DB::table('claims')->where('status', 'recorded')->update(['status' => 'submitted']);
    }

    public function down(): void
    {
        DB::table('claims')->whereIn('status', ['submitted', 'approved', 'rejected'])->update(['status' => 'recorded']);

        Schema::table('claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'review_note']);
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('submitted_at', 'recorded_at');
        });
    }
};
