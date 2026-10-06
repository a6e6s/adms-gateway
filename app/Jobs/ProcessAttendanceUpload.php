<?php

namespace App\Jobs;

use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use App\Models\DeviceCommand;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use App\Services\Adms\AttendanceIdentity;
use App\Services\Adms\AttendancePayloadParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessAttendanceUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [5, 15, 30, 60];

    public function __construct(public int $uploadId) {}

    /**
     * Execute the job.
     */
    public function handle(AttendancePayloadParser $parser, AttendanceIdentity $identity): void
    {
        $token = (string) Str::uuid();
        $claimed = AttendanceUpload::query()->whereKey($this->uploadId)
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'processing')->where('processing_lease_expires_at', '<=', now());
                    });
            })
            ->update([
                'status' => 'processing',
                'processing_token' => $token,
                'processing_lease_expires_at' => now()->addMinutes(2),
                'processing_attempts' => DB::raw('processing_attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $upload = AttendanceUpload::query()->with('device')->findOrFail($this->uploadId);
            $queryCommands = DeviceCommand::query()
                ->where('device_id', $upload->device_id)
                ->whereIn('type', ['request_attendance', 'force_resend_attendance'])
                ->whereNotNull('offered_at')
                ->whereNotNull('query_start_time')
                ->whereNotNull('query_end_time')
                ->where('offered_at', '<=', $upload->received_at)
                ->whereIn('status', ['offered', 'unknown', 'acknowledged', 'attendance_received'])
                ->orderByDesc('offered_at')
                ->get();
            $deviceEmployees = DeviceEmployee::query()->where('device_id', $upload->device_id)
                ->get()->keyBy('pin');
            $chunk = [];
            $errors = $upload->errors ?? [];
            $matchedCommandId = $upload->device_command_id;

            foreach ($parser->rows($upload->raw_payload, $upload->device_timezone) as $row) {
                if ($row['line'] <= $upload->checkpoint_line) {
                    continue;
                }

                if ($row['error'] === null && $matchedCommandId === null) {
                    foreach ($queryCommands as $queryCommand) {
                        if ($row['fields'][1] >= $queryCommand->query_start_time
                            && $row['fields'][1] <= $queryCommand->query_end_time) {
                            $matchedCommandId = $queryCommand->id;
                            AttendanceUpload::query()->whereKey($upload->id)
                                ->where('processing_token', $token)
                                ->update(['device_command_id' => $matchedCommandId]);

                            break;
                        }
                    }
                }

                $chunk[] = $row;
                if (count($chunk) >= 200) {
                    $this->processChunk($upload, $token, $chunk, $identity, $deviceEmployees, $errors);
                    $upload->refresh();
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->processChunk($upload, $token, $chunk, $identity, $deviceEmployees, $errors);
                $upload->refresh();
            }

            AttendanceUpload::query()->whereKey($upload->id)->where('processing_token', $token)->update([
                'device_command_id' => $matchedCommandId,
                'status' => $upload->rejected_rows > 0 ? 'processed_with_errors' : 'processed',
                'processed_at' => now(),
                'processing_token' => null,
                'processing_lease_expires_at' => null,
                'errors' => json_encode($errors, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            if ($matchedCommandId !== null) {
                DeviceCommand::query()->whereKey($matchedCommandId)
                    ->whereIn('status', ['offered', 'unknown', 'acknowledged', 'attendance_received'])
                    ->update(['status' => 'attendance_received', 'updated_at' => now()]);
            }
        } catch (Throwable $exception) {
            AttendanceUpload::query()->whereKey($this->uploadId)->where('processing_token', $token)->update([
                'status' => 'pending',
                'processing_token' => null,
                'processing_lease_expires_at' => null,
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  list<array{line: int, fields: list<string>, error: ?string}>  $rows
     * @param  Collection<string, DeviceEmployee>  $deviceEmployees
     * @param  list<array{line: int, error: string}>  $errors
     */
    private function processChunk(AttendanceUpload $upload, string $token, array $rows, AttendanceIdentity $identity, $deviceEmployees, array &$errors): void
    {
        DB::transaction(function () use ($upload, $token, $rows, $identity, $deviceEmployees, &$errors): void {
            $lockedUpload = AttendanceUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            if ($lockedUpload->processing_token !== $token) {
                return;
            }

            $inserted = 0;
            $duplicates = 0;
            $rejected = 0;
            $total = 0;

            foreach ($rows as $row) {
                $total++;
                if ($row['error'] !== null) {
                    $rejected++;
                    if (count($errors) < 100) {
                        $errors[] = ['line' => $row['line'], 'error' => $row['error']];
                    }

                    continue;
                }

                $fields = $row['fields'];
                $deduplicationHash = $identity->hash($upload->device_id, $fields);
                $deviceEmployee = $deviceEmployees->get($fields[0]);

                if ($deviceEmployee === null) {
                    $employee = Employee::query()->firstOrCreate(
                        [
                            'company_id' => $upload->company_id,
                            'employee_number' => $fields[0],
                        ],
                        [
                            'name' => "Device PIN {$fields[0]}",
                            'is_active' => true,
                        ],
                    );
                    $deviceEmployee = DeviceEmployee::query()->firstOrCreate(
                        [
                            'device_id' => $upload->device_id,
                            'pin' => $fields[0],
                        ],
                        ['employee_id' => $employee->id],
                    );
                    $deviceEmployees->put($fields[0], $deviceEmployee);

                    AttendancePunch::query()
                        ->where('device_id', $upload->device_id)
                        ->where('pin', $fields[0])
                        ->whereNull('device_employee_id')
                        ->update([
                            'device_employee_id' => $deviceEmployee->id,
                            'updated_at' => now(),
                        ]);
                }

                $occurredAt = new \DateTimeImmutable($fields[1], new \DateTimeZone($upload->device_timezone));
                $attributes = [
                    'company_id' => $upload->company_id,
                    'device_id' => $upload->device_id,
                    'attendance_upload_id' => $upload->id,
                    'device_employee_id' => $deviceEmployee->id,
                    'pin' => $fields[0],
                    'occurred_at_local' => $fields[1],
                    'occurred_at_utc' => $occurredAt->setTimezone(new \DateTimeZone('UTC')),
                    'timezone' => $upload->device_timezone,
                    'time_quality' => 'resolved',
                    'status_code' => $fields[2] ?? null,
                    'verification_code' => $fields[3] ?? null,
                    'work_code' => ($fields[4] ?? '') !== '' ? $fields[4] : null,
                    'raw_fields' => $fields,
                    'deduplication_hash' => $deduplicationHash,
                    'identity_version' => 1,
                    'received_at' => $upload->received_at,
                ];

                if (AttendancePunch::query()->where('device_id', $upload->device_id)->where('deduplication_hash', $deduplicationHash)->exists()) {
                    AttendancePunch::query()
                        ->where('device_id', $upload->device_id)
                        ->where('deduplication_hash', $deduplicationHash)
                        ->whereNull('device_employee_id')
                        ->update([
                            'device_employee_id' => $deviceEmployee->id,
                            'updated_at' => now(),
                        ]);
                    $duplicates++;

                    continue;
                }

                AttendancePunch::query()->create($attributes);
                $inserted++;
            }

            $lockedUpload->forceFill([
                'checkpoint_line' => $rows[array_key_last($rows)]['line'],
                'total_rows' => $lockedUpload->total_rows + $total,
                'inserted_rows' => $lockedUpload->inserted_rows + $inserted,
                'duplicate_rows' => $lockedUpload->duplicate_rows + $duplicates,
                'rejected_rows' => $lockedUpload->rejected_rows + $rejected,
                'errors' => $errors,
                'processing_lease_expires_at' => now()->addMinutes(2),
            ])->save();
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        AttendanceUpload::query()->whereKey($this->uploadId)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'failed',
            'processing_token' => null,
            'processing_lease_expires_at' => null,
            'errors' => json_encode([['error' => 'Processing attempts exhausted.']], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
}
