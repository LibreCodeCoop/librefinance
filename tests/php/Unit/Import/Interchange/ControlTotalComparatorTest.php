<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Import\Interchange;

use DomainException;
use OCA\LibreFinance\Import\Interchange\ControlTotalComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControlTotalComparatorTest extends TestCase {
    private string $cutoff;
    private array $cash;
    private array $otherCash;
    private string $closing;
    private string $opening;
    private array $plan;
    private array $source;
    private array $destination;
    private ControlTotalComparator $comparator;

    protected function setUp(): void {
        parent::setUp();
        $this->cutoff = '2025-02-01T00:00:00Z';
        $this->cash = ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'BRL', 'scale' => 2];
        $this->otherCash = ['sourceWorkspaceId' => 'book-b', 'destinationWorkspaceId' => 'ledger-b', 'currency' => 'BRL', 'scale' => 2];
        $this->closing = 'bank_closing_balance';
        $this->opening = 'bank_opening_balance';
        $this->plan = [
            $this->cash + ['requiredControls' => [$this->opening, $this->closing]],
            $this->otherCash + ['requiredControls' => [$this->closing]],
            ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'JPY',
                'scale' => 0, 'requiredControls' => [$this->closing]],
            ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'KWD',
                'scale' => 3, 'requiredControls' => ['obligation_outstanding', 'settlement_net']],
        ];

        $this->source = ['cutoff' => $this->cutoff, 'scopes' => [
            $this->scope('book-a', 'BRL', 2, [$this->control($this->opening, '0'), $this->control($this->closing, '-125')]),
            $this->scope('book-b', 'BRL', 2, [$this->control($this->closing, '125')]),
            $this->scope('book-a', 'JPY', 0, [$this->control($this->closing, '125')]),
            $this->scope('book-a', 'KWD', 3, [
                $this->control('obligation_outstanding', '12345678901234567890123456789012345678'),
                $this->control('settlement_net', '-125'),
            ]),
        ]];
        $this->destination = ['cutoff' => $this->cutoff, 'scopes' => [
            $this->scope('ledger-b', 'BRL', 2, [$this->control($this->closing, '125')]),
            $this->scope('ledger-a', 'KWD', 3, [
                $this->control('settlement_net', '-125'),
                $this->control('obligation_outstanding', '12345678901234567890123456789012345678'),
            ]),
            $this->scope('ledger-a', 'JPY', 0, [$this->control($this->closing, '125')]),
            $this->scope('ledger-a', 'BRL', 2, [$this->control($this->closing, '-125'), $this->control($this->opening, '0')]),
        ]];
        $this->comparator = new ControlTotalComparator();
    }

    private function scope(string $workspaceId, string $currency, int $scale, array $controls): array {
        return ['workspaceId' => $workspaceId, 'currency' => $currency, 'scale' => $scale, 'controls' => $controls];
    }

    private function control(string $name, string $minor): array {
        return ['name' => $name, 'minor' => $minor];
    }

    private function compare(array $left, array $right): array {
        return $this->comparator->compare($this->cutoff, $this->plan, $left, $right);
    }

    public function testOrderIndependentMatch(): void {
        $result = $this->compare($this->source, $this->destination);
        self::assertSame(['status' => 'matched', 'diagnostics' => []], $result);
    }

    public function testMissingRecordDiffersFromZero(): void {
        $missing = $this->destination;
        array_pop($missing['scopes'][3]['controls']);
        $result = $this->compare($this->source, $missing);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'missing_control', 'side' => 'destination', 'scope' => $this->cash, 'control' => $this->opening],
            ],
        ], $result);
    }

    public function testEqualRemainingValuesCannotHideMissingControlBothSides(): void {
        $missing = $this->destination;
        array_pop($missing['scopes'][3]['controls']);
        $alsoMissing = $this->source;
        array_shift($alsoMissing['scopes'][0]['controls']);
        $result = $this->compare($alsoMissing, $missing);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'missing_control', 'side' => 'source', 'scope' => $this->cash, 'control' => $this->opening],
                ['code' => 'missing_control', 'side' => 'destination', 'scope' => $this->cash, 'control' => $this->opening],
            ],
        ], $result);
    }

    public function testAgreeingOnRemainingBookCannotHideOmittedBookBothSides(): void {
        $missing = $this->destination;
        array_shift($missing['scopes']);
        $alsoMissing = $this->source;
        array_splice($alsoMissing['scopes'], 1, 1);
        $result = $this->compare($alsoMissing, $missing);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'missing_scope', 'side' => 'source', 'scope' => $this->otherCash],
                ['code' => 'missing_scope', 'side' => 'destination', 'scope' => $this->otherCash],
            ],
        ], $result);
    }

    public function testOffsettingDifferencesSeparateBooks(): void {
        $changed = $this->destination;
        $changed['scopes'][0]['controls'][0]['minor'] = '-125';
        $changed['scopes'][3]['controls'][0]['minor'] = '125';
        $changeA = ['code' => 'changed_control', 'scope' => $this->cash, 'control' => $this->closing];
        $changeB = ['code' => 'changed_control', 'scope' => $this->otherCash, 'control' => $this->closing];
        $result = $this->compare($this->source, $changed);
        self::assertSame([
            'status' => 'different',
            'diagnostics' => [$changeA, $changeB],
        ], $result);

        array_pop($changed['scopes'][3]['controls']);
        $resultIncomplete = $this->compare($this->source, $changed);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'missing_control', 'side' => 'destination', 'scope' => $this->cash, 'control' => $this->opening],
                $changeA,
                $changeB,
            ],
        ], $resultIncomplete);
    }

    public function testOneMinorUnitDifferenceAt38DigitsExact(): void {
        $changed = $this->destination;
        $changed['scopes'][1]['controls'][1]['minor'] = '12345678901234567890123456789012345679';
        $result = $this->compare($this->source, $changed);
        self::assertSame('different', $result['status']);
        self::assertSame([
            [
                'code' => 'changed_control',
                'scope' => [
                    'sourceWorkspaceId' => 'book-a',
                    'destinationWorkspaceId' => 'ledger-a',
                    'currency' => 'KWD',
                    'scale' => 3,
                ],
                'control' => 'obligation_outstanding',
            ],
        ], $result['diagnostics']);
    }

    public function testOffsettingMinorUnitsBrlJpySeparateDifferences(): void {
        $changed = $this->destination;
        $changed['scopes'][2]['controls'][0]['minor'] = '124';
        $changed['scopes'][3]['controls'][0]['minor'] = '-124';
        $changeA = ['code' => 'changed_control', 'scope' => $this->cash, 'control' => $this->closing];
        $result = $this->compare($this->source, $changed);
        self::assertSame([
            'status' => 'different',
            'diagnostics' => [
                $changeA,
                [
                    'code' => 'changed_control',
                    'scope' => array_replace($this->cash, ['currency' => 'JPY', 'scale' => 0]),
                    'control' => $this->closing,
                ],
            ],
        ], $result);
    }

    #[DataProvider('fieldMismatchProvider')]
    public function testFieldMismatchCannotBeInferred(string $field, mixed $value): void {
        $changed = $this->destination;
        $changed['scopes'][3][$field] = $value;
        $result = $this->compare($this->source, $changed);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'missing_scope', 'side' => 'destination', 'scope' => $this->cash],
                [
                    'code' => 'unexpected_scope',
                    'side' => 'destination',
                    'scope' => array_replace(['workspaceId' => 'ledger-a', 'currency' => 'BRL', 'scale' => 2], [$field => $value]),
                ],
            ],
        ], $result);
    }

    public static function fieldMismatchProvider(): array {
        return [
            'currency' => ['currency', 'USD'],
            'scale' => ['scale', 3],
            'workspaceId' => ['workspaceId', 'book-a'],
        ];
    }

    public function testCutoffMismatch(): void {
        $laterCutoff = '2025-02-02T00:00:00Z';
        $changed = $this->destination;
        $changed['cutoff'] = $laterCutoff;
        $cutoffDiagnostic = [
            'code' => 'cutoff_mismatch',
            'side' => 'destination',
            'expectedCutoff' => $this->cutoff,
            'actualCutoff' => $laterCutoff,
        ];
        $result = $this->compare($this->source, $changed);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [$cutoffDiagnostic],
        ], $result);

        $laterSource = array_replace($this->source, ['cutoff' => $laterCutoff]);
        $resultBoth = $this->comparator->compare($this->cutoff, $this->plan, $laterSource, $changed);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                array_replace($cutoffDiagnostic, ['side' => 'source']),
                $cutoffDiagnostic,
            ],
        ], $resultBoth);
    }

    public function testDuplicateScopeOrControl(): void {
        $duplicate = $this->destination;
        $duplicate['scopes'][] = $duplicate['scopes'][3];
        $result = $this->compare($this->source, $duplicate);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'duplicate_scope', 'side' => 'destination', 'scope' => $this->cash],
            ],
        ], $result);

        $duplicateControl = $this->destination;
        $duplicateControl['scopes'][3]['controls'][] = $this->control($this->closing, '600');
        $resultControl = $this->compare($this->source, $duplicateControl);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => 'duplicate_control', 'side' => 'destination', 'scope' => $this->cash, 'control' => $this->closing],
            ],
        ], $resultControl);
    }

    #[DataProvider('unsupportedOrUnexpectedControlProvider')]
    public function testUnsupportedOrUnexpectedControl(string $name, string $code): void {
        $expanded = $this->destination;
        $expanded['scopes'][3]['controls'][] = $this->control($name, '0');
        $result = $this->compare($this->source, $expanded);
        self::assertSame([
            'status' => 'unverified',
            'diagnostics' => [
                ['code' => $code, 'side' => 'destination', 'scope' => $this->cash, 'control' => $name],
            ],
        ], $result);
    }

    public static function unsupportedOrUnexpectedControlProvider(): array {
        return [
            'unsupported' => ['fiscal_total', 'unsupported_control'],
            'unexpected' => ['ledger_debit_total', 'unexpected_control'],
        ];
    }

    public function testEmptyPlanFails(): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Aempty_control_comparison_scope\z/');
        $this->comparator->compare($this->cutoff, [], $this->source, $this->destination);
    }

    #[DataProvider('invalidPlanProvider')]
    public function testInvalidPlanFails(string $code, array $requiredControls): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($code, '/') . '\z/');
        $this->comparator->compare(
            $this->cutoff,
            [$this->cash + ['requiredControls' => $requiredControls]],
            $this->source,
            $this->destination
        );
    }

    public static function invalidPlanProvider(): array {
        return [
            'empty_required_controls' => ['empty_required_controls', []],
            'duplicate_required_control' => ['duplicate_required_control', ['bank_closing_balance', 'bank_closing_balance']],
            'unsupported_required_control' => ['unsupported_required_control', ['fiscal_total']],
        ];
    }

    public function testAmbiguousPlanFails(): void {
        $ambiguousPlan = $this->plan;
        $ambiguousPlan[1]['destinationWorkspaceId'] = 'ledger-a';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Aduplicate_required_control_scope\z/');
        $this->comparator->compare($this->cutoff, $ambiguousPlan, $this->source, $this->destination);
    }

    #[DataProvider('invalidMinorUnitsProvider')]
    public function testInvalidMinorUnitsFail(mixed $minor): void {
        $invalid = $this->destination;
        $invalid['scopes'][3]['controls'][0]['minor'] = $minor;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_control_minor_units\z/');
        $this->compare($this->source, $invalid);
    }

    public static function invalidMinorUnitsProvider(): array {
        return [
            [0],
            [0.25],
            ['-0'],
            ['01'],
            ['+1'],
            ['1.0'],
            ['1e3'],
            [str_repeat('9', 39)],
        ];
    }

    public function testInvalidCalendarCutoffFails(): void {
        $invalid = array_replace($this->destination, ['cutoff' => '2025-02-30T00:00:00Z']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_control_cutoff\z/');
        $this->compare($this->source, $invalid);
    }

    public function testOversizedScopeListFails(): void {
        $oversized = array_replace($this->destination, ['scopes' => array_fill(0, 257, $this->destination['scopes'][0])]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_control_comparison_list\z/');
        $this->compare($this->source, $oversized);
    }

    public function testOversizedControlListFails(): void {
        $oversized = $this->destination;
        $oversized['scopes'][0]['controls'] = array_fill(0, 33, $this->control($this->closing, '1'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_control_comparison_list\z/');
        $this->compare($this->source, $oversized);
    }
}
