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
 * Category taxonomy independent from the chart of accounts.
 * Parent/child structure is immutable after creation to avoid cyclic races.
 * Child inherits accounting account only if its own account is NULL.
 */
final class CategoryService {
    public function __construct(private IDBConnection $db, private AccessService $access, private WriteService $write) {}

    public function list(int $spaceId, int $afterId = 0): array {
        $this->access->requireRole($spaceId);
        if ($afterId < 0) { throw new DomainException('invalid_cursor'); }
        $q = $this->db->getQueryBuilder();
        $q->select('id','code','name','kind','parent_id','account_id','active','color')
            ->from('lf_categories')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->setMaxResults(51);
        $rows=$q->executeQuery()->fetchAllAssociative();
        $hasMore=count($rows)>50;
        $rows=array_slice($rows,0,50);
        // Resolve ancestor chains in batches, not one query per category (no N+1).
        $byId=[];
        foreach ($rows as $r) { $byId[(int)$r['id']]=$r; }
        $frontier=array_values(array_filter(array_map(
            static fn(array $r):?int=>$r['parent_id']===null?null:(int)$r['parent_id'], $rows
        )));
        for ($depth=0; $frontier!==[] && $depth<48; ++$depth) {
            $needed=array_values(array_unique(array_filter($frontier,static fn(int $v):bool=>!isset($byId[$v]))));
            if ($needed===[]) {break;}
            $q=$this->db->getQueryBuilder();
            $q->select('id','parent_id','account_id')->from('lf_categories')
                ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->in('id',$q->createNamedParameter($needed,IQueryBuilder::PARAM_INT_ARRAY)));
            $ancestors=$q->executeQuery()->fetchAllAssociative();
            if (count($ancestors)!==count($needed)) {throw new DomainException('category_parent_not_found');}
            $frontier=[];
            foreach ($ancestors as $ancestor) {
                $byId[(int)$ancestor['id']]=$ancestor;
                if ($ancestor['parent_id']!==null) {$frontier[]=(int)$ancestor['parent_id'];}
            }
        }
        if ($frontier!==[]) {
            foreach ($frontier as $parentId) {
                if (!isset($byId[$parentId])) {throw new DomainException('category_depth_exceeded');}
            }
        }
        foreach ($rows as &$row) {
            $row=self::cast($row);
            $visited=[];
            $cursor=$row;
            $effective=null;
            for ($depth=0;$depth<48 && $cursor!==null;++$depth) {
                $cursorId=(int)$cursor['id'];
                if (isset($visited[$cursorId])) {throw new DomainException('category_cycle');}
                $visited[$cursorId]=true;
                if ($cursor['account_id']!==null) {$effective=(int)$cursor['account_id'];break;}
                $parentId=$cursor['parent_id'];
                $cursor=$parentId===null?null:($byId[(int)$parentId]??null);
                if ($parentId!==null && $cursor===null) {throw new DomainException('category_parent_not_found');}
            }
            if ($cursor!==null && $effective===null) {throw new DomainException('category_depth_exceeded');}
            $row['effective_account_id']=$effective;
        }
        unset($row);
        return ['categories'=>$rows,'nextAfterId'=>$hasMore?(int)end($rows)['id']:null];
    }

    /**
     * Resolve a category to its immutable account classification.
     * Historical journals retain the category ID; no mutable reparenting.
     */
    public function resolveForPosting(int $spaceId, int $categoryId): int {
        $this->access->requireRole($spaceId, 'operator');
        $category=$this->find($spaceId,$categoryId);
        if($category===null || !(bool)$category['active']){
            throw new DomainException('category_not_found');
        }
        $accountId=$this->effectiveAccount($spaceId,$category);
        if($accountId===null){throw new DomainException('category_account_unresolved');}
        $this->requireAccount($spaceId,$accountId,(string)$category['kind']);
        return $accountId;
    }

    public function create(int $spaceId, string $code, string $name, string $kind,
        ?int $parentId = null, ?int $accountId = null, string $color = '#64748B'): array {
        $this->access->requireRole($spaceId,'manager');
        $code=strtoupper(trim($code));
        $name=trim($name);
        if (!preg_match('/^[A-Z0-9_-]{2,40}$/',$code)) { throw new DomainException('invalid_category_code'); }
        if ($name==='' || mb_strlen($name)>150) { throw new DomainException('invalid_category_name'); }
        if (!in_array($kind,['income','expense'],true)) { throw new DomainException('invalid_category_kind'); }
        if ($parentId !== null) {
            $parent=$this->find($spaceId,$parentId);
            if ($parent===null) {throw new DomainException('category_parent_not_found');}
            if ($parent['kind']!==$kind || !(bool)$parent['active']) {throw new DomainException('category_parent_incompatible');}
            $this->validateAncestors($spaceId,$parent);
        }
        if ($accountId!==null) {$this->requireAccount($spaceId,$accountId,$kind);}
        $color = self::validColor($color);
        $this->db->beginTransaction();
        try {
            $q=$this->db->getQueryBuilder();
            $q->insert('lf_categories')->values([
                'space_id'=>$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT),
                'code'=>$q->createNamedParameter($code),
                'name'=>$q->createNamedParameter($name),
                'kind'=>$q->createNamedParameter($kind),
                'parent_id'=>$q->createNamedParameter($parentId,IQueryBuilder::PARAM_INT),
                'account_id'=>$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT),
                'active'=>$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),
                'color'=>$q->createNamedParameter($color),
                'created_at'=>$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $id=(int)$this->db->lastInsertId('*PREFIX*lf_categories');
            $this->db->commit();
            return ['id'=>$id,'code'=>$code,'name'=>$name,'kind'=>$kind,'parent_id'=>$parentId,
                'account_id'=>$accountId,'effective_account_id'=>$accountId??($parentId!==null?$this->resolveParent($spaceId,$parentId):null),
                'active'=>true,'color'=>$color];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception && $e->getReason()===\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('category_code_conflict',previous:$e);
            }
            throw $e;
        }
    }

    public function update(int $spaceId, int $categoryId, string $name,
        string $color, bool $active = true): array {
        $this->access->requireRole($spaceId, 'manager');
        $name = trim($name);
        $color = self::validColor($color);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_category_name');
        }
        $this->db->beginTransaction();
        try {
            $existing = $this->find($spaceId, $categoryId);
            if ($existing === null) { throw new DomainException('category_not_found'); }
            // Never change the posting classification or parent of a category.
            $this->write->update('lf_categories', ['name' => $name, 'color' => $color,
                'active' => $active ? 1 : 0], ['space_id' => $spaceId, 'id' => $categoryId]);
            $this->write->insert('lf_audit', ['space_id' => $spaceId, 'actor_uid' => $this->access->uid(),
                'action' => 'category.updated', 'entity' => 'category',
                'entity_id' => $categoryId, 'occurred_at' => time()]);
            $this->db->commit();
            return ['id' => $categoryId, 'name' => $name, 'color' => $color, 'active' => $active];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private static function validColor(string $color): string {
        $color = strtoupper(trim($color));
        if (!preg_match('/^#[0-9A-F]{6}$/D', $color)) {
            throw new DomainException('invalid_category_color');
        }
        return $color;
    }

    private function requireAccount(int $spaceId, int $accountId, string $kind): void {
        $q=$this->db->getQueryBuilder();
        $q->select('class','active')->from('lf_accounts')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)));
        $row=$q->executeQuery()->fetchAssociative();
        if (!$row || !(bool)$row['active'] || $row['class']!==($kind==='income'?'revenue':'expense')) {
            throw new DomainException('category_account_incompatible');
        }
    }

    private function find(int $spaceId, int $categoryId): ?array {
        $q=$this->db->getQueryBuilder();
        $q->select('id','kind','parent_id','account_id','active')->from('lf_categories')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($categoryId,IQueryBuilder::PARAM_INT)));
        return $q->executeQuery()->fetchAssociative() ?: null;
    }

    private function validateAncestors(int $spaceId,array $category): void {
        $visited=[];
        for ($step=0;$step<48 && $category!==null;++$step) {
            $id=(int)$category['id'];
            if (isset($visited[$id])) {throw new DomainException('category_cycle');}
            $visited[$id]=true;
            $parent=$category['parent_id'];
            $category=$parent===null?null:$this->find($spaceId,(int)$parent);
            if ($parent!==null && $category===null) {throw new DomainException('category_parent_not_found');}
        }
        if ($category!==null) {throw new DomainException('category_depth_exceeded');}
    }

    private function effectiveAccount(int $spaceId,array $category): ?int {
        if ($category['account_id']!==null) {return (int)$category['account_id'];}
        return $category['parent_id']===null?null:$this->resolveParent($spaceId,(int)$category['parent_id']);
    }

    private function resolveParent(int $spaceId,int $parentId): ?int {
        $visited=[];
        for ($step=0;$step<48;++$step) {
            if (isset($visited[$parentId])) {throw new DomainException('category_cycle');}
            $visited[$parentId]=true;
            $parent=$this->find($spaceId,$parentId);
            if (!$parent) {throw new DomainException('category_parent_not_found');}
            if ($parent['account_id']!==null) {return (int)$parent['account_id'];}
            if ($parent['parent_id']===null) {return null;}
            $parentId=(int)$parent['parent_id'];
        }
        throw new DomainException('category_depth_exceeded');
    }

    private static function cast(array $row):array {
        $row['id']=(int)$row['id'];
        $row['parent_id']=$row['parent_id']!==null?(int)$row['parent_id']:null;
        $row['account_id']=$row['account_id']!==null?(int)$row['account_id']:null;
        $row['active']=(bool)$row['active'];
        return $row;
    }
}
