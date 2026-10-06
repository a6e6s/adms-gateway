<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case CheckIn = '0';
    case CheckOut = '1';
    case BreakOut = '2';
    case BreakIn = '3';
    case OvertimeIn = '4';
    case OvertimeOut = '5';

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return __('filament/resources/attendance-punches.status_codes.unknown');
        }

        return match (self::tryFrom($code)) {
            self::CheckIn => __('filament/resources/attendance-punches.status_codes.check_in'),
            self::CheckOut => __('filament/resources/attendance-punches.status_codes.check_out'),
            self::BreakOut => __('filament/resources/attendance-punches.status_codes.break_out'),
            self::BreakIn => __('filament/resources/attendance-punches.status_codes.break_in'),
            self::OvertimeIn => __('filament/resources/attendance-punches.status_codes.overtime_in'),
            self::OvertimeOut => __('filament/resources/attendance-punches.status_codes.overtime_out'),
            null => __('filament/resources/attendance-punches.status_codes.unmapped', ['code' => $code]),
        };
    }
}
