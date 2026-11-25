<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->index()->after('booking_id');
            $table->timestamp('expires_at')->nullable()->index()->after('student_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['batch_id', 'expires_at']);
        });
    }
};
