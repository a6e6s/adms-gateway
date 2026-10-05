<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('attlog_stamp', 100)->nullable();
            $table->timestamp('last_getrequest_at')->nullable()->index();
        });

        DB::table('devices')->select('id')->orderBy('id')->chunkById(100, function (Collection $devices): void {
            foreach ($devices as $device) {
                $stamp = DB::table('attendance_uploads')
                    ->where('device_id', $device->id)
                    ->whereNotNull('source_stamp')
                    ->orderByDesc('id')
                    ->value('source_stamp');

                if ($stamp !== null) {
                    DB::table('devices')->where('id', $device->id)->update(['attlog_stamp' => $stamp]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['last_getrequest_at']);
            $table->dropColumn(['attlog_stamp', 'last_getrequest_at']);
        });
    }
};
