<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\PublicAPI;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Cross-app boundary for historical statement imports.
 * It never posts journals or authorizes financial operations.
 */
final class BankImportApi {
    private const MODULE = 'librefinance_banking';

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
    ) {}

    /**
     * @param list<array{externalId:string,accountId:int,bookingDate:string,amountMinor:int,description:string,currency:string}> $entries
     * @return array<string,array{id:int,accountId:int,bookingDate:string,amountMinor:int,description:string}>
     */
    public function inspect(int $spaceId, array $entries): array {
        $this->validate($spaceId, $entries);
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'external_id', 'bank_account_id', 'booking_date',
                'amount_minor', 'description')
            ->from('lfb_transactions')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('external_id',
                $q->createNamedParameter(array_column($entries, 'externalId'),
                    IQueryBuilder::PARAM_STR_ARRAY)));
        $found = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $found[(string)$row['external_id']] = [
                'id' => (int)$row['id'],
                'accountId' => (int)$row['bank_account_id'],
                'bookingDate' => (string)$row['booking_date'],
                'amountMinor' => (int)$row['amount_minor'],
                'description' => (string)$row['description'],
            ];
        }
        return $found;
    }

    /**
     * Caller MUST commit the banking rows and source identity mappings in
     * the same database transaction. Unique keys protect concurrent imports.
     * @param list<array{externalId:string,accountId:int,bookingDate:string,amountMinor:int,description:string,currency:string}> $entries
     * @return array<string,int> external ID => banking transaction ID
     */
    public function appendPending(int $spaceId, array $entries): array {
        $this->validate($spaceId, $entries);
        $this->finance->reserveBankAccountsForWrite($spaceId, self::MODULE,
            array_column($entries, 'accountId'));
        if ($this->inspect($spaceId, $entries) !== []) {
            throw new DomainException('import_bank_entry_collision');
        }
        $user = $this->session->getUser()?->getUID();
        if ($user === null || $user === '') {
            throw new DomainException('authentication_required');
        }
        $created = [];
        foreach ($entries as $entry) {
            $this->insert('lfb_transactions', [
                'space_id' => $spaceId,
                'bank_account_id' => $entry['accountId'],
                'external_id' => $entry['externalId'],
                'booking_date' => $entry['bookingDate'],
                'amount_minor' => $entry['amountMinor'],
                'description' => $entry['description'],
                'status' => 'pending',
                'matched_journal_id' => null,
                'created_by' => $user,
                'created_at' => time(),
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lfb_transactions');
            $this->insert('lfb_audit', [
                'space_id' => $spaceId,
                'transaction_id' => $id,
                'actor_uid' => $user,
                'action' => 'bank_transaction.imported',
                'occurred_at' => time(),
            ]);
            $created[$entry['externalId']] = $id;
        }
        return $created;
    }

    /** @param list<array<string,mixed>> $entries */
    private function validate(int $spaceId, array $entries): void {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if ($entries === [] || count($entries) > 100 || !array_is_list($entries)) {
            throw new DomainException('invalid_import_batch');
        }
        $unique = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !is_string($entry['externalId'] ?? null)
                || preg_match('/^lfm:[0-9a-f]{64}$/D', $entry['externalId']) !== 1
                || !is_int($entry['accountId'] ?? null) || $entry['accountId'] < 1
                || !is_int($entry['amountMinor'] ?? null)
                || $entry['amountMinor'] === 0
                || abs($entry['amountMinor']) > 9000000000000
                || !is_string($entry['bookingDate'] ?? null)
                || !is_string($entry['description'] ?? null)
                || trim($entry['description']) !== $entry['description']
                || $entry['description'] === '' || mb_strlen($entry['description']) > 255
                || !is_string($entry['currency'] ?? null)) {
                throw new DomainException('invalid_import_transaction');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $entry['bookingDate']);
            if ($date === false || $date->format('Y-m-d') !== $entry['bookingDate']
                || isset($unique[$entry['externalId']])) {
                throw new DomainException('invalid_import_transaction');
            }
            $unique[$entry['externalId']] = true;
        }
        $accounts = $this->finance->requireBankImportAccounts($spaceId, self::MODULE,
            array_column($entries, 'accountId'));
        foreach ($entries as $entry) {
            if ($accounts[$entry['accountId']] !== $entry['currency']) {
                throw new DomainException('import_currency_mismatch');
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
