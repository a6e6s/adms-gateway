<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAttendanceUpload;
use App\Models\AttendanceUpload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:recover-attendance-uploads')]
#[Description('Redispatch pending and expired attendance uploads.')]
class RecoverAttendanceUploads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dispatched = 0;

        AttendanceUpload::query()->where(function ($query): void {
            $query->where(function ($query): void {
                $query->where('status', 'pending')
                    ->where(function ($query): void {
                        $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<', now()->subMinute());
                    });
            })->orWhere(function ($query): void {
                $query->where('status', 'processing')->where('processing_lease_expires_at', '<', now());
            });
        })->orderBy('id')->chunkById(100, function ($uploads) use (&$dispatched): void {
            foreach ($uploads as $upload) {
                try {
                    ProcessAttendanceUpload::dispatch($upload->id)->afterCommit();
                    $upload->forceFill(['last_dispatched_at' => now()])->save();
                    $dispatched++;
                } catch (\Throwable $exception) {
                    Log::warning('Could not redispatch attendance upload.', [
                        'upload_id' => $upload->id,
                        'exception' => $exception::class,
                    ]);
                }
            }
        });

        $this->info("Redispatched {$dispatched} attendance upload(s).");

        return self::SUCCESS;
    }
}
