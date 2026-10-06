<?php

use App\Enums\AttendanceStatus;

it('maps known punch status codes to enum cases', function () {
    expect(AttendanceStatus::tryFrom('0'))->toBe(AttendanceStatus::CheckIn)
        ->and(AttendanceStatus::tryFrom('1'))->toBe(AttendanceStatus::CheckOut)
        ->and(AttendanceStatus::tryFrom('5'))->toBe(AttendanceStatus::OvertimeOut)
        ->and(AttendanceStatus::tryFrom('99'))->toBeNull();
});
