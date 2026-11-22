<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Invoicing;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

final class TaxService {
    public const MODULE='librefinance_invoicing';
    public function __construct(private FinanceApi $finance,private IDBConnection $db,
        private IUserSession $session) {}

    public function list(int $spaceId,int $afterId=0):array {
        $this->finance->requireModule($spaceId,self::MODULE);
        if ($afterId<0) {throw new DomainException('invalid_cursor');}
        $q=$this->db->getQueryBuilder();
        $q->select('id','code','label','country','region','kind','rate_bps','inclusive',
            'valid_from','valid_through','source_ref')->from('lfi_tax_rules')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id',$q->createNamedParameter($afterId,IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->setMaxResults(51);
        $rows=$q->executeQuery()->fetchAllAssociative();
        $more=count($rows)>50;
        $rows=array_slice($rows,0,50);
        foreach ($rows as &$row) {$row=self::cast($row);}
        unset($row);
        return ['rules'=>$rows,'nextAfterId'=>$more?(int)end($rows)['id']:null];
    }

    public function create(int $spaceId,string $code,string $label,string $country,
        string $kind,int $rateBps,bool $inclusive,string $validFrom,
        ?string $validThrough=null,?string $region=null,string $sourceRef=''):array {
        $this->finance->requireModule($spaceId,self::MODULE,'manager');
        $code=strtoupper(trim($code));
        $label=trim($label);
        $country=strtoupper(trim($country));
        $region=$region!==null?strtoupper(trim($region)):null;
        if (!preg_match('/^[A-Z0-9_.-]{2,40}$/',$code)) {throw new DomainException('invalid_tax_code');}
        if ($label==='' || mb_strlen($label)>150) {throw new DomainException('invalid_tax_label');}
        if (!preg_match('/^[A-Z]{2}$/',$country)
            || ($region!==null && !preg_match('/^[A-Z0-9-]{1,32}$/',$region))) {
            throw new DomainException('invalid_tax_jurisdiction');
        }
        if (!in_array($kind,['vat','sales','withholding','other'],true) ||
            $rateBps<0 || $rateBps>100000 || ($inclusive && $kind==='withholding')) {
            throw new DomainException('invalid_tax_rule');
        }
        self::date($validFrom);
        if ($validThrough!==null) {
            self::date($validThrough);
            if ($validThrough<$validFrom) {throw new DomainException('invalid_tax_period');}
        }
        $sourceRef=trim($sourceRef);
        if ($sourceRef==='' || mb_strlen($sourceRef)>255) {
            throw new DomainException('tax_source_required');
        }
        for($attempt=0;$attempt<5;++$attempt){
        $this->db->beginTransaction();
        try {
            // Lock the revision stream BEFORE checking overlapping periods.
            // The unique stream key serializes writers for the same tax code.
            $q=$this->db->getQueryBuilder();
            $q->select('id')->from('lfi_tax_series')
                ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('code',$q->createNamedParameter($code)));
            $seriesId=$q->executeQuery()->fetchOne();
            if($seriesId===false){
                $q=$this->db->getQueryBuilder();
                $q->insert('lfi_tax_series')->values([
                    'space_id'=>$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT),
                    'code'=>$q->createNamedParameter($code),
                    'lock_version'=>$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),
                ])->executeStatement();
            }else{
                $q=$this->db->getQueryBuilder();
                $affected=$q->update('lfi_tax_series')
                    ->set('lock_version',$q->createFunction('lock_version + 1'))
                    ->where($q->expr()->eq('id',$q->createNamedParameter((int)$seriesId,IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                if($affected!==1){throw new DomainException('tax_concurrent_change');}
            }
            $q=$this->db->getQueryBuilder();
            $q->select('valid_from','valid_through')->from('lfi_tax_rules')
                ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('code',$q->createNamedParameter($code)));
            foreach ($q->executeQuery()->fetchAllAssociative() as $previous) {
                $previousEnd=$previous['valid_through']??'9999-12-31';
                $newEnd=$validThrough??'9999-12-31';
                if ($validFrom<=$previousEnd && $previous['valid_from']<=$newEnd) {
                    throw new DomainException('tax_period_overlap');
                }
            }
            $values=[
                'space_id'=>$spaceId,'code'=>$code,'label'=>$label,'country'=>$country,
                'region'=>$region,'kind'=>$kind,'rate_bps'=>$rateBps,
                'inclusive'=>$inclusive?1:0,'valid_from'=>$validFrom,
                'valid_through'=>$validThrough,'source_ref'=>$sourceRef,
                'created_by'=>$this->session->getUser()?->getUID()??'',
                'created_at'=>time(),
            ];
            $q=$this->db->getQueryBuilder();
            $placeholders=[];
            foreach($values as $key=>$value){
                $placeholders[$key]=$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);
            }
            $q->insert('lfi_tax_rules')->values($placeholders)->executeStatement();
            $id=(int)$this->db->lastInsertId('*PREFIX*lfi_tax_rules');
            $this->db->commit();
            return ['id'=>$id,'code'=>$code,'label'=>$label,'country'=>$country,
                'region'=>$region,'kind'=>$kind,'rate_bps'=>$rateBps,'inclusive'=>$inclusive,
                'valid_from'=>$validFrom,'valid_through'=>$validThrough,'source_ref'=>$sourceRef];
        } catch(\Throwable $e){
            $this->db->rollBack();
            if($e instanceof \OCP\DB\Exception){
                $reason=$e->getReason();
                $uniqueness=$reason===\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
                $dbLock=in_array($reason,[
                    \OCP\DB\Exception::REASON_DEADLOCK,
                    \OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT,
                ],true);
                // DBAL wraps SQLite's BUSY_SNAPSHOT as a generic driver exception.
                // Only this known transaction conflict is retried, never arbitrary SQL errors.
                if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_SQLITE
                    && $reason===\OCP\DB\Exception::REASON_DRIVER){
                    $error=strtolower($e->getMessage());
                    $dbLock=str_contains($error,'database is locked')
                        ||str_contains($error,'database is busy')
                        ||str_contains($error,'sqlite_busy');
                }
                if($uniqueness||$dbLock){
                    if($attempt<4){
                        usleep(30000*($attempt+1));
                        continue;
                    }
                    throw new DomainException('tax_concurrent_change',previous:$e);
                }
            }
            throw $e;
        }
        }
        throw new DomainException('tax_concurrent_change');
    }

    public function ruleForDate(int $spaceId,int $ruleId,string $date,
        string $country,?string $region):array {
        $this->finance->requireModule($spaceId,self::MODULE);
        self::date($date);
        $q=$this->db->getQueryBuilder();
        $q->select('id','code','label','country','region','kind','rate_bps','inclusive',
            'valid_from','valid_through','source_ref')->from('lfi_tax_rules')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($ruleId,IQueryBuilder::PARAM_INT)));
        $rule=$q->executeQuery()->fetchAssociative();
        if ($rule===false){throw new DomainException('tax_rule_not_found');}
        if($rule['country']!==$country || ($rule['region']!==null && $rule['region']!==$region)){
            throw new DomainException('tax_jurisdiction_mismatch');
        }
        if($date<$rule['valid_from'] || ($rule['valid_through']!==null && $date>$rule['valid_through'])){
            throw new DomainException('tax_rule_not_effective');
        }
        return self::cast($rule);
    }

    private static function date(string $date):void {
        $v=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$v || $v->format('Y-m-d')!==$date){throw new DomainException('invalid_date');}
    }
    private static function cast(array $rule):array {
        $rule['id']=(int)$rule['id'];
        $rule['rate_bps']=(int)$rule['rate_bps'];
        $rule['inclusive']=(bool)$rule['inclusive'];
        return $rule;
    }
}
