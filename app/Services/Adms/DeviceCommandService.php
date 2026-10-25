<?php

namespace App\Services\Adms;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function requestDeviceInfo(Device $device, User $user): DeviceCommand
    {
        return $this->queueCommand($device, $user, 'get_device_info', 'INFO');
    }

    public function requestDeviceOptions(Device $device, User $user): DeviceCommand
    {
        return $this->queueCommand(
            $device,
            $user,
            'get_device_options',
            'GET OPTIONS ~SerialNumber,FirmVer,~DeviceName,LockCount,ReaderCount,AuxInCount,AuxOutCount,MachineType,~IsOnlyRFMachine,~MaxUserCount,~MaxAttLogCount,~MaxFingerCount,~MaxUserFingerCount,MThreshold,NetMask,GATEIPAddress,~ZKFPVersion,SimpleEventType,VerifyStyles,EventTypes,ComPwd',
        );
    }

    public function cancelPendingCommand(DeviceCommand $command, User $user): bool
    {
        return DB::transaction(function () use ($command, $user): bool {
            $lockedCommand = DeviceCommand::query()->lockForUpdate()->findOrFail($command->getKey());
            $lockedCommand->loadMissing('device');

            Gate::forUser($user)->authorize('update', $lockedCommand->device);

            if ($lockedCommand->status !== 'pending') {
                return false;
            }

            $lockedCommand->forceFill(['status' => 'cancelled'])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function queueGuideOperation(Device $device, User $user, string $operation, array $data = []): DeviceCommand
    {
        $wirePayload = match ($operation) {
            'reboot' => 'REBOOT',
            'unlock' => 'AC_UNLOCK',
            'clear_log' => 'CLEAR LOG',
            'clear_data' => 'CLEAR DATA',
            'clear_photo' => 'CLEAR PHOTO',
            'refresh_options' => 'REFRESH OPTIONS',
            'cancel_alarm' => 'AC_UNALARM',
            'set_device_time' => $this->buildDeviceTimeOptions($device, $data),
            'disable_time_sync' => $this->buildAutomaticTimeSyncOptions($device),
            'set_web_server' => $this->buildWebServerOptions($data),
            'set_network' => $this->buildNetworkOptions($data),
            'update_user' => $this->buildUserUpdate($data),
            'update_card' => $this->buildCardUpdate($data),
            'update_fingerprint', 'update_face' => $this->buildBiodataUpdate($operation, $data),
            'delete_user' => 'DATA DELETE USERINFO PIN='.$this->requiredIdentifier($data, 'pin'),
            'delete_fingerprint', 'delete_face' => $this->buildBiodataDelete($operation, $data),
            'register_fingerprint', 'register_face' => $this->buildBioRegistration($operation, $data),
            'register_card' => 'REG CARD Pin='.$this->requiredIdentifier($data, 'pin'),
            'query_users' => 'DATA QUERY USERINFO PIN='.$this->requiredIdentifier($data, 'pin'),
            default => throw new \InvalidArgumentException('Unsupported device command.'),
        };

        $sensitive = in_array($operation, ['update_user', 'update_card', 'update_fingerprint', 'update_face'], true);

        return $this->queueCommand($device, $user, 'guide_'.$operation, $wirePayload, sensitive: $sensitive);
    }

    public function recordQueryUpload(
        Device $device,
        string $table,
        string $payload,
        ?string $sourceIp,
        ?string $stamp,
        ?int $wireCommandId = null,
    ): bool {
        $commandType = match (mb_strtoupper($table)) {
            'USER', 'USERINFO', 'OPERLOG' => 'guide_query_users',
            'BIODATA' => 'guide_query_templates',
            'OPTIONS' => 'get_device_options',
            default => throw new \InvalidArgumentException('Unsupported device query upload table.'),
        };

        $commandQuery = $device->commands()
            ->where('type', $commandType)
            ->whereIn('status', ['offered', 'unknown', 'acknowledged'])
            ->where('expires_at', '>', now());

        if ($wireCommandId !== null) {
            $commandQuery->where('wire_command_id', $wireCommandId);
        }

        $command = $commandQuery->orderByDesc('requested_at')->first();

        if ($command === null) {
            return false;
        }

        $existingPayload = $command->encrypted_payload;
        $separator = is_string($existingPayload) && $existingPayload !== '' ? "\n" : '';
        $combinedPayload = ($existingPayload ?? '').$separator.$payload;
        if (strlen($combinedPayload) > 5_242_880) {
            throw new \InvalidArgumentException('The combined device query upload exceeds 5 MiB.');
        }

        $history = $command->result_history ?? [];
        $history[] = [
            'received_at' => now()->toISOString(),
            'source_ip' => $sourceIp,
            'table' => mb_strtoupper($table),
            'stamp' => $stamp,
            'bytes' => strlen($payload),
        ];

        $command->forceFill([
            'encrypted_payload' => $combinedPayload,
            'result_received_at' => $command->result_received_at ?? now(),
            'result_source_ip' => $command->result_source_ip ?? $sourceIp,
            'raw_result' => trim(($command->raw_result ?? '').sprintf(
                "\nEncrypted %s upload received: %d bytes%s.",
                mb_strtoupper($table),
                strlen($payload),
                $stamp !== null && $stamp !== '' ? " (stamp {$stamp})" : '',
            )),
            'result_history' => array_slice($history, -10),
            'status' => 'acknowledged',
        ])->save();

        return true;
    }

    public function syncClockToServer(Device $device, User $user): DeviceCommand
    {
        $deviceTimezone = new \DateTimeZone($device->timezone);
        $serverTime = CarbonImmutable::now('UTC');
        $deviceTime = $serverTime->setTimezone($deviceTimezone);
        $offsetMinutes = intdiv($deviceTime->getOffset(), 60);
        $offsetSign = $offsetMinutes < 0 ? '-' : '+';
        $absoluteOffsetMinutes = abs($offsetMinutes);
        $timeZoneOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($absoluteOffsetMinutes, 60), $absoluteOffsetMinutes % 60);
        $wirePayload = sprintf(
            'SET OPTIONS TimeZone=%s,DateTime=%s',
            $timeZoneOffset,
            $deviceTime->format('Y-m-d H:i:s'),
        );

        return $this->queueCommand($device, $user, 'sync_clock', $wirePayload);
    }

    private function queueAttendanceQuery(
        Device $device,
        User $user,
        CarbonImmutable $queryStart,
        CarbonImmutable $queryEnd,
        string $type,
    ): DeviceCommand {
        if ($queryStart->greaterThan($queryEnd)) {
            throw new \InvalidArgumentException('The attendance query start must be before its end.');
        }

        $queryStartValue = $queryStart->format('Y-m-d H:i:s');
        $queryEndValue = $queryEnd->format('Y-m-d H:i:s');
        $wirePayload = "DATA QUERY ATTLOG StartTime={$queryStartValue}\tEndTime={$queryEndValue}";

        return $this->queueCommand($device, $user, $type, $wirePayload, $queryStartValue, $queryEndValue);
    }

    private function queueCommand(
        Device $device,
        User $user,
        string $type,
        string $wirePayload,
        ?string $queryStartValue = null,
        ?string $queryEndValue = null,
        bool $sensitive = false,
    ): DeviceCommand {
        Gate::forUser($user)->authorize('update', $device);
        $device->loadMissing('company');
        if (! $device->is_enabled || ! $device->company?->is_active) {
            throw new \DomainException('Only enabled devices in active companies can receive commands.');
        }

        $command = DB::transaction(function () use ($device, $user, $queryStartValue, $queryEndValue, $wirePayload, $type, $sensitive): DeviceCommand {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $active = $lockedDevice->commands()
                ->whereIn('status', ['pending', 'offered', 'unknown', 'attendance_received'])
                ->where('expires_at', '>', now())
                ->exists();

            if ($active) {
                throw new \DomainException('This device already has an active command.');
            }

            $wireId = ((int) $lockedDevice->commands()->max('wire_command_id')) + 1;

            if ($wireId > 2_000_000_000) {
                throw new \DomainException('The device command ID range is exhausted.');
            }

            return $lockedDevice->commands()->create([
                'requested_by' => $user->id,
                'type' => $type,
                'wire_command_id' => $wireId,
                'wire_payload' => $sensitive ? 'Sensitive command payload is stored encrypted.' : $wirePayload,
                'encrypted_payload' => $sensitive ? $wirePayload : null,
                'query_start_time' => $queryStartValue,
                'query_end_time' => $queryEndValue,
                'status' => 'pending',
                'requested_at' => now(),
                'expires_at' => now()->addMinutes(max(1, (int) config('services.adms.device_command_ttl_minutes', 1440))),
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

            $wirePayload = $command->encrypted_payload ?? $command->wire_payload;

            return "C:{$command->wire_command_id}:{$wirePayload}\n";
        });
    }

    public function recordResult(Device $device, string $body, ?string $sourceIp): void
    {
        $result = [];
        foreach (preg_split('/[&\r\n]+/', trim($body)) ?: [] as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rtrim($key, ';');
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        if (isset($result['CMD']) && trim($result['CMD']) === '') {
            unset($result['CMD']);
        }

        $wireId = filter_var($result['ID'] ?? null, FILTER_VALIDATE_INT);
        if ($wireId === false || $wireId === null) {
            throw new \InvalidArgumentException('Command result has no valid ID.');
        }

        $command = $device->commands()->where('wire_command_id', $wireId)->first();
        if ($command === null) {
            throw new \InvalidArgumentException('Command result does not match this device.');
        }

        if (in_array($command->status, ['expired', 'cancelled'], true) && array_key_exists('Return', $result)) {
            return;
        }

        if (! in_array($command->status, ['pending', 'offered', 'unknown', 'acknowledged', 'attendance_received', 'failed'], true)) {
            throw new \InvalidArgumentException('Command result does not match an active or completed command.');
        }

        $acceptedResultTypes = match ($command->type) {
            'request_attendance', 'force_resend_attendance' => ['DATA', 'DATA QUERY ATTLOG'],
            'get_device_info' => ['INFO'],
            'get_device_options' => ['GET OPTIONS'],
            default => [],
        };

        $isValidGuideResult = str_starts_with($command->type, 'guide_')
            && array_key_exists('Return', $result)
            && (! isset($result['CMD']) || in_array($result['CMD'], $this->acceptedGuideResultTypes($command->type), true));

        $isValidDeviceOptionsResult = $command->type === 'get_device_options'
            && array_key_exists('Return', $result)
            && (! isset($result['CMD']) || in_array($result['CMD'], $acceptedResultTypes, true));

        $isValidClockSyncResult = $command->type === 'sync_clock'
            && (! isset($result['CMD']) || in_array($result['CMD'], ['OPTIONS', 'SET OPTIONS'], true));

        $isValidResultWithoutCommandName = array_key_exists('Return', $result) && ! isset($result['CMD']);
        $isRejectedResult = isset($result['Return'])
            && $result['Return'] !== ''
            && $result['Return'] !== '0';

        if (! $isValidClockSyncResult && ! $isValidDeviceOptionsResult && ! $isValidGuideResult && ! $isValidResultWithoutCommandName && ! $isRejectedResult && ! in_array($result['CMD'] ?? '', $acceptedResultTypes, true)) {
            throw new \InvalidArgumentException('Command result type does not match the queued command.');
        }

        $history = $command->result_history ?? [];
        $isDeviceOptionsCommand = $command->type === 'get_device_options';
        $history[] = $isDeviceOptionsCommand
            ? [
                'received_at' => now()->toISOString(),
                'source_ip' => $sourceIp,
                'body_length' => strlen($body),
                'stored_encrypted' => true,
            ]
            : ['received_at' => now()->toISOString(), 'source_ip' => $sourceIp, 'body' => $body];
        $hasExistingResult = $command->result_received_at !== null;
        $isSuccessfulResult = ($result['Return'] ?? null) === '0';
        $status = $hasExistingResult || $command->status === 'attendance_received'
            ? $command->status
            : ($isSuccessfulResult ? 'acknowledged' : 'failed');
        $encryptedPayload = $command->encrypted_payload;
        $rawResult = $command->raw_result ?? $body;

        if ($isDeviceOptionsCommand) {
            $separator = is_string($encryptedPayload) && $encryptedPayload !== '' ? "\n" : '';
            $encryptedPayload = ($encryptedPayload ?? '').$separator.$body;
            $rawResult = $command->raw_result ?? sprintf(
                "ID=%d&Return=%s&CMD=GET OPTIONS\nDevice options response stored encrypted (%d bytes).",
                $wireId,
                $result['Return'] ?? '',
                strlen($body),
            );
        }

        $command->forceFill([
            'status' => $status,
            'result_received_at' => $command->result_received_at ?? now(),
            'result_source_ip' => $command->result_source_ip ?? $sourceIp,
            'raw_result' => $rawResult,
            'encrypted_payload' => $encryptedPayload,
            'result_history' => array_slice($history, -10),
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function buildWebServerOptions(array $data): string
    {
        $serverAddress = $this->requiredServerAddress($data);
        $port = filter_var($data['web_server_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new \InvalidArgumentException('The web server port is invalid.');
        }

        return sprintf('SET OPTIONS WebServerIP=%s,WebServerPort=%d', $serverAddress, $port);
    }

    /** @param array<string, mixed> $data */
    private function requiredServerAddress(array $data): string
    {
        $address = $data['web_server_ip'] ?? null;
        if (! is_string($address) || trim($address) === '') {
            throw new \InvalidArgumentException('The web server address is required.');
        }

        $address = trim($address);
        $ipAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ipAddress !== false) {
            return $ipAddress;
        }

        if (str_contains($address, '://')) {
            $parts = parse_url($address);
            if (! is_array($parts)
                || ! in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || ! isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['port'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new \InvalidArgumentException('The web server URL is invalid. Enter the port separately.');
            }

            $address = $parts['host'];
        } elseif (str_contains($address, '/') || str_contains($address, ':')) {
            throw new \InvalidArgumentException('The web server domain name is invalid.');
        }

        if (preg_match('/^\d+(?:\.\d+){3}$/', $address) === 1
            || filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('The web server domain name is invalid.');
        }

        return $address;
    }

    private function buildAutomaticTimeSyncOptions(Device $device): string
    {
        $deviceTime = CarbonImmutable::now('UTC')->setTimezone($device->timezone);
        $offsetMinutes = intdiv($deviceTime->getOffset(), 60);
        $offsetSign = $offsetMinutes < 0 ? '-' : '+';
        $absoluteOffsetMinutes = abs($offsetMinutes);
        $timeZoneOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($absoluteOffsetMinutes, 60), $absoluteOffsetMinutes % 60);

        return sprintf(
            'SET OPTIONS TimeZone=%s,DateTime=%s,SyncTime=0',
            $timeZoneOffset,
            $deviceTime->format('Y-m-d H:i:s'),
        );
    }

    /** @param array<string, mixed> $data */
    private function buildDeviceTimeOptions(Device $device, array $data): string
    {
        $value = $data['device_time'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('The device time is required.');
        }

        try {
            $deviceTime = CarbonImmutable::parse($value, config('app.timezone'))->setTimezone($device->timezone);
        } catch (Throwable) {
            throw new \InvalidArgumentException('The device time is invalid.');
        }

        $offsetMinutes = intdiv($deviceTime->getOffset(), 60);
        $offsetSign = $offsetMinutes < 0 ? '-' : '+';
        $absoluteOffsetMinutes = abs($offsetMinutes);
        $timeZoneOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($absoluteOffsetMinutes, 60), $absoluteOffsetMinutes % 60);

        return sprintf(
            'SET OPTIONS TimeZone=%s,DateTime=%s',
            $timeZoneOffset,
            $deviceTime->format('Y-m-d H:i:s'),
        );
    }

    /** @param array<string, mixed> $data */
    private function buildNetworkOptions(array $data): string
    {
        $ipAddress = $this->requiredIpAddress($data, 'ip_address');
        $netmask = $this->requiredIpAddress($data, 'netmask');
        $maskNumber = ip2long($netmask);
        if ($maskNumber === false) {
            throw new \InvalidArgumentException('The network mask is invalid.');
        }

        $inverseMask = ~$maskNumber & 0xFFFFFFFF;
        if ($maskNumber === 0 || ($inverseMask & ($inverseMask + 1)) !== 0) {
            throw new \InvalidArgumentException('The network mask is invalid.');
        }

        $options = "DHCP=0,IPAddress={$ipAddress}";
        $gateway = $data['gateway'] ?? null;
        if (is_string($gateway) && trim($gateway) !== '') {
            $gateway = $this->requiredIpAddress(['gateway' => $gateway], 'gateway');
            $options .= ",GATEIPAddress={$gateway}";
        }

        return "SET OPTIONS {$options},NetMask={$netmask}";
    }

    /** @param array<string, mixed> $data */
    private function buildUserUpdate(array $data): string
    {
        $pin = $this->requiredIdentifier($data, 'pin');
        $name = $this->requiredCommandValue($data, 'name', 48);
        $privilege = filter_var($data['privilege'] ?? null, FILTER_VALIDATE_INT);
        if (! in_array($privilege, [0, 2, 6, 10, 14], true)) {
            throw new \InvalidArgumentException('The user privilege is invalid.');
        }

        $values = ["PIN={$pin}", "Name={$name}", 'Card='];
        $values[] = "Pri={$privilege}";
        $password = $data['password'] ?? '';
        $values[] = 'Passwd='.($password !== '' ? $this->commandValue($password, 32, 'password') : '');
        $values[] = 'Grp=1';

        return 'DATA USER '.implode("\t", $values);
    }

    /** @param array<string, mixed> $data */
    private function buildCardUpdate(array $data): string
    {
        $pin = $this->requiredIdentifier($data, 'pin');
        $cardNumber = $this->requiredIdentifier($data, 'card_number');

        return "DATA UPDATE USERINFO PIN={$pin}\tCard={$cardNumber}";
    }

    /** @param array<string, mixed> $data */
    private function buildBiodataUpdate(string $operation, array $data): string
    {
        $pin = $this->requiredIdentifier($data, 'pin');
        $type = $operation === 'update_fingerprint' ? 1 : 9;
        $template = $data['template'] ?? null;

        if (! is_string($template) || strlen($template) > 262_144 || base64_decode($template, true) === false) {
            throw new \InvalidArgumentException('The biometric template must be valid Base64 data under 256 KiB.');
        }

        if ($type === 9) {
            return "DATA UPDATE biodata Pin={$pin},Type={$type},Tmp={$template}";
        }

        $version = $this->commandValue($data['version'] ?? '10.0', 12, 'version');

        return "DATA UPDATE biodata Pin={$pin},Type={$type},Version={$version},Tmp={$template}";
    }

    /** @param array<string, mixed> $data */
    private function buildBiodataDelete(string $operation, array $data): string
    {
        $pin = $this->requiredIdentifier($data, 'pin');
        $type = $operation === 'delete_fingerprint' ? 1 : 9;

        return "DATA DELETE biodata Pin={$pin},Type={$type}";
    }

    /** @param array<string, mixed> $data */
    private function buildBioRegistration(string $operation, array $data): string
    {
        $pin = $this->requiredIdentifier($data, 'pin');
        $type = $operation === 'register_fingerprint' ? 1 : 9;

        return "REG BIO Pin={$pin},Type={$type}";
    }

    /** @param array<string, mixed> $data */
    private function requiredIdentifier(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException("The {$key} value is required.");
        }

        $value = (string) $value;
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value) !== 1) {
            throw new \InvalidArgumentException("The {$key} value is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredIpAddress(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException("The {$key} value must be an IPv4 address.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredCommandValue(array $data, string $key, int $maxLength): string
    {
        return $this->commandValue($data[$key] ?? null, $maxLength, $key);
    }

    private function commandValue(mixed $value, int $maxLength, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength || preg_match('/[,=\r\n\x00-\x1F]/', $value) === 1) {
            throw new \InvalidArgumentException("The {$field} value is invalid.");
        }

        return trim($value);
    }

    /** @return list<string> */
    private function acceptedGuideResultTypes(string $commandType): array
    {
        return match ($commandType) {
            'guide_reboot' => ['REBOOT'],
            'guide_unlock' => ['AC_UNLOCK'],
            'guide_clear_log' => ['CLEAR LOG'],
            'guide_clear_data' => ['CLEAR DATA'],
            'guide_clear_photo' => ['CLEAR PHOTO'],
            'guide_refresh_options' => ['REFRESH OPTIONS'],
            'guide_cancel_alarm' => ['AC_UNALARM'],
            'guide_set_device_time', 'guide_disable_time_sync', 'guide_set_web_server', 'guide_set_network' => ['OPTIONS', 'SET OPTIONS'],
            'guide_update_user', 'guide_update_card', 'guide_update_fingerprint', 'guide_update_face',
            'guide_delete_user', 'guide_delete_fingerprint', 'guide_delete_face' => ['DATA'],
            'guide_register_fingerprint', 'guide_register_face' => ['REG BIO'],
            'guide_register_card' => ['REG CARD'],
            'guide_query_users', 'guide_query_templates' => ['DATA'],
            default => [],
        };
    }
}
