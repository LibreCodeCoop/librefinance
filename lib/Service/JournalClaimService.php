<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Cross-module journal references are owned by the core and remain valid
 * while a module is disabled. Every call is inside the caller's transaction.
 * No module-specific class or table is referenced.
 */
final class JournalClaimService {
    public function __construct(
        private IDBConnection $db,
        private AccessService $access,
    ) {}

    /** Lock journal row on engines supporting SELECT FOR UPDATE. */
    private function journal(int $spaceId, int $journalId): array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'status', 'reversed_journal_id')->from('lf_journals')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        // SQLite obtains its write lock on insert; FOR UPDATE is unsupported.
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            $q->forUpdate();
        }
        $journal = $q->executeQuery()->fetchAssociative();
        if ($journal === false) {
            throw new DomainException('journal_not_found');
        }
        return $journal;
    }

    /**
     * A reference can be one-to-one per module and journal, while independent
     * modules may refer to the same journal (e.g. bank feed and invoice).
     */
    public function claim(int $spaceId, int $journalId, string $moduleId, string $reference): void {
        $this->access->requireRole($spaceId, 'operator');
        if (!preg_match('/^librefinance_[a-z][a-z0-9_]{2,40}$/', $moduleId)
            || !preg_match('/^[A-Za-z0-9_.:-]{4,64}$/', $reference)) {
            throw new DomainException('invalid_journal_claim');
        }
        $journal = $this->journal($spaceId, $journalId);
        if ($journal['status'] !== 'posted' || $journal['reversed_journal_id'] !== null) {
            throw new DomainException('journal_not_available');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lf_journals')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('reversed_journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        if ($q->executeQuery()->fetchOne() !== false) {
            throw new DomainException('journal_not_available');
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('lf_journal_claims')->values([
            'space_id' => $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT),
            'journal_id' => $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT),
            'module_id' => $q->createNamedParameter($moduleId),
            'reference' => $q->createNamedParameter($reference),
            'claimed_by' => $q->createNamedParameter($this->access->uid()),
            'claimed_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ]);
        try {
            $q->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('journal_already_claimed', previous: $e);
            }
            throw $e;
        }
    }

    public function assertReversible(int $spaceId, int $journalId): void {
        $this->access->requireRole($spaceId, 'manager');
        $this->journal($spaceId, $journalId);
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lf_journal_claims')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        if ($q->executeQuery()->fetchOne() !== false) {
            throw new DomainException('journal_claimed');
        }
    }
}
