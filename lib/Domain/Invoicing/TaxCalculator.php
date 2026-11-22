<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Invoicing;

use DomainException;

/**
 * General arithmetic primitives; not a tax-law or tax-presence engine.
 * Amounts and rates are bounded integers. One explicit tax rule per line.
 */
final class TaxCalculator {
    public const MAX_MINOR=9000000000000;

    /** @param array<string,mixed>|null $rule */
    public static function calculate(int $quantity, int $unitPriceMinor, ?array $rule):array {
        if ($quantity<1 || $quantity>100000 || $unitPriceMinor<0
            || $unitPriceMinor>self::MAX_MINOR
            || $unitPriceMinor>intdiv(self::MAX_MINOR,$quantity)) {
            throw new DomainException('invalid_line_amount');
        }
        $gross=$quantity*$unitPriceMinor;
        $base=$gross;
        $tax=0;
        $withheld=0;
        if ($rule!==null) {
            $rate=(int)$rule['rate_bps'];
            $kind=(string)$rule['kind'];
            $inclusive=(bool)$rule['inclusive'];
            if ($rate<0 || $rate>100000
                || !in_array($kind,['vat','sales','withholding','other'],true)
                || ($kind==='withholding' && $inclusive)) {
                throw new DomainException('invalid_tax_rule');
            }
            if ($kind==='withholding') {
                $withheld=self::divideRound($gross*$rate,10000);
            } elseif ($inclusive) {
                $tax=self::divideRound($gross*$rate,10000+$rate);
                $base=$gross-$tax;
            } else {
                $tax=self::divideRound($gross*$rate,10000);
            }
        }
        $total=$base+$tax-$withheld;
        if ($total<0 || $total>self::MAX_MINOR) {
            throw new DomainException('invalid_invoice_total');
        }
        return ['baseMinor'=>$base,'taxMinor'=>$tax,
            'withheldMinor'=>$withheld,'totalMinor'=>$total];
    }

    private static function divideRound(int $numerator,int $denominator):int {
        return intdiv($numerator+intdiv($denominator,2),$denominator);
    }
}
