<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Obligations;

use DomainException;

final class RecurrenceSchedule {
    public static function dates(string $date, int $count, int $stepMonths, string $endOfMonth): array {
        if ($count < 1 || $count > 24) {
            throw new DomainException('invalid_recurrence');
        }
        if ($stepMonths < 1 || $stepMonths > 12) {
            throw new DomainException('invalid_recurrence');
        }
        if ($endOfMonth !== 'clamp' && $endOfMonth !== 'reject') {
            throw new DomainException('invalid_recurrence');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new DomainException('invalid_recurrence');
        }

        $parts = explode('-', $date);
        $year = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];

        if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
            throw new DomainException('invalid_recurrence');
        }

        $dates = [];
        $totalMonths = ($year * 12) + ($month - 1);

        for ($i = 1; $i <= $count; $i++) {
            $totalMonths += $stepMonths;
            $targetYear = intdiv($totalMonths, 12);
            $targetMonth = ($totalMonths % 12) + 1;

            if ($targetYear < 1 || $targetYear > 9999) {
                throw new DomainException('invalid_recurrence');
            }

            $daysInTargetMonth = 31;
            while (!checkdate($targetMonth, $daysInTargetMonth, $targetYear)) {
                --$daysInTargetMonth;
            }

            $targetDay = $day;
            if ($targetDay > $daysInTargetMonth) {
                if ($endOfMonth === 'reject') {
                    throw new DomainException('recurrence_day_unavailable');
                } else {
                    $targetDay = $daysInTargetMonth;
                }
            }

            $dates[] = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
        }

        return $dates;
    }
}
