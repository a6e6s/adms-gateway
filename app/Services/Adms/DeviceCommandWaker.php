<?php

namespace App\Services\Adms;

use App\Models\Device;
use RuntimeException;

class DeviceCommandWaker
{
    private const DEVICE_WAKE_PORT = 4374;

    private const WAKE_COMMAND = 'R-CMD';

    public function wake(Device $device): void
    {
        $ipAddress = $device->last_seen_ip;
        if (! is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('No valid last-seen device IP is available for the wake-up packet.');
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The last-seen device IP is in a reserved address range.');
        }

        $isPublicAddress = filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if ($isPublicAddress && $device->expected_ip !== $ipAddress) {
            throw new RuntimeException('Wake-up to public IP addresses requires that address to be configured as the expected device IP.');
        }

        $uri = str_contains($ipAddress, ':')
            ? "udp://[{$ipAddress}]:".self::DEVICE_WAKE_PORT
            : "udp://{$ipAddress}:".self::DEVICE_WAKE_PORT;
        $socket = @stream_socket_client($uri, $errorCode, $errorMessage, 0.25, STREAM_CLIENT_CONNECT);

        if (! is_resource($socket)) {
            throw new RuntimeException("Could not open the device wake-up socket ({$errorCode}): {$errorMessage}");
        }

        try {
            $bytesSent = @fwrite($socket, self::WAKE_COMMAND);
        } finally {
            fclose($socket);
        }

        if ($bytesSent !== strlen(self::WAKE_COMMAND)) {
            throw new RuntimeException('The device wake-up datagram could not be sent.');
        }
    }
}
