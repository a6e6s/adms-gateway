<?php

namespace App\Http\Controllers\Iclock;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Adms\AcceptAttendanceUpload;
use App\Services\Adms\DeviceCommandService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DeviceController extends Controller
{
    private const MAX_UPLOAD_BYTES = 1_048_576;

    public function initialize(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        if ($device instanceof Response) {
            return $device;
        }

        $this->touch($device, $request);
        $pushVersion = $request->query('pushver');
        $deviceType = $request->query('DeviceType');
        $device->forceFill([
            'push_version' => is_string($pushVersion) ? mb_substr($pushVersion, 0, 40) : $device->push_version,
            'device_type' => is_string($deviceType) ? mb_substr($deviceType, 0, 80) : $device->device_type,
        ])->save();

        return response(implode("\n", [
            "GET OPTION FROM: {$device->serial_number}",
            'ATTLOGStamp='.($device->attlog_stamp ?? 'None'),
            'ErrorDelay=60',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=1000000000',
            'Realtime=1',
            'Encrypt=0',
            '',
        ]), SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request, AcceptAttendanceUpload $acceptUpload): Response
    {
        $device = $this->resolveDevice($request);
        if ($device instanceof Response) {
            return $device;
        }

        $table = mb_strtoupper((string) $request->query('table', ''));
        if (! in_array($table, ['ATTLOG', 'OPTIONS'], true)) {
            return $this->plain('Unsupported table', SymfonyResponse::HTTP_BAD_REQUEST);
        }

        if ($request->headers->has('Content-Encoding') || $request->headers->has('Transfer-Encoding')) {
            return $this->plain('Unsupported content encoding', SymfonyResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_UPLOAD_BYTES) {
            return $this->plain('Upload exceeds 1 MiB limit', SymfonyResponse::HTTP_CONTENT_TOO_LARGE);
        }

        $payload = $request->getContent();
        if (strlen($payload) > self::MAX_UPLOAD_BYTES) {
            return $this->plain('Upload exceeds 1 MiB limit', SymfonyResponse::HTTP_CONTENT_TOO_LARGE);
        }

        $this->touch($device, $request);
        if ($table === 'OPTIONS') {
            return $this->plain('OK');
        }

        $stamp = $request->query('Stamp');
        $stamp = is_string($stamp) ? preg_replace('/[\x00-\x1F\x7F]/', '', trim($stamp)) : null;
        $stamp = is_string($stamp) && $stamp !== '' ? mb_substr($stamp, 0, 100) : null;
        $acceptUpload->accept($device, $payload, $stamp, $request->ip());

        return $this->plain('OK');
    }

    public function poll(Request $request, DeviceCommandService $commands): Response
    {
        $device = $this->resolveDevice($request);
        if ($device instanceof Response) {
            return $device;
        }

        $this->touch($device, $request, commandPoll: true);

        return $this->plain($commands->offerNext($device));
    }

    public function result(Request $request, DeviceCommandService $commands): Response
    {
        $device = $this->resolveDevice($request, allowExpectedIpWithoutSerial: true);
        if ($device instanceof Response) {
            return $device;
        }

        $payload = $request->getContent();
        if (strlen($payload) > 16_384) {
            return $this->plain('Result too large', SymfonyResponse::HTTP_CONTENT_TOO_LARGE);
        }

        try {
            $commands->recordResult($device, $payload, $request->ip());
        } catch (\InvalidArgumentException) {
            return $this->plain('Invalid command result', SymfonyResponse::HTTP_BAD_REQUEST);
        }

        $this->touch($device, $request);

        return $this->plain('OK');
    }

    public function ping(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        if ($device instanceof Response) {
            return $device;
        }

        $this->touch($device, $request);

        return $this->plain('OK');
    }

    private function resolveDevice(Request $request, bool $allowExpectedIpWithoutSerial = false): Device|Response
    {
        $serial = $request->query('SN');
        $device = is_string($serial) && $serial !== ''
            ? Device::query()->with('company')->where('serial_number', $serial)->first()
            : null;

        if ($device === null && $allowExpectedIpWithoutSerial && $request->ip() !== null) {
            $matches = Device::query()->with('company')->where('last_seen_ip', $request->ip())->limit(2)->get();
            $device = $matches->count() === 1 ? $matches->first() : null;
        }

        if ($device === null || ! $device->is_enabled || ! $device->company?->is_active) {
            return $this->plain('Unknown device', SymfonyResponse::HTTP_FORBIDDEN);
        }

        if ($device->expected_ip !== null && $device->expected_ip !== $request->ip()) {
            return $this->plain('Unexpected device address', SymfonyResponse::HTTP_FORBIDDEN);
        }

        return $device;
    }

    private function touch(Device $device, Request $request, bool $commandPoll = false): void
    {
        $attributes = [
            'last_seen_at' => now(),
            'last_seen_ip' => $request->ip(),
        ];

        if ($commandPoll) {
            $attributes['last_getrequest_at'] = now();
        }

        $device->forceFill($attributes)->save();
    }

    private function plain(string $body, int $status = SymfonyResponse::HTTP_OK): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
