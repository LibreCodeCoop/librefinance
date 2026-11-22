<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Search;

use OCA\LibreFinance\Service\AccessService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IInAppSearch;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Nextcloud Unified Search, without a third-party service or external indexing.
 * All query predicates include the currently authorized workspace IDs before
 * limiting the results. No raw tax IDs, amounts, notes or emails are exposed.
 */
final class FinanceSearchProvider implements IInAppSearch {
    public function __construct(
        private AccessService $access,
        private IDBConnection $db,
        private IURLGenerator $urls,
        private IL10N $l10n,
    ) {}

    public function getId(): string {
        return 'librefinance-records';
    }

    public function getName(): string {
        return $this->l10n->t('LibreFinance');
    }

    public function getOrder(string $route, array $routeParameters): ?int {
        return str_starts_with($route, 'librefinance.') ? -1 : 45;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $name = $this->getName();
        // The OCS search always belongs to the caller. Never consult a
        // different account's spaces through the current session.
        try {
            if ($this->access->uid() !== $user->getUID()) {
                return SearchResult::complete($name, []);
            }
            $spaces = $this->access->accessibleSpaces();
        } catch (\DomainException) {
            return SearchResult::complete($name, []);
        }
        $term = trim((string)($query->getFilter('term')?->get() ?? $query->getTerm()));
        if (mb_strlen($term) < 2 || mb_strlen($term) > 120 || $spaces === []) {
            return SearchResult::complete($name, []);
        }
        $term = mb_strtolower($term);
        // ILike is the public, cross-DB expression builder contract.
        $pattern = '%' . $this->db->escapeLikeParameter($term) . '%';
        $spaceMap = [];
        foreach ($spaces as $space) $spaceMap[(int)$space['id']] = $space;
        $ids = array_keys($spaceMap);

        // Optional obligations can be disabled in an otherwise valid area.
        $blocked = [];
        $flags = $this->db->getQueryBuilder();
        $flags->select('space_id')->from('lf_module_flags')
            ->where($flags->expr()->in('space_id',
                $flags->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($flags->expr()->eq('module_id',
                $flags->createNamedParameter('librefinance_obligations')))
            ->andWhere($flags->expr()->eq('enabled',
                $flags->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        foreach ($flags->executeQuery()->fetchFirstColumn() as $id) {
            $blocked[(int)$id] = true;
        }

        $results = [];
        $max = min(max(1, $query->getLimit()), 40);
        $specs = [
            ['lfob_obligations', 'description', 'finance', 'Compromisso'],
            ['lf_contacts', 'name', 'contacts', 'Contato'],
            ['lf_companies', 'legal_name', 'companies', 'Empresa'],
            ['lf_items', 'name', 'items', 'Produto ou serviço'],
        ];
        foreach ($specs as [$table, $column, $view, $label]) {
            $allowed = $view === 'finance'
                ? array_values(array_diff($ids, array_keys($blocked))) : $ids;
            if ($allowed === [] || count($results) >= $max) continue;

            // Every table is queried once with a SQL-enforced authorized set;
            // results never pass through a PHP-only ACL after pagination.
            $q = $this->db->getQueryBuilder();
            $q->select('id', 'space_id', $column)
                ->from($table)
                ->where($q->expr()->in('space_id',
                    $q->createNamedParameter($allowed, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($q->expr()->iLike($column,
                    $q->createNamedParameter($pattern)))
                ->orderBy('id', 'DESC')
                ->setMaxResults(min(12, $max - count($results)));
            foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
                $spaceId = (int)$row['space_id'];
                $space = $spaceMap[$spaceId];
                $url = $this->urls->linkToRoute('librefinance.page.index')
                    . '?' . http_build_query([
                        'view' => $view,
                        'spaceId' => $spaceId,
                        'search' => $term,
                    ], '', '&', PHP_QUERY_RFC3986);
                $results[] = new SearchResultEntry(
                    $this->urls->imagePath('librefinance', 'app.svg'),
                    (string)$row[$column], $label . ' · ' . (string)$space['name'],
                    $url, '');
            }
        }
        return SearchResult::complete($name, $results);
    }
}
