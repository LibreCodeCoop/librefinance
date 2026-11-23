<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\PublicAPI;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCA\LibreFinance\Service\SaasPolicyRuntimeGate;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Native banking ownership for imported inter-account transfers.
 *
 * A transfer is one paired, explicitly unresolved event. It is NOT two
 * bank statement rows, a payment or a balanced ledger journal. Its legs
 * must be linked to source statements before contributing to balances.
 */
final class BankTransferApi {
    private const MODULE = 'librefinance_banking';

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
        private SaasPolicyRuntimeGate $policies,
    ) {}

    /**
     * @param list<array{externalId:string,fromAccountId:int,toAccountId:int,
     *   bookingDate:string,amountMinor:int,currency:string,description:string}> $rows
     * @return array<string,array<string,int|string>>
     */
    public function inspect(int $spaceId, array $rows): array {
        $this->validate($spaceId, $rows);
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'external_id', 'from_account_id', 'to_account_id',
                'booking_date', 'amount_minor', 'currency', 'description', 'status')
            ->from('lfb_transfers')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('external_id', $q->createNamedParameter(
                array_column($rows, 'externalId'), IQueryBuilder::PARAM_STR_ARRAY)));
        $found = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $found[(string)$row['external_id']] = [
                'id' => (int)$row['id'],
                'fromAccountId' => (int)$row['from_account_id'],
                'toAccountId' => (int)$row['to_account_id'],
                'bookingDate' => (string)$row['booking_date'],
                'amountMinor' => (int)$row['amount_minor'],
                'currency' => (string)$row['currency'],
                'description' => (string)$row['description'],
                'status' => (string)$row['status'],
            ];
        }
        return $found;
    }

    /**
     * Caller MUST wrap these inserts and its source mapping records in one
     * transaction. A DB unique key prevents duplicate external events.
     *
     * @param list<array{externalId:string,fromAccountId:int,toAccountId:int,
     *   bookingDate:string,amountMinor:int,currency:string,description:string}> $rows
     * @return array<string,int>
     */
    public function appendPending(int $spaceId, array $rows): array {
        $this->validate($spaceId, $rows);
        $this->policies->requireWorkspaceEnabled($spaceId, 'imports.enabled');
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['fromAccountId'];
            $ids[] = $row['toAccountId'];
        }
        $this->finance->reserveBankAccountsForWrite($spaceId, self::MODULE, $ids);
        if ($this->inspect($spaceId, $rows) !== []) {
            throw new DomainException('transfer_import_collision');
        }
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        $created = [];
        foreach ($rows as $row) {
            $this->insert('lfb_transfers', [
                'space_id' => $spaceId,
                'external_id' => $row['externalId'],
                'from_account_id' => $row['fromAccountId'],
                'to_account_id' => $row['toAccountId'],
                'booking_date' => $row['bookingDate'],
                'amount_minor' => $row['amountMinor'],
                'currency' => $row['currency'],
                'description' => $row['description'],
                'status' => 'pending_source_match',
                'created_by' => $uid,
                'created_at' => time(),
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lfb_transfers');
            $this->insert('lfb_transfer_audit', [
                'space_id' => $spaceId,
                'transfer_id' => $id,
                'actor_uid' => $uid,
                'action' => 'bank_transfer.imported',
                'occurred_at' => time(),
            ]);
            $created[$row['externalId']] = $id;
        }
        return $created;
    }

    /** @return array<string,mixed> */
    public function listForSpace(int $spaceId, int $afterId = 0): array {
        $this->finance->requireModule($spaceId, self::MODULE);
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'from_account_id', 'to_account_id',
                'booking_date', 'amount_minor', 'currency', 'description', 'status')
            ->from('lfb_transfers')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $found = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($found) > 50;
        $rows = array_slice($found, 0, 50);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'fromAccountId' => (int)$row['from_account_id'],
                'toAccountId' => (int)$row['to_account_id'],
                'bookingDate' => (string)$row['booking_date'],
                'amountMinor' => (int)$row['amount_minor'],
                'currency' => (string)$row['currency'],
                'description' => (string)$row['description'],
                'status' => (string)$row['status'],
                'ledgerEntriesPosted' => 0,
                'statementLegsCreated' => 0,
            ];
        }
        return [
            'transfers' => $result,
            'nextAfterId' => $hasMore ? (int)end($rows)['id'] : null,
            'allTransfersReconciled' => false,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function validate(int $spaceId, array $rows): void {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if ($rows === [] || !array_is_list($rows) || count($rows) > 50) {
            throw new DomainException('invalid_transfer_batch');
        }
        $ids = [];
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['externalId'] ?? null)
                || preg_match('/^lfmt:[0-9a-f]{64}$/D', $row['externalId']) !== 1
                || !is_int($row['fromAccountId'] ?? null)
                || !is_int($row['toAccountId'] ?? null)
                || $row['fromAccountId'] <= 0 || $row['toAccountId'] <= 0
                || $row['fromAccountId'] === $row['toAccountId']
                || !is_int($row['amountMinor'] ?? null)
                || $row['amountMinor'] <= 0 || $row['amountMinor'] > 9000000000000
                || !is_string($row['currency'] ?? null)
                || preg_match('/^[A-Z]{3}$/D', $row['currency']) !== 1
                || !is_string($row['description'] ?? null)
                || trim($row['description']) !== $row['description']
                || $row['description'] === '' || mb_strlen($row['description']) > 255
                || !is_string($row['bookingDate'] ?? null)) {
                throw new DomainException('invalid_transfer');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['bookingDate']);
            if ($date === false || $date->format('Y-m-d') !== $row['bookingDate']
                || isset($keys[$row['externalId']])) {
                throw new DomainException('invalid_transfer');
            }
            $keys[$row['externalId']] = true;
            $ids[$row['fromAccountId']] = true;
            $ids[$row['toAccountId']] = true;
        }
        $currencies = $this->finance->requireBankImportAccounts(
            $spaceId, self::MODULE, array_keys($ids));
        foreach ($rows as $row) {
            if ($currencies[$row['fromAccountId']] !== $row['currency']
                || $currencies[$row['toAccountId']] !== $row['currency']) {
                throw new DomainException('transfer_currency_mismatch');
            }
        }
    }

    /** @param array<string,int|string|null> $values */
    private function insert(string $table, array $values): void {
        $q = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $key => $value) {
            $params[$key] = $q->createNamedParameter($value,
                is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
        }
        $q->insert($table)->values($params)->executeStatement();
    }
}
