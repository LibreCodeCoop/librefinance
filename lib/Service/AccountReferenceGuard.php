<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use LogicException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Serializes account deletion/deactivation with the creation of new references
 * across independently installed finance modules.
 *
 * MUST be invoked inside the SAME DB transaction as the reference INSERT.
 * A conditional UPDATE acquires an exclusive account-row lock on PostgreSQL
 * and MariaDB and a SQLite writer lock. Even an unchanged boolean UPDATE
 * locks the matched row; rowCount is intentionally not used because MySQL
 * may count only changed values. An independent SELECT checks availability.
 *
 * Read-only validation is NOT a substitute for this reservation.
 */
final class AccountReferenceGuard {
    public function __construct(private IDBConnection $db) {}

    public function reserve(int $spaceId, int $accountId, bool $bankOnly = false): string {
        if (!$this->db->inTransaction()) {
            throw new LogicException('account_reference_guard_requires_transaction');
        }
        if ($spaceId < 1 || $accountId < 1) {
            throw new DomainException($bankOnly ? 'bank_account_not_found' : 'account_not_found');
        }

        $q = $this->db->getQueryBuilder();
        $q->update('lf_accounts')
            ->set('active', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('active', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        if ($bankOnly) {
            $q->andWhere($q->expr()->eq('is_bank', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        try {
            $q->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if (self::isRetryableAccountLock($e)) {
                // SQLite can fail to promote a stale read snapshot to writer
                // while another request deletes this account. Do not retry
                // inside that transaction: abort and let the caller retry.
                throw new DomainException('account_reference_busy', previous: $e);
            }
            throw $e;
        }

        $q = $this->db->getQueryBuilder();
        $q->select('currency', 'active', 'is_bank')->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        // SQLite does not support SELECT FOR UPDATE. Its UPDATE above already
        // obtained the only writer lock. PostgreSQL and MySQL/MariaDB must
        // use a current locking read, not a stale repeatable-read snapshot.
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            $q->forUpdate();
        }
        $account = $q->executeQuery()->fetchAssociative();
        if ($account === false || !(bool)$account['active']
            || ($bankOnly && !(bool)$account['is_bank'])) {
            throw new DomainException($bankOnly ? 'bank_account_not_found' : 'account_not_found');
        }
        return (string)$account['currency'];
    }

    /**
     * Restrict transient handling to recognized locking conditions; do not
     * convert arbitrary SQL errors, missing tables or authorization failures.
     */
    private static function isRetryableAccountLock(\OCP\DB\Exception $e): bool {
        if (in_array($e->getReason(), [
            \OCP\DB\Exception::REASON_DEADLOCK,
            \OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT,
        ], true)) {
            return true;
        }
        // SQLite lock upgrade in an existing read transaction is not
        // necessarily classified as retryable by DBAL/Nextcloud.
        return str_contains(strtolower($e->getMessage()), 'database is locked')
            && str_contains(strtoupper($e->getMessage()), 'SQLSTATE[HY000]');
    }
}
