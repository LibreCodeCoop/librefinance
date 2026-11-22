<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use OCA\LibreFinance\Import\Source\SourceSystem;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only diagnostics for historical bank import coverage per source account.
 *
 * A matching arithmetic snapshot is NOT a balance reconciliation: complete
 * source inventory, transfers, posting categories and source cutover dates
 * must be independently verified before the finance ledger can be certified.
 */
final class MovementReconciliationService {
    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
    ) {}

    /** @return array<string,mixed> */
    public function list(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic', int $afterId = 0): array {
        $this->finance->requireModule($spaceId, 'librefinance_import', 'manager');
        $this->finance->requireModule($spaceId, 'librefinance_banking', 'manager');
        if (!SourceSystem::valid($sourceSystem)
            || !is_string($sourceCompanyId) || strlen($sourceCompanyId) > 64
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $sourceCompanyId) !== 1
            || $afterId < 0) {
            throw new DomainException('invalid_import_source');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('m.id', 'm.source_record_id', 'm.target_id',
                's.currency', 's.opening_balance_minor',
                's.current_balance_minor', 's.balance_as_of')
            ->from('lfm_records', 'm')
            ->innerJoin('m', 'lfm_liquid_origins', 's',
                's.target_account_id = m.target_id AND s.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('account')))
            ->andWhere($q->expr()->gt('m.id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.id', 'ASC')->setMaxResults(51);
        $found = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($found) > 50;
        $found = array_slice($found, 0, 50);
        $accountIds = array_map(static fn(array $row): int =>
            (int)$row['target_id'], $found);
        $net = [];
        $counts = [];
        if ($accountIds !== []) {
            $q = $this->db->getQueryBuilder();
            $q->select('t.bank_account_id', 't.status')
                ->selectAlias($q->createFunction('COUNT(t.id)'), 'total')
                ->selectAlias($q->createFunction('SUM(t.amount_minor)'), 'net_minor')
                ->from('lfm_records', 'm')
                ->innerJoin('m', 'lfb_transactions', 't',
                    't.id = m.target_id AND t.space_id = m.space_id')
                ->where($q->expr()->eq('m.space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('m.source_system',
                    $q->createNamedParameter($sourceSystem)))
                ->andWhere($q->expr()->eq('m.source_company_id',
                    $q->createNamedParameter($sourceCompanyId)))
                ->andWhere($q->expr()->eq('m.record_kind',
                    $q->createNamedParameter('transaction')))
                ->andWhere($q->expr()->in('t.bank_account_id',
                    $q->createNamedParameter($accountIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('t.bank_account_id')->addGroupBy('t.status');
            foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
                $id = (int)$row['bank_account_id'];
                $net[$id] = ($net[$id] ?? 0) + (int)$row['net_minor'];
                $counts[$id][$row['status']] = (int)$row['total'];
            }
        }
        $result = [];
        foreach ($found as $row) {
            $id = (int)$row['target_id'];
            $opening = (int)$row['opening_balance_minor'];
            $current = (int)$row['current_balance_minor'];
            $movement = $net[$id] ?? 0;
            $reconstructed = $opening + $movement;
            $result[] = [
                'accountSourceId' => $row['source_record_id'],
                'targetAccountId' => $id,
                'currency' => $row['currency'],
                'balanceAsOf' => $row['balance_as_of'],
                'sourceOpeningBalanceMinor' => $opening,
                'importedMovementNetMinor' => $movement,
                'importedMovementCount' =>
                    ($counts[$id]['pending'] ?? 0) + ($counts[$id]['matched'] ?? 0),
                'pendingMovementCount' => $counts[$id]['pending'] ?? 0,
                'matchedMovementCount' => $counts[$id]['matched'] ?? 0,
                'reconstructedFromImportedMinor' => $reconstructed,
                'sourceCurrentBalanceMinor' => $current,
                'unexplainedDifferenceMinor' => $current - $reconstructed,
                'historyComplete' => false,
                'balanceReconciled' => false,
            ];
        }
        return [
            'sourceSystem' => $sourceSystem,
            'sourceCompanyId' => $sourceCompanyId,
            'accounts' => $result,
            'nextAfterId' => $hasMore ? (int)end($found)['id'] : null,
            'sourceInventoryVerified' => false,
            'balanceReconciled' => false,
            'note' => 'Arithmetic of imported statements only. Transfers, source cutoff and all source transactions remain unverified.',
        ];
    }
}
