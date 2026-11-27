<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Banking;

use DomainException;
use OCA\LibreFinance\Domain\Currency\CurrencyMetadata;

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

    /**
     * @return array{
     *     currency:?string,
     *     statementPeriod:?array{from:?string,through:?string},
     *     ledgerBalance:?array{date:string,amount_minor:int,currency:string},
     *     availableBalance:?array{date:string,amount_minor:int,currency:string},
     *     rows:list<array{id:string,date:string,amount_minor:int,description:string}>
     * }
     */
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
        $banktranlistBlock = $lists[1][0];

        // Extract statement period from BANKTRANLIST DTSTART / DTEND
        $periodBlock = preg_replace('~<STMTTRN(?:\s[^>]*)?>.*?</STMTTRN\s*>~is', '', $banktranlistBlock);
        if ($periodBlock === null
            || preg_match_all('~</?DT(?:START|END)\b~i', $periodBlock)
                !== preg_match_all('~</?DT(?:START|END)\b~i', $banktranlistBlock)) {
            throw new DomainException('invalid_ofx_row');
        }
        $statementPeriod = self::parseBanktranlistPeriod($periodBlock);

        $found = preg_match_all('~<STMTTRN(?:\s[^>]*)?>(.*?)</STMTTRN\s*>~is',
            $banktranlistBlock, $transactions);
        if (!$found || $found > self::MAX_ROWS) {
            throw new DomainException('invalid_ofx_row');
        }
        if (preg_match_all('~<STMTTRN(?:\s[^>]*)?>~i', $banktranlistBlock) !== $found) {
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
        $normalizedCurrency = $currency !== null ? strtoupper($currency) : null;

        // Parse ledger and available balance aggregates
        $balanceBlock = '';
        if (preg_match('~</?(?:LEDGERBAL|AVAILBAL)\b~i', $text)) {
            if (preg_match_all('~<STMTRS(?:\s[^>]*)?>(.*?)</STMTRS\s*>~is', $text, $statements) !== 1
                || substr_count($statements[1][0], $lists[0][0]) !== 1) {
                throw new DomainException('invalid_ofx_accounts');
            }
            // A balance inherits the explicit currency of its own statement,
            // not a currency-like leaf elsewhere in the file. CURDEF is the
            // first statement field; SGML may omit its closing leaf tag.
            if (preg_match('~</?CURDEF\\b~i', $text)
                && (preg_match('~\\A\\s*<CURDEF\\s*>[^<]*+(?:</CURDEF\\s*>)?\\s*+(?=<[A-Za-z])~i',
                    $statements[1][0], $currencyPrefix) !== 1
                    || preg_match_all('~</?CURDEF\\b~i', $text)
                        !== preg_match_all('~</?CURDEF\\b~i', $currencyPrefix[0]))) {
                throw new DomainException('invalid_ofx_row');
            }
            // Checkpoints in this supported statement profile are siblings
            // after the selected BANKTRANLIST, never part of its transactions.
            $after = strpos($statements[1][0], $lists[0][0]) + strlen($lists[0][0]);
            $balanceBlock = substr($statements[1][0], $after);
            $matched = preg_replace('~<(LEDGERBAL|AVAILBAL)\s*>\s*+(?:<(BALAMT|DTASOF)\s*>[^<]*+(?:</\2\s*>)?\s*+)*</\1\s*>~i', '', $balanceBlock);
            if ($matched === null || trim($matched) !== ''
                || preg_match_all('~</?(?:LEDGERBAL|AVAILBAL)\b~i', $text)
                    !== preg_match_all('~</?(?:LEDGERBAL|AVAILBAL)\b~i', $balanceBlock)) {
                throw new DomainException('invalid_ofx_row');
            }
        }
        $ledgerBalance = self::parseBalanceAggregate($balanceBlock, 'LEDGERBAL', $normalizedCurrency);
        $availableBalance = self::parseBalanceAggregate($balanceBlock, 'AVAILBAL', $normalizedCurrency);

        $ids = [];
        $rows = [];
        foreach ($transactions[1] as $block) {
            try {
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
                $date = self::parseStrictDate($posted);
                $id = preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $originalId)
                    ? $originalId : 'ofx-' . hash('sha256', $originalId);
                if (isset($ids[$id])) {
                    throw new DomainException('invalid_ofx_row');
                }
                $ids[$id] = true;
                $rows[] = ['id' => $id, 'date' => $date,
                    'amount_minor' => self::minor($value, false), 'description' => $memo];
            } catch (DomainException $e) {
                throw new StatementParseException($e, count($rows) + 1);
            }
        }
        return [
            'currency' => $normalizedCurrency,
            'statementPeriod' => $statementPeriod,
            'ledgerBalance' => $ledgerBalance,
            'availableBalance' => $availableBalance,
            'rows' => $rows,
        ];
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

    private static function parseStrictDate(string $dateStr): string {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(?:\.\d{1,6})?)?(?:\[[^\]\r\n]{1,24}\])?$/D', $dateStr, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
            || (($parts[4] ?? '') !== ''
                && ((int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59))) {
            throw new DomainException('invalid_ofx_date');
        }
        return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
    }

    private static function parseBanktranlistPeriod(string $banktranlistBlock): ?array {
        if (!preg_match('~</?DT(?:START|END)\b~i', $banktranlistBlock)) {
            return null;
        }
        // Only the two supported, direct BANKTRANLIST leaves may supply dates.
        // SGML may omit a closing leaf; XML must not close a different leaf.
        if (preg_match('~\A\s*+(?:<(DTSTART|DTEND)\s*>[^<]*+(?:</\1\s*>)?\s*+)*\z~i', $banktranlistBlock) !== 1) {
            throw new DomainException('invalid_ofx_row');
        }
        $start = self::leaf($banktranlistBlock, 'DTSTART');
        $end = self::leaf($banktranlistBlock, 'DTEND');
        if ($start === '' || $end === '') {
            throw new DomainException('invalid_ofx_row');
        }
        $from = $start !== null ? self::parseStrictDate($start) : null;
        $through = $end !== null ? self::parseStrictDate($end) : null;
        if ($from !== null && $through !== null && $from > $through) {
            throw new DomainException('invalid_ofx_date');
        }
        return ['from' => $from, 'through' => $through];
    }

    /** @return ?array{date:string,amount_minor:int,currency:string} */
    private static function parseBalanceAggregate(string $text, string $tagName, ?string $defaultCurrency): ?array {
        $count = preg_match_all('~<' . $tagName . '(?:\s[^>]*)?>(.*?)</' . $tagName . '\s*>~is', $text, $matches);
        if ($count === false || $count > 1) {
            throw new DomainException('invalid_ofx_row');
        }
        if ($count === 0) {
            return null;
        }
        $block = $matches[1][0];
        $amtStr = self::leaf($block, 'BALAMT');
        $dtStr = self::leaf($block, 'DTASOF');
        if ($amtStr === null || $dtStr === null) {
            throw new DomainException('invalid_ofx_row');
        }
        if ($defaultCurrency === null || CurrencyMetadata::precision($defaultCurrency) !== 2) {
            throw new DomainException('invalid_ofx_currency');
        }
        $currency = $defaultCurrency;
        $date = self::parseStrictDate($dtStr);
        $amountMinor = self::minor($amtStr, true);
        return [
            'date' => $date,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ];
    }

    private static function minor(string $value, bool $allowZero = false): int {
        if (!preg_match('/^([+-]?)(\d{1,10})(?:[.,](\d{1,6}))?$/D',
            trim($value), $m)) {
            throw new DomainException('invalid_ofx_amount');
        }
        // Preserve the existing two-decimal transaction contract.
        $fraction = str_pad($m[3] ?? '', 2, '0');
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new DomainException('invalid_ofx_amount');
        }
        $minor = (int)$m[2] * 100 + (int)substr($fraction, 0, 2);
        if ($m[1] === '-') {
            $minor = -$minor;
        }
        if (!$allowZero && $minor === 0) {
            throw new DomainException('invalid_ofx_amount');
        }
        if (abs($minor) > self::MAX_MINOR) {
            throw new DomainException('invalid_ofx_amount');
        }
        return $minor;
    }
}
