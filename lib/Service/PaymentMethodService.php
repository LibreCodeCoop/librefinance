<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Manual payment method registry. No secrets or automatic provider connections.
 * Ledger usage remains immutable; historical methods can be disabled.
 */
final class PaymentMethodService {
    private const KINDS = ['cash','bank_transfer','credit_card','debit_card',
        'pix','digital_wallet','other'];

    public function __construct(private IDBConnection $db,
        private AccessService $access, private WriteService $writer) {}

    public function list(int $spaceId, int $afterId = 0): array {
        $this->access->requireRole($spaceId, 'viewer');
        if ($afterId < 0) throw new DomainException('invalid_cursor');
        $q=$this->db->getQueryBuilder();
        $q->select('id','name','kind','bank_account_id','active','created_at','updated_at')
            ->from('lf_payment_methods')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id',$q->createNamedParameter($afterId,IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->setMaxResults(51);
        $rows=$q->executeQuery()->fetchAllAssociative();
        $more=count($rows)>50;
        $rows=array_slice($rows,0,50);
        return ['methods'=>array_map([self::class,'cast'],$rows),
            'nextAfterId'=>$more?(int)end($rows)['id']:null,
            'supportedKinds'=>self::KINDS,
            'automaticProvidersConnected'=>false];
    }

    public function create(int $spaceId, string $name, string $kind,
        ?int $bankAccountId=null): array {
        $this->access->requireRole($spaceId, 'manager');
        [$name,$kind]=self::validate($name,$kind);
        $this->requireBank($spaceId,$bankAccountId);
        $now=time();
        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_payment_methods',[
                'space_id'=>$spaceId,'name'=>$name,'kind'=>$kind,
                'bank_account_id'=>$bankAccountId,'active'=>true,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            $id=(int)$this->db->lastInsertId('*PREFIX*lf_payment_methods');
            $this->audit($spaceId,$id,'payment_method.created');
            $this->db->commit();
            return ['id'=>$id,'name'=>$name,'kind'=>$kind,
                'bank_account_id'=>$bankAccountId,'active'=>true];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function update(int $spaceId,int $methodId,string $name,
        ?int $bankAccountId=null,bool $active=true): array {
        $this->access->requireRole($spaceId,'manager');
        $old=$this->find($spaceId,$methodId);
        if ($old===null) throw new DomainException('payment_method_not_found');
        [$name]=self::validate($name,(string)$old['kind']);
        $this->requireBank($spaceId,$bankAccountId);
        if ($bankAccountId !== $old['bank_account_id'] && $this->used($spaceId,$methodId)) {
            throw new DomainException('payment_method_in_use');
        }
        $this->db->beginTransaction();
        try {
            $this->writer->update('lf_payment_methods',[
                'name'=>$name,'bank_account_id'=>$bankAccountId,
                'active'=>$active,'updated_at'=>time(),
            ],['space_id'=>$spaceId,'id'=>$methodId]);
            $this->audit($spaceId,$methodId,'payment_method.updated');
            $this->db->commit();
            return ['id'=>$methodId,'name'=>$name,'kind'=>$old['kind'],
                'bank_account_id'=>$bankAccountId,'active'=>$active];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function delete(int $spaceId,int $methodId): array {
        $this->access->requireRole($spaceId,'manager');
        $this->db->beginTransaction();
        try {
            $q=$this->db->getQueryBuilder();
            $q->update('lf_payment_methods')
                ->set('active',$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL))
                ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id',$q->createNamedParameter($methodId,IQueryBuilder::PARAM_INT)));
            if ($q->executeStatement() !== 1) throw new DomainException('payment_method_not_found');
            if ($this->used($spaceId,$methodId)) throw new DomainException('payment_method_in_use');
            $q=$this->db->getQueryBuilder();
            $q->delete('lf_payment_methods')
                ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id',$q->createNamedParameter($methodId,IQueryBuilder::PARAM_INT)));
            $q->executeStatement();
            $this->audit($spaceId,$methodId,'payment_method.deleted');
            $this->db->commit();
            return ['id'=>$methodId,'deleted'=>true];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function validateForJournal(int $spaceId,int $methodId,int $debitId,int $creditId): void {
        $this->access->requireRole($spaceId,'operator');
        $method=$this->find($spaceId,$methodId);
        if ($method===null || !$method['active']) throw new DomainException('payment_method_not_found');
        $account=$method['bank_account_id'];
        if ($account!==null && $account!==$debitId && $account!==$creditId) {
            throw new DomainException('payment_method_account_mismatch');
        }
    }

    private function find(int $spaceId,int $methodId): ?array {
        $q=$this->db->getQueryBuilder();
        $q->select('id','name','kind','bank_account_id','active','created_at','updated_at')
            ->from('lf_payment_methods')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($methodId,IQueryBuilder::PARAM_INT)));
        $row=$q->executeQuery()->fetchAssociative();
        return $row===false?null:self::cast($row);
    }

    private function used(int $spaceId,int $methodId): bool {
        $q=$this->db->getQueryBuilder();
        $q->select('id')->from('lf_journals')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('payment_method_id',
                $q->createNamedParameter($methodId,IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        return $q->executeQuery()->fetchOne()!==false;
    }

    private function requireBank(int $spaceId,?int $accountId): void {
        if ($accountId===null) return;
        if ($accountId<1) throw new DomainException('invalid_payment_account');
        $q=$this->db->getQueryBuilder();
        $q->select('id')->from('lf_accounts')
            ->where($q->expr()->eq('id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('is_bank',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)))
            ->andWhere($q->expr()->eq('active',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)));
        if ($q->executeQuery()->fetchOne()===false) throw new DomainException('invalid_payment_account');
    }

    private static function validate(string $name,string $kind): array {
        $name=trim($name);
        if ($name==='' || mb_strlen($name)>120 || !in_array($kind,self::KINDS,true)) {
            throw new DomainException('invalid_payment_method');
        }
        return [$name,$kind];
    }

    private static function cast(array $row): array {
        $row['id']=(int)$row['id'];
        $row['bank_account_id']=$row['bank_account_id']===null?null:(int)$row['bank_account_id'];
        $row['active']=(bool)$row['active'];
        $row['created_at']=(int)$row['created_at'];
        $row['updated_at']=(int)$row['updated_at'];
        return $row;
    }

    private function audit(int $spaceId,int $methodId,string $action): void {
        $this->writer->insert('lf_audit',[
            'space_id'=>$spaceId,'actor_uid'=>$this->access->uid(),
            'action'=>$action,'entity'=>'payment_method',
            'entity_id'=>$methodId,'occurred_at'=>time(),
        ]);
    }
}
