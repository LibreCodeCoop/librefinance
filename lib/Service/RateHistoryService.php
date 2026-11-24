<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\Domain\Currency\CurrencyMetadata;
use OCA\LibreFinance\Domain\Currency\RateQuote;
use OCP\DB\Exception as DatabaseException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append-only workspace rate evidence. Recording a quote never changes a
 * journal, account balance or the functional currency of the ledger.
 */
final class RateHistoryService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private AccessService $access,
    ) {}

    public function list(int $spaceId, int $afterId = 0): array {
        $this->access->requireFinancialCapability($spaceId, 'currency.rates.read');
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_fx_rates')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            'contractVersion' => 1,
            'metadataVersion' => CurrencyMetadata::VERSION,
            'supportedCurrencies' => CurrencyMetadata::supported(),
            'rates' => array_map([self::class, 'cast'], $rows),
            'nextAfterId' => $more ? (int)end($rows)['id'] : null,
        ];
    }

    public function record(int $spaceId, string $sourceCurrency, string $targetCurrency,
        mixed $rate, string $effectiveOn, string $sourceType, string $sourceRef): array {
        $this->access->requireFinancialCapability($spaceId, 'currency.rates.manage');
        if (!is_string($rate)) {
            throw new DomainException('invalid_exchange_rate');
        }
        $quote = new RateQuote($spaceId, $sourceCurrency, $targetCurrency,
            $rate, $effectiveOn, $sourceType, $sourceRef);
        $fingerprint = $quote->fingerprint();
        $actor = $this->access->uid();
        // A read before the first write inside a SQLite transaction can
        // cause a read-to-write lock upgrade race. A pre-read is only an
        // optimization: the unique constraint arbitrates concurrent inserts.
        $found = $this->find($spaceId, $fingerprint);
        if ($found !== null) {
            $this->access->requireFinancialCapability($spaceId, 'currency.rates.manage');
            return ['rate' => self::cast($found), 'replayed' => true];
        }
        $this->db->beginTransaction();
        try {
            $now = time();
            $this->writer->insert('lf_fx_rates', [
                'space_id' => $spaceId,
                'metadata_version' => CurrencyMetadata::VERSION,
                'source_currency' => $quote->sourceCurrency,
                'target_currency' => $quote->targetCurrency,
                'source_precision' => $quote->sourcePrecision,
                'target_precision' => $quote->targetPrecision,
                'rate' => $quote->rate,
                'effective_on' => $quote->effectiveOn,
                'source_type' => $quote->sourceType,
                'source_ref' => $quote->sourceRef,
                'fingerprint' => $fingerprint,
                'created_by' => $actor,
                'created_at' => $now,
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lf_fx_rates');
            $this->writer->insert('lf_audit', [
                'space_id' => $spaceId, 'actor_uid' => $actor,
                'action' => 'currency.rate_recorded', 'entity' => 'exchange_rate',
                'entity_id' => $id, 'occurred_at' => $now,
            ]);
            $row = $this->find($spaceId, $fingerprint);
            if ($row === null || (int)$row['id'] !== $id) {
                throw new \LogicException('Recorded exchange rate missing');
            }
            $this->access->requireFinancialCapability($spaceId, 'currency.rates.manage');
            $this->db->commit();
            return ['rate' => self::cast($row), 'replayed' => false];
        } catch (DatabaseException $e) {
            $this->db->rollBack();
            if ($e->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            $this->access->requireFinancialCapability($spaceId, 'currency.rates.manage');
            $found = $this->find($spaceId, $fingerprint);
            if ($found === null) {
                throw $e;
            }
            return ['rate' => self::cast($found), 'replayed' => true];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function find(int $spaceId, string $fingerprint): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_fx_rates')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('fingerprint', $q->createNamedParameter($fingerprint)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    private static function cast(array $row): array {
        return [
            'id' => (int)$row['id'],
            'metadataVersion' => (int)$row['metadata_version'],
            'sourceCurrency' => (string)$row['source_currency'],
            'targetCurrency' => (string)$row['target_currency'],
            'sourcePrecision' => (int)$row['source_precision'],
            'targetPrecision' => (int)$row['target_precision'],
            'rate' => (string)$row['rate'],
            'effectiveOn' => (string)$row['effective_on'],
            'sourceType' => (string)$row['source_type'],
            'sourceRef' => (string)$row['source_ref'],
            'createdBy' => (string)$row['created_by'],
            'createdAt' => (int)$row['created_at'],
            'fingerprint' => (string)$row['fingerprint'],
        ];
    }
}
