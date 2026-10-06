<?php

namespace App\Services\Adms;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeviceCommandService
{
    public function __construct(private DeviceCommandWaker $waker) {}

    public function requestAttendance(Device $device, User $user, string $startTime, string $endTime): DeviceCommand
    {
        $deviceTimezone = new \DateTimeZone($device->timezone);
        $queryStart = CarbonImmutable::parse($startTime, config('app.timezone'))->setTimezone($deviceTimezone);
        $queryEnd = CarbonImmutable::parse($endTime, config('app.timezone'))->setTimezone($deviceTimezone);

        return $this->queueAttendanceQuery($device, $user, $queryStart, $queryEnd, 'request_attendance');
    }

    public function forceResendAllAttendance(Device $device, User $user, string $startTime, string $endTime): DeviceCommand
    {
        $deviceTimezone = new \DateTimeZone($device->timezone);
        $queryStart = CarbonImmutable::parse($startTime, config('app.timezone'))->setTimezone($deviceTimezone);
        $queryEnd = CarbonImmutable::parse($endTime, config('app.timezone'))->setTimezone($deviceTimezone);

        return $this->queueAttendanceQuery($device, $user, $queryStart, $queryEnd, 'force_resend_attendance');
    }

    private function queueAttendanceQuery(
        Device $device,
        User $user,
        CarbonImmutable $queryStart,
        CarbonImmutable $queryEnd,
        string $type,
    ): DeviceCommand {
        $device->loadMissing('company');
        if (! $device->is_enabled || ! $device->company?->is_active) {
            throw new \DomainException('Only enabled devices in active companies can receive commands.');
        }

        if ($queryStart->greaterThan($queryEnd)) {
            throw new \InvalidArgumentException('The attendance query start must be before its end.');
        }

        $queryStartValue = $queryStart->format('Y-m-d H:i:s');
        $queryEndValue = $queryEnd->format('Y-m-d H:i:s');
        $wirePayload = "DATA QUERY ATTLOG StartTime={$queryStartValue}\tEndTime={$queryEndValue}";

        $command = DB::transaction(function () use ($device, $user, $queryStartValue, $queryEndValue, $wirePayload, $type): DeviceCommand {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $active = $lockedDevice->commands()
                ->whereIn('status', ['pending', 'offered', 'unknown', 'attendance_received'])
                ->where('expires_at', '>', now())
                ->exists();

            if ($active) {
                throw new \DomainException('This device already has an active attendance request.');
            }

            $wireId = ((int) $lockedDevice->commands()->max('wire_command_id')) + 1;

            if ($wireId > 2_000_000_000) {
                throw new \DomainException('The device command ID range is exhausted.');
            }

            return $lockedDevice->commands()->create([
                'requested_by' => $user->id,
                'type' => $type,
                'wire_command_id' => $wireId,
                'wire_payload' => $wirePayload,
                'query_start_time' => $queryStartValue,
                'query_end_time' => $queryEndValue,
                'status' => 'pending',
                'requested_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
        });

        try {
            $this->waker->wake($device);
            $command->forceFill(['wake_sent_at' => now(), 'wake_error' => null])->save();
        } catch (Throwable $exception) {
            $command->forceFill(['wake_error' => mb_substr($exception->getMessage(), 0, 512)])->save();
            Log::warning('Device command queued but the UDP wake-up packet could not be sent.', [
                'command_id' => $command->id,
                'device_id' => $device->id,
                'exception' => $exception::class,
            ]);
        }

        $command->refresh();

        return $command;
    }

    public function offerNext(Device $device): string
    {
        return DB::transaction(function () use ($device): string {
            $device->commands()->where('status', 'pending')->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);

            $device->commands()->where('status', 'offered')->where('expires_at', '<=', now())
                ->update(['status' => 'unknown', 'updated_at' => now()]);

            $command = $device->commands()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($command === null) {
                $device->commands()->where('status', 'pending')->where('expires_at', '<=', now())
                    ->update(['status' => 'expired', 'updated_at' => now()]);

                return 'OK';
            }

            $command->forceFill(['status' => 'offered', 'offered_at' => now()])->save();

            return "C:{$command->wire_command_id}:{$command->wire_payload}\n";
        });
    }

    public function recordResult(Device $device, string $body, ?string $sourceIp): void
    {
        $result = [];
        foreach (preg_split('/[&\r\n]+/', trim($body)) ?: [] as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        $wireId = filter_var($result['ID'] ?? null, FILTER_VALIDATE_INT);
        if ($wireId === false || $wireId === null) {
            throw new \InvalidArgumentException('Command result has no valid ID.');
        }

        if (! in_array($result['CMD'] ?? '', ['DATA', 'DATA QUERY ATTLOG'], true)) {
            throw new \InvalidArgumentException('Command result type is not an attendance query result.');
        }

        $command = $device->commands()->where('wire_command_id', $wireId)->first();
        if ($command === null) {
            throw new \InvalidArgumentException('Command result does not match this device.');
        }

        if (! in_array($command->status, ['pending', 'offered', 'unknown', 'acknowledged', 'attendance_received', 'failed'], true)) {
            throw new \InvalidArgumentException('Command result does not match an active or completed command.');
        }

        $history = $command->result_history ?? [];
        $history[] = ['received_at' => now()->toISOString(), 'source_ip' => $sourceIp, 'body' => $body];
        $hasExistingResult = $command->result_received_at !== null;
        $status = $hasExistingResult || $command->status === 'attendance_received'
            ? $command->status
            : (($result['Return'] ?? null) === '0' ? 'acknowledged' : 'failed');

        $command->forceFill([
            'status' => $status,
            'result_received_at' => $command->result_received_at ?? now(),
            'result_source_ip' => $command->result_source_ip ?? $sourceIp,
            'raw_result' => $command->raw_result ?? $body,
            'result_history' => array_slice($history, -10),
        ])->save();
    }
}
