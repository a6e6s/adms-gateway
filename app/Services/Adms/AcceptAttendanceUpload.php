<?php

namespace App\Services\Adms;

use App\Jobs\ProcessAttendanceUpload;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AcceptAttendanceUpload
{
    public function accept(Device $device, string $payload, ?string $stamp, ?string $sourceIp): void
    {
        $upload = DB::transaction(function () use ($device, $payload, $stamp, $sourceIp) {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);

            $upload = $lockedDevice->attendanceUploads()->create([
                'company_id' => $lockedDevice->company_id,
                'raw_payload' => $payload,
                'payload_sha256' => hash('sha256', $payload),
                'byte_count' => strlen($payload),
                'source_stamp' => $stamp,
                'received_at' => now(),
                'source_ip' => $sourceIp,
                'device_timezone' => $lockedDevice->timezone,
                'parser_version' => $lockedDevice->protocol_profile,
                'status' => 'pending',
            ]);

            if ($stamp !== null) {
                $lockedDevice->forceFill(['attlog_stamp' => $stamp])->save();
            }

            return $upload;
        });

        try {
            ProcessAttendanceUpload::dispatch($upload->id)->afterCommit();
            $upload->forceFill(['last_dispatched_at' => now()])->save();
        } catch (Throwable $exception) {
            Log::warning('Attendance upload saved but dispatch failed.', [
                'upload_id' => $upload->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
