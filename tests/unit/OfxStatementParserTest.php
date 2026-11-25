<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit;

use DomainException;
use OCA\LibreFinance\Domain\Banking\OfxStatementParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfxStatementParserTest extends TestCase {
    private const SGML = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
ENCODING:USASCII
CHARSET:1252

<OFX><STMTRS><CURDEF>BRL
<BANKTRANLIST><DTSTART>20261120
<STMTTRN><DTPOSTED>20261120124500[-3:BRT]
<TRNAMT>-25.90
<FITID>ofx-id-one
<MEMO>Pagamento de energia
</STMTTRN>
<STMTTRN><DTPOSTED>20261122
<TRNAMT>150,00
<FITID>ofx-id-two
<NAME>Recebimento de cliente
</STMTTRN></BANKTRANLIST></STMTRS></OFX>
OFX;

    private const XML = <<<'OFX'
<?xml version="1.0" encoding="UTF-8"?>
<OFX><STMTRS><CURDEF>BRL</CURDEF><BANKTRANLIST>
<STMTTRN><DTPOSTED>20261122</DTPOSTED><TRNAMT>1.05</TRNAMT>
<FITID>id&lt;123</FITID><NAME>Loja &amp; Cia</NAME></STMTTRN>
</BANKTRANLIST></STMTRS></OFX>
OFX;

    public function testParsesSgml(): void {
        $statement = OfxStatementParser::parse(self::SGML);

        self::assertSame('BRL', $statement['currency']);
        self::assertCount(2, $statement['rows']);
        self::assertSame(-2590, $statement['rows'][0]['amount_minor']);
        self::assertSame(15000, $statement['rows'][1]['amount_minor']);
        self::assertSame('2026-11-20', $statement['rows'][0]['date']);
    }

    public function testParsesXml(): void {
        $statement = OfxStatementParser::parse(self::XML);

        self::assertSame(105, $statement['rows'][0]['amount_minor']);
        self::assertSame('Loja & Cia', $statement['rows'][0]['description']);
        self::assertStringStartsWith('ofx-', $statement['rows'][0]['id']);
    }

    public function testFractionalSecondsWithTimezone(): void {
        $statement = OfxStatementParser::parse(str_replace('20261120124500[-3:BRT]', '20261120124500.000[-3:BRT]', self::SGML));

        self::assertSame('2026-11-20', $statement['rows'][0]['date']);
    }

    public function testAdditionalZeroDecimalPlaces(): void {
        $statement = OfxStatementParser::parse(str_replace('-25.90', '-25.900000', self::SGML));

        self::assertSame(-2590, $statement['rows'][0]['amount_minor']);
    }

    public static function invalidStatements(): array {
        return [
            'fractional cent' => [str_replace('-25.90', '-25.900001', self::SGML)],
            'truncated time' => [str_replace('20261120124500[-3:BRT]', '202611201245[-3:BRT]', self::SGML)],
            'invalid time' => [str_replace('20261120124500[-3:BRT]', '20261120999999[-3:BRT]', self::SGML)],
            'invalid date' => [str_replace('20261120', '20260230', self::SGML)],
            'zero amount' => [str_replace('150,00', '0.00', self::SGML)],
            'duplicate FITID' => [str_replace('ofx-id-two', 'ofx-id-one', self::SGML)],
            'duplicate transaction list' => [str_replace('</BANKTRANLIST>', '</BANKTRANLIST><BANKTRANLIST></BANKTRANLIST>', self::SGML)],
            'external entity declaration' => ['<!DOCTYPE OFX [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . self::SGML],
            'oversized statement' => [str_repeat('x', 262145)],
        ];
    }

    #[DataProvider('invalidStatements')]
    public function testRejectsInvalidStatement(string $statement): void {
        $this->expectException(DomainException::class);
        OfxStatementParser::parse($statement);
    }
}
