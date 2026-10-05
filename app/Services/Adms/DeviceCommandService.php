<?php

namespace App\Services\Adms;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceCommandService
{
    public function requestAttendance(Device $device, User $user): DeviceCommand
    {
        $device->loadMissing('company');
        if (! $device->is_enabled || ! $device->company?->is_active) {
            throw new \DomainException('Only enabled devices in active companies can receive commands.');
        }

        return DB::transaction(function () use ($device, $user): DeviceCommand {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $active = $lockedDevice->commands()
                ->whereIn('status', ['pending', 'offered', 'unknown'])
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
                'type' => 'request_attendance',
                'wire_command_id' => $wireId,
                'wire_payload' => 'DATA QUERY ATTLOG',
                'status' => 'pending',
                'requested_at' => now(),
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    public function offerNext(Device $device): string
    {
        return DB::transaction(function () use ($device): string {
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

        if (! in_array($command->status, ['offered', 'unknown', 'acknowledged', 'failed'], true)) {
            throw new \InvalidArgumentException('Command result arrived before the command was offered.');
        }

        $history = $command->result_history ?? [];
        $history[] = ['received_at' => now()->toISOString(), 'source_ip' => $sourceIp, 'body' => $body];
        $hasExistingResult = $command->result_received_at !== null;
        $status = $hasExistingResult
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
