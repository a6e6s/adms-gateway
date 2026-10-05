<?php

namespace App\Services\Adms;

class AttendanceIdentity
{
    /**
     * @param  list<string>  $fields
     */
    public function hash(int $deviceId, array $fields): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'device_id' => $deviceId,
            'fields' => $fields,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
