<?php

namespace App\Services\Adms;

use App\Models\Device;
use Carbon\CarbonImmutable;
use RuntimeException;

class ZkDeviceClockReader
{
    private const MACHINE_PREPARE_DATA_1 = 0x5050;

    private const MACHINE_PREPARE_DATA_2 = 0x7D82;

    private const CMD_CONNECT = 1000;

    private const CMD_EXIT = 1001;

    private const CMD_AUTH = 1102;

    private const CMD_GET_TIME = 201;

    private const CMD_ACK_OK = 2000;

    private const CMD_ACK_UNAUTH = 2005;

    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;

    private int $replyId = 65534;

    public function read(Device $device): CarbonImmutable
    {
        $ipAddress = $device->last_seen_ip;
        if (! is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('No valid IPv4 last-seen device address is available.');
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The device address is in a reserved address range.');
        }

        $isPublicAddress = filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
        if ($isPublicAddress && $device->expected_ip !== $ipAddress) {
            throw new RuntimeException('Direct clock reads to public IP addresses require the configured expected device IP.');
        }

        $port = (int) config('services.adms.zk_sdk_port', 4370);
        $timeout = max(1, min(10, (int) config('services.adms.zk_sdk_timeout_seconds', 3)));
        $commKey = (int) config('services.adms.zk_comm_key', 0);
        $socket = @stream_socket_client("tcp://{$ipAddress}:{$port}", $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
        if (! is_resource($socket)) {
            throw new RuntimeException("Could not connect to the device SDK port ({$errorCode}): {$errorMessage}");
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $connectResponse = $this->sendCommand(self::CMD_CONNECT);
            $this->sessionId = $connectResponse['session_id'];

            if ($connectResponse['command'] === self::CMD_ACK_UNAUTH) {
                $authResponse = $this->sendCommand(self::CMD_AUTH, $this->makeCommKey($commKey, $this->sessionId));
                if ($authResponse['command'] !== self::CMD_ACK_OK) {
                    throw new RuntimeException('The device rejected SDK authentication.');
                }
            } elseif ($connectResponse['command'] !== self::CMD_ACK_OK) {
                throw new RuntimeException('The device rejected the SDK connection.');
            }

            $timeResponse = $this->sendCommand(self::CMD_GET_TIME);
            if ($timeResponse['command'] !== self::CMD_ACK_OK || strlen($timeResponse['data']) < 4) {
                throw new RuntimeException('The device did not return a valid clock reading.');
            }

            $packedTime = unpack('Vtimestamp', substr($timeResponse['data'], 0, 4));
            if (! is_array($packedTime) || ! isset($packedTime['timestamp'])) {
                throw new RuntimeException('The device returned an invalid clock value.');
            }

            $timestamp = (int) $packedTime['timestamp'];
            $second = $timestamp % 60;
            $timestamp = intdiv($timestamp, 60);
            $minute = $timestamp % 60;
            $timestamp = intdiv($timestamp, 60);
            $hour = $timestamp % 24;
            $timestamp = intdiv($timestamp, 24);
            $day = ($timestamp % 31) + 1;
            $timestamp = intdiv($timestamp, 31);
            $month = ($timestamp % 12) + 1;
            $year = intdiv($timestamp, 12) + 2000;

            $deviceTime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
            $parsedTime = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $deviceTime, $device->timezone);
            if ($parsedTime === false || $parsedTime->format('Y-m-d H:i:s') !== $deviceTime) {
                throw new RuntimeException('The device returned a calendar date that could not be interpreted.');
            }

            return $parsedTime->utc();
        } finally {
            if (is_resource($this->socket)) {
                if ($this->sessionId !== 0) {
                    try {
                        $this->sendCommand(self::CMD_EXIT);
                    } catch (RuntimeException) {
                    }
                }

                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /** @return array{command: int, session_id: int, data: string} */
    private function sendCommand(int $command, string $data = ''): array
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('The device SDK socket is closed.');
        }

        $replyId = ($this->replyId + 1) % 65535;
        $checksumPacket = pack('v4', $command, 0, $this->sessionId, $this->replyId).$data;
        $packet = pack('v4', $command, $this->checksum($checksumPacket), $this->sessionId, $replyId).$data;
        $this->writeAll(pack('v2V', self::MACHINE_PREPARE_DATA_1, self::MACHINE_PREPARE_DATA_2, strlen($packet)).$packet);

        $frame = unpack('vfirst/vsecond/Vlength', $this->readExact(8));
        if (! is_array($frame)
            || $frame['first'] !== self::MACHINE_PREPARE_DATA_1
            || $frame['second'] !== self::MACHINE_PREPARE_DATA_2
            || $frame['length'] < 8
            || $frame['length'] > 65_536) {
            throw new RuntimeException('The device returned an invalid SDK response frame.');
        }

        $response = $this->readExact($frame['length']);
        $header = unpack('vcommand/vchecksum/vsession_id/vreply_id', substr($response, 0, 8));
        if (! is_array($header)) {
            throw new RuntimeException('The device returned an invalid SDK response header.');
        }

        $this->replyId = $header['reply_id'];

        return [
            'command' => $header['command'],
            'session_id' => $header['session_id'],
            'data' => substr($response, 8),
        ];
    }

    private function writeAll(string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($payload, $offset));
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('Could not send the SDK request to the device.');
            }

            $offset += $written;
        }
    }

    private function readExact(int $length): string
    {
        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = @fread($this->socket, $length - strlen($payload));
            if (! is_string($chunk) || $chunk === '') {
                $metadata = stream_get_meta_data($this->socket);
                if (($metadata['timed_out'] ?? false) === true) {
                    throw new RuntimeException('The device SDK request timed out.');
                }

                throw new RuntimeException('The device closed the SDK connection before responding.');
            }

            $payload .= $chunk;
        }

        return $payload;
    }

    private function checksum(string $packet): int
    {
        $checksum = 0;
        $length = strlen($packet);
        for ($offset = 0; $offset + 1 < $length; $offset += 2) {
            $checksum += ord($packet[$offset]) | (ord($packet[$offset + 1]) << 8);
            if ($checksum > 65535) {
                $checksum -= 65535;
            }
        }

        if ($length % 2 !== 0) {
            $checksum += ord($packet[$length - 1]);
        }

        while ($checksum > 65535) {
            $checksum -= 65535;
        }

        $checksum = ~$checksum;
        while ($checksum < 0) {
            $checksum += 65535;
        }

        return $checksum;
    }

    private function makeCommKey(int $key, int $sessionId): string
    {
        $scrambledKey = 0;
        for ($bit = 0; $bit < 32; $bit++) {
            $scrambledKey = ($scrambledKey << 1) | (($key >> $bit) & 1);
        }

        $bytes = array_values(unpack('C4', pack('V', ($scrambledKey + $sessionId) & 0xFFFFFFFF)));
        foreach ([ord('Z'), ord('K'), ord('S'), ord('O')] as $index => $mask) {
            $bytes[$index] ^= $mask;
        }

        $words = array_values(unpack('v2', pack('C4', ...$bytes)));
        $bytes = array_values(unpack('C4', pack('v2', $words[1], $words[0])));
        $ticks = 50;

        return pack('C4', $bytes[0] ^ $ticks, $bytes[1] ^ $ticks, $ticks, $bytes[3] ^ $ticks);
    }
}
