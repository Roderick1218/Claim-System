<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflow: draft → submitted → approved / rejected (rejected can be edited and resubmitted).
 * Existing "recorded" claims become "submitted" (waiting for approval).
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
            $table->string('review_note', 1000)->nullable()->after('reviewed_at'); // rejection reason / approval remark
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
