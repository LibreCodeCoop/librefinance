<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Banking;

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

    public static function duplicateSingletonSgmlCases(): array {
        return [
            'duplicate CURDEF' => [str_replace('<CURDEF>BRL', '<CURDEF>BRL<CURDEF>USD', self::SGML)],
            'duplicate FITID within same transaction' => [str_replace('<FITID>ofx-id-one', '<FITID>ofx-id-one<FITID>ofx-id-one-dup', self::SGML)],
            'duplicate DTPOSTED' => [str_replace('<DTPOSTED>20261120124500[-3:BRT]', '<DTPOSTED>20261120124500[-3:BRT]<DTPOSTED>20261121', self::SGML)],
            'duplicate TRNAMT' => [str_replace('<TRNAMT>-25.90', '<TRNAMT>-25.90<TRNAMT>-10.00', self::SGML)],
            'duplicate MEMO' => [str_replace('<MEMO>Pagamento de energia', '<MEMO>Pagamento de energia<MEMO>Outro memo', self::SGML)],
            'duplicate NAME' => [str_replace('<STMTTRN><DTPOSTED>20261122', '<STMTTRN><DTPOSTED>20261122<NAME>Nome 1<NAME>Nome 2', self::SGML)],
            'duplicate NAME while MEMO exists' => [str_replace('<MEMO>Pagamento de energia', '<MEMO>Pagamento de energia<NAME>Nome 1<NAME>Nome 2', self::SGML)],
        ];
    }

    #[DataProvider('duplicateSingletonSgmlCases')]
    public function testRejectsDuplicateSingletonSgml(string $statement): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_ofx_row\z/');
        OfxStatementParser::parse($statement);
    }

    public static function duplicateXmlCases(): array {
        return [
            'duplicate TRNAMT in XML' => [str_replace('<TRNAMT>1.05</TRNAMT>', '<TRNAMT>1.05</TRNAMT><TRNAMT>2.00</TRNAMT>', self::XML)],
        ];
    }

    #[DataProvider('duplicateXmlCases')]
    public function testRejectsDuplicateXml(string $statement): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_ofx_row\z/');
        OfxStatementParser::parse($statement);
    }

    public function testMemoPreferenceWithExistingRowsAndAmounts(): void {
        $sgmlWithBoth = str_replace(
            '<MEMO>Pagamento de energia',
            '<MEMO>Pagamento de energia</MEMO><NAME>Recebimento Nome',
            self::SGML
        );
        $statement = OfxStatementParser::parse($sgmlWithBoth);

        self::assertSame('BRL', $statement['currency']);
        self::assertCount(2, $statement['rows']);
        self::assertSame(-2590, $statement['rows'][0]['amount_minor']);
        self::assertSame(15000, $statement['rows'][1]['amount_minor']);
        self::assertSame('Pagamento de energia', $statement['rows'][0]['description']);
    }

    #[DataProvider('invalidStatements')]
    public function testRejectsInvalidStatement(string $statement): void {
        $this->expectException(DomainException::class);
        OfxStatementParser::parse($statement);
    }

    public function testParsesStatementPeriodAndDistinctBalances(): void {
        $source = str_replace('<DTSTART>20261120', '<DTSTART>20261101<DTEND>20261130', self::SGML);
        $source = str_replace('</STMTRS>',
            '<LEDGERBAL><BALAMT>-1050.50<DTASOF>20261130</LEDGERBAL>'
            . '<AVAILBAL><BALAMT>5000.00<DTASOF>20261129</AVAILBAL></STMTRS>', $source);
        $statement = OfxStatementParser::parse($source);
        self::assertSame(['from' => '2026-11-01', 'through' => '2026-11-30'], $statement['statementPeriod']);
        self::assertSame(['date' => '2026-11-30', 'amount_minor' => -105050, 'currency' => 'BRL'], $statement['ledgerBalance']);
        self::assertSame(['date' => '2026-11-29', 'amount_minor' => 500000, 'currency' => 'BRL'], $statement['availableBalance']);
        self::assertSame(OfxStatementParser::parse(self::SGML)['rows'], $statement['rows']);
    }

    public function testMissingCheckpointsAndPartialPeriod(): void {
        $statement = OfxStatementParser::parse(self::SGML);
        self::assertSame(['from' => '2026-11-20', 'through' => null], $statement['statementPeriod']);
        self::assertNull($statement['ledgerBalance']);
        self::assertNull($statement['availableBalance']);
        self::assertNull(OfxStatementParser::parse(self::XML)['statementPeriod']);
    }

    public function testZeroXmlBalance(): void {
        $statement = OfxStatementParser::parse(str_replace('</STMTRS>',
            '<LEDGERBAL><BALAMT>0.00</BALAMT><DTASOF>20261122</DTASOF></LEDGERBAL></STMTRS>', self::XML));
        self::assertSame(['date' => '2026-11-22', 'amount_minor' => 0, 'currency' => 'BRL'], $statement['ledgerBalance']);
        self::assertNull($statement['availableBalance']);
        self::assertSame(105, $statement['rows'][0]['amount_minor']);
    }

    public static function invalidBalanceAndPeriodStatements(): array {
        $balance = '<LEDGERBAL><BALAMT>100.00<DTASOF>20261130</LEDGERBAL>';
        $withBalance = static fn (string $value): string => str_replace('</STMTRS>', $value . '</STMTRS>', self::SGML);
        return [
            'bad balance date' => [$withBalance(str_replace('20261130', '20260230', $balance)), 'invalid_ofx_date'],
            'bad balance time' => [$withBalance(str_replace('20261130', '20261130250000', $balance)), 'invalid_ofx_date'],
            'fractional cent balance' => [$withBalance(str_replace('100.00', '100.001', $balance)), 'invalid_ofx_amount'],
            'reversed period' => [str_replace('<DTSTART>20261120', '<DTSTART>20261130<DTEND>20261101', self::SGML), 'invalid_ofx_date'],
            'duplicate start date' => [str_replace('<DTSTART>20261120', '<DTSTART>20261120<DTSTART>20261121', self::SGML), 'invalid_ofx_row'],
            'duplicate ledger balance' => [$withBalance($balance . $balance), 'invalid_ofx_row'],
            'duplicate balance amount' => [$withBalance(str_replace('<BALAMT>100.00', '<BALAMT>100.00<BALAMT>200.00', $balance)), 'invalid_ofx_row'],
            'duplicate balance date' => [$withBalance(str_replace('<DTASOF>20261130', '<DTASOF>20261130<DTASOF>20261129', $balance)), 'invalid_ofx_row'],
            'unclosed aggregate' => [$withBalance(str_replace('</LEDGERBAL>', '', $balance)), 'invalid_ofx_row'],
            'nested balance' => [$withBalance('<UNKNOWN>' . $balance . '</UNKNOWN>'), 'invalid_ofx_row'],
            'orphan balance' => [str_replace('</OFX>', $balance . '</OFX>', self::SGML), 'invalid_ofx_row'],
            'transaction period spoof' => [str_replace('<FITID>ofx-id-one', '<DTEND>20261130<FITID>ofx-id-one', self::SGML), 'invalid_ofx_row'],
            'unsupported currency' => [str_replace('<CURDEF>BRL', '<CURDEF>ZZZ', $withBalance($balance)), 'unsupported_currency'],
            'non-two-decimal currency' => [str_replace('<CURDEF>BRL', '<CURDEF>JPY', $withBalance($balance)), 'invalid_ofx_currency'],
            'missing currency' => [str_replace('<CURDEF>BRL', '', $withBalance($balance)), 'invalid_ofx_currency'],
        ];
    }

    #[DataProvider('invalidBalanceAndPeriodStatements')]
    public function testRejectsInvalidBalanceAndPeriod(string $statement, string $error): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($error, '/') . '\z/');
        OfxStatementParser::parse($statement);
    }
}
