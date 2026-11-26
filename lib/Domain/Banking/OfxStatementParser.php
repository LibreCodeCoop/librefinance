<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Banking;

use DomainException;

/**
 * Strict, bounded OFX 1.x (SGML) / OFX 2.x (XML) statement reader.
 *
 * Only bank transaction evidence is accepted. An OFX file never posts a journal.
 * Aggregate tags in OFX 1.x are closed even when leaf tags are not, so the
 * same bounded tag reader handles both supported serializations. DTDs and
 * external entities are deliberately unsupported.
 */
final class OfxStatementParser {
    private const MAX_BYTES = 262144;
    private const MAX_ROWS = 500;
    private const MAX_MINOR = 9000000000000;

    /** @return array{currency:?string,rows:list<array{id:string,date:string,amount_minor:int,description:string}>} */
    public static function parse(string $source): array {
        if ($source === '' || strlen($source) > self::MAX_BYTES
            || str_contains($source, "\0")) {
            throw new DomainException('invalid_ofx');
        }
        $text = self::decode($source);
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY|\[CDATA)/i', $text)) {
            throw new DomainException('invalid_ofx');
        }
        if (!preg_match('/<OFX(?:\s[^>]*)?>/i', $text)) {
            throw new DomainException('invalid_ofx');
        }
        $count = preg_match_all('~<BANKTRANLIST(?:\s[^>]*)?>(.*?)</BANKTRANLIST\s*>~is',
            $text, $lists);
        if ($count !== 1) {
            // One selected bank account cannot safely ingest several statements.
            throw new DomainException('invalid_ofx_accounts');
        }
        $content = $lists[1][0];
        $found = preg_match_all('~<STMTTRN(?:\s[^>]*)?>(.*?)</STMTTRN\s*>~is',
            $content, $transactions);
        if (!$found || $found > self::MAX_ROWS) {
            throw new DomainException('invalid_ofx_row');
        }
        if (preg_match_all('~<STMTTRN(?:\s[^>]*)?>~i', $content) !== $found) {
            throw new DomainException('invalid_ofx_row');
        }
        // Reject a second statement or orphan transaction outside the selected list.
        if (preg_match_all('~<STMTTRN(?:\s[^>]*)?>~i', $text) !== $found) {
            throw new DomainException('invalid_ofx_accounts');
        }
        $currency = self::leaf($text, 'CURDEF');
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new DomainException('invalid_ofx_currency');
        }
        $ids = [];
        $rows = [];
        foreach ($transactions[1] as $block) {
            $originalId = self::leaf($block, 'FITID');
            $posted = self::leaf($block, 'DTPOSTED');
            $value = self::leaf($block, 'TRNAMT');
            $memo = self::leaf($block, 'MEMO');
            $name = self::leaf($block, 'NAME');
            $memo ??= $name;
            if ($originalId === null || $originalId === '' || mb_strlen($originalId) > 255
                || $posted === null || $value === null
                || $memo === null || $memo === '' || mb_strlen($memo) > 255) {
                throw new DomainException('invalid_ofx_row');
            }
            // Bank-exported OFX dates may carry a six-digit time, optional
            // fractional seconds and a bracketed timezone. Preserve the
            // booking date instead of guessing a user-local conversion.
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(?:\.\d{1,6})?)?(?:\[[^\]\r\n]{1,24}\])?$/D', $posted, $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
                || (($parts[4] ?? '') !== ''
                    && ((int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59))) {
                throw new DomainException('invalid_ofx_date');
            }
            $date = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
            $id = preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $originalId)
                ? $originalId : 'ofx-' . hash('sha256', $originalId);
            if (isset($ids[$id])) {
                throw new DomainException('invalid_ofx_row');
            }
            $ids[$id] = true;
            $rows[] = ['id' => $id, 'date' => $date,
                'amount_minor' => self::minor($value), 'description' => $memo];
        }
        return ['currency' => $currency !== null ? strtoupper($currency) : null,
            'rows' => $rows];
    }

    private static function decode(string $source): string {
        $charset = null;
        if (preg_match('/^CHARSET:\s*(\d+)\s*$/mi', $source, $m)) {
            $charset = match ($m[1]) {
                '1252' => 'Windows-1252',
                '8859' => 'ISO-8859-1',
                '65001' => 'UTF-8',
                default => null,
            };
        }
        if ($charset === null && preg_match('/<\?xml[^>]*encoding=["\x27]([^"\x27]+)["\x27]/i', $source, $m)) {
            $charset = match (strtoupper($m[1])) {
                'WINDOWS-1252', 'CP1252' => 'Windows-1252',
                'ISO-8859-1', 'LATIN1' => 'ISO-8859-1',
                'UTF-8' => 'UTF-8',
                default => null,
            };
        }
        // The JSON browser client sends Unicode strings, already decoded to
        // UTF-8 from the bank's declared charset. Preserve valid UTF-8 to
        // avoid mojibake; support raw legacy bytes from other API clients.
        if (mb_check_encoding($source, 'UTF-8')) {
            $text = $source;
        } elseif ($charset !== null && $charset !== 'UTF-8') {
            $text = mb_convert_encoding($source, 'UTF-8', $charset);
        } else {
            throw new DomainException('invalid_ofx_encoding');
        }
        if (strlen($text) > self::MAX_BYTES * 2 || !mb_check_encoding($text, 'UTF-8')) {
            throw new DomainException('invalid_ofx_encoding');
        }
        return $text;
    }

    private static function leaf(string $block, string $name): ?string {
        $count = preg_match_all('~<' . $name . '\s*>([^<]*)~i', $block, $matches);
        if ($count === false) {
            throw new DomainException('invalid_ofx_row');
        }
        if ($count === 0) {
            return null;
        }
        if ($count > 1) {
            throw new DomainException('invalid_ofx_row');
        }
        $text = trim(html_entity_decode($matches[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
            throw new DomainException('invalid_ofx_row');
        }
        return $text;
    }

    private static function minor(string $value): int {
        if (!preg_match('/^([+-]?)(\d{1,10})(?:[.,](\d{1,6}))?$/D',
            trim($value), $m)) {
            throw new DomainException('invalid_ofx_amount');
        }
        // Some OFX providers export four or six decimal places. Accept
        // only extra zeroes; never round fractional cents into money.
        $fraction = str_pad($m[3] ?? '', 2, '0');
        if (strlen($fraction)>2 && trim(substr($fraction, 2), '0') !== '') {
            throw new DomainException('invalid_ofx_amount');
        }
        $minor = (int)$m[2] * 100 + (int)substr($fraction, 0, 2);
        if ($m[1] === '-') $minor = -$minor;
        if ($minor === 0 || abs($minor) > self::MAX_MINOR) {
            throw new DomainException('invalid_ofx_amount');
        }
        return $minor;
    }
}
