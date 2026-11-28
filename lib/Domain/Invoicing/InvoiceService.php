<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Invoicing;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCA\LibreFinance\Domain\Obligations\ObligationService;
use OCA\LibreFinance\Service\CustomFieldService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

final class InvoiceService {
    private const MODULE='librefinance_invoicing';

    public function __construct(
        private FinanceApi $finance,
        private TaxService $taxes,
        private ObligationService $obligations,
        private IDBConnection $db,
        private IUserSession $session,
        private CustomFieldService $customFields,
    ) {}

    public function list(int $spaceId,int $afterId=0,string $kind='',string $status='',string $issueFrom='',string $issueThrough=''):array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.read');
        if($afterId<0){throw new DomainException('invalid_cursor');}
        if($kind!=='' && !in_array($kind,['invoice','bill'],true)){
            throw new DomainException('invalid_invoice_kind');
        }
        if($status!=='' && !in_array($status,['draft','issued','partially_paid','paid','attention'],true)){
            throw new DomainException('invalid_invoice_status');
        }
        $validateDate = static function (string $date): void {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                throw new DomainException('invalid_invoice_period');
            }
            [$y, $m, $d] = explode('-', $date);
            if (!checkdate((int)$m, (int)$d, (int)$y)) {
                throw new DomainException('invalid_invoice_period');
            }
        };
        if ($issueFrom !== '') {
            $validateDate($issueFrom);
        }
        if ($issueThrough !== '') {
            $validateDate($issueThrough);
        }
        if ($issueFrom !== '' && $issueThrough !== '' && $issueFrom > $issueThrough) {
            throw new DomainException('invalid_invoice_period');
        }
        $q=$this->db->getQueryBuilder();
        $fields=['id','number','kind','status','contact_id','currency',
            'issue_on','due_on','country','region','subtotal_minor','tax_minor','withheld_minor',
            'total_minor','paid_minor','version'];
        $q->select(...array_map(static fn(string $field):string=>'i.'.$field,$fields))
            ->from('lfi_invoices','i')
            ->where($q->expr()->eq('i.space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('i.id',$q->createNamedParameter($afterId,IQueryBuilder::PARAM_INT)));
        // Filter before pagination, never after a page is loaded in Vue.
        if($kind!==''){
            $q->andWhere($q->expr()->eq('i.kind',$q->createNamedParameter($kind)));
        }
        if($issueFrom!==''){
            $q->andWhere($q->expr()->gte('i.issue_on',$q->createNamedParameter($issueFrom)));
        }
        if($issueThrough!==''){
            $q->andWhere($q->expr()->lte('i.issue_on',$q->createNamedParameter($issueThrough)));
        }
        // Issued documents without a verified obligation link must remain
        // actionable. A scoped LEFT JOIN uses the per-space unique index
        // and avoids a DBAL-escaped NOT IN subquery (invalid on PostgreSQL).
        if($status==='attention'){
            $q->leftJoin('i','lf_invoice_obligation_links','l',
                $q->expr()->andX(
                    $q->expr()->eq('l.invoice_id','i.id'),
                    $q->expr()->eq('l.space_id','i.space_id'),
                ));
            $q->andWhere($q->expr()->orX(
                $q->expr()->eq('i.status',$q->createNamedParameter('draft')),
                $q->expr()->isNull('l.invoice_id'),
            ));
        }elseif($status!==''){
            $q->andWhere($q->expr()->eq('i.status',$q->createNamedParameter($status)));
        }
        $q->orderBy('i.id','ASC')->setMaxResults(51);
        $rows=$q->executeQuery()->fetchAllAssociative();
        $more=count($rows)>50;
        $rows=array_slice($rows,0,50);
        foreach($rows as &$row){$row=self::cast($row);}
        unset($row);
        // Two batched reads, not a per-invoice query; respect each sales/bills
        // field definition and the authenticated finance-space permissions.
        foreach(['invoice','bill'] as $type){
            $ids=array_column(array_filter($rows,static fn(array $row):bool=>$row['kind']===$type),'id');
            $values=$this->customFields->valuesForEntities($spaceId,$type,$ids);
            foreach($rows as &$row){
                if($row['kind']===$type){
                    $row['custom_fields']=$values[$row['id']]??[];
                }
            }
            unset($row);
        }
        return ['invoices'=>$rows,'nextAfterId'=>$more?(int)end($rows)['id']:null];
    }

    /**
     * Read-only advisory lookup. A document reference is not an origin identity.
     * The existing document, if supplied, owns its evidence; never trust a
     * caller to substitute its party or exclude an arbitrary candidate.
     *
     * @return array<string,mixed>
     */
    public function duplicateCandidates(int $spaceId, int $invoiceId = 0, string $kind = '',
        int $contactId = 0, string $reference = '', string $issueOn = '',
        string $totalMinor = '', int $afterId = 0): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.read');
        if ($invoiceId < 0 || $contactId < 0 || $afterId < 0) {
            throw new DomainException('invalid_duplicate_evidence');
        }
        $knownDocumentId = null;
        if ($invoiceId > 0) {
            if ($kind !== '' || $contactId !== 0 || $reference !== '' || $issueOn !== '' || $totalMinor !== '') {
                throw new DomainException('invalid_duplicate_evidence');
            }
            $document = $this->find($spaceId, $invoiceId);
            if ($document === null) {
                throw new DomainException('invoice_not_found');
            }
            $knownDocumentId = (int)$document['id'];
            $kind = (string)$document['kind'];
            $contactId = (int)$document['contact_id'];
            $reference = (string)$document['number'];
            $issueOn = (string)$document['issue_on'];
            $totalMinor = (string)$document['total_minor'];
            $currency = (string)$document['currency'];
        } else {
            $currency = $this->finance->currency($spaceId, self::MODULE);
            if ($contactId > 0) {
                $this->finance->requireActiveContact($spaceId, self::MODULE, $contactId);
            }
        }

        $reference = strtoupper(trim($reference));
        if ($kind !== '' && !in_array($kind, ['invoice', 'bill'], true)) {
            throw new DomainException('invalid_invoice_kind');
        }
        if ($reference !== '' && !preg_match('~^[A-Z0-9_./:-]{2,80}$~D', $reference)) {
            throw new DomainException('invalid_duplicate_evidence');
        }
        $from = $through = null;
        if ($issueOn !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $issueOn)) {
                throw new DomainException('invalid_date');
            }
            [$year, $month, $day] = array_map('intval', explode('-', $issueOn));
            if (!checkdate($month, $day, $year)) {
                throw new DomainException('invalid_date');
            }
            $date = new \DateTimeImmutable($issueOn, new \DateTimeZone('UTC'));
            $from = max(new \DateTimeImmutable('0001-01-01', new \DateTimeZone('UTC')), $date->modify('-3 days'))->format('Y-m-d');
            $through = min(new \DateTimeImmutable('9999-12-31', new \DateTimeZone('UTC')), $date->modify('+3 days'))->format('Y-m-d');
        }
        $amount = null;
        if ($totalMinor !== '') {
            if (!preg_match('/^[1-9][0-9]{0,12}$/D', $totalMinor) || (int)$totalMinor > TaxCalculator::MAX_MINOR) {
                throw new DomainException('invalid_duplicate_evidence');
            }
            $amount = (int)$totalMinor;
        }
        $dateAndAmount = $from !== null && $amount !== null;
        $complete = $kind !== '' && $contactId > 0 && ($reference !== '' || $dateAndAmount);
        $result = [
            'contractVersion' => 1,
            'knownDocumentId' => $knownDocumentId,
            // No commercial source identity is recorded by the current importer.
            'originIdentityStatus' => 'unavailable',
            'evidence' => [
                'kind' => $kind, 'contactId' => $contactId ?: null, 'currency' => $currency,
                'reference' => $reference ?: null, 'issueOn' => $issueOn ?: null,
                'totalMinor' => $amount, 'dateToleranceDays' => 3,
            ],
            'status' => $complete ? 'no_candidate' : 'incomplete_evidence',
            'candidates' => [],
            'nextAfterId' => null,
        ];
        if (!$complete) {
            return $result;
        }

        $q = $this->db->getQueryBuilder();
        $matches = [];
        if ($reference !== '') {
            $matches[] = $q->expr()->eq('number', $q->createNamedParameter($reference));
        }
        if ($dateAndAmount) {
            $matches[] = $q->expr()->andX(
                $q->expr()->eq('total_minor', $q->createNamedParameter($amount, IQueryBuilder::PARAM_INT)),
                $q->expr()->gte('issue_on', $q->createNamedParameter($from)),
                $q->expr()->lte('issue_on', $q->createNamedParameter($through)),
            );
        }
        $q->select('id', 'number', 'kind', 'contact_id', 'currency', 'status', 'issue_on', 'total_minor')
            ->from('lfi_invoices')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)))
            ->andWhere($q->expr()->eq('contact_id', $q->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('currency', $q->createNamedParameter($currency)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->andWhere(count($matches) === 1 ? $matches[0] : $q->expr()->orX(...$matches));
        if ($knownDocumentId !== null) {
            $q->andWhere($q->expr()->neq('id', $q->createNamedParameter($knownDocumentId, IQueryBuilder::PARAM_INT)));
        }
        $rows = $q->orderBy('id', 'ASC')->setMaxResults(51)->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as $row) {
            $reasons = [];
            if ($reference !== '' && $row['number'] === $reference) {
                $reasons[] = 'same_reference';
            }
            if ($dateAndAmount && (int)$row['total_minor'] === $amount
                && $row['issue_on'] >= $from && $row['issue_on'] <= $through) {
                $reasons[] = 'same_total';
                $reasons[] = $row['issue_on'] === $issueOn ? 'same_issue_date' : 'near_issue_date';
            }
            $result['candidates'][] = [
                'id' => (int)$row['id'],
                'reference' => (string)$row['number'],
                'kind' => (string)$row['kind'],
                'contactId' => (int)$row['contact_id'],
                'currency' => (string)$row['currency'],
                'status' => (string)$row['status'],
                'issueOn' => (string)$row['issue_on'],
                'totalMinor' => (int)$row['total_minor'],
                'matchReasons' => $reasons,
                'classification' => 'possible_duplicate',
            ];
        }
        $result['status'] = $rows === [] ? 'no_candidate' : 'candidates';
        $result['nextAfterId'] = $more ? (int)end($rows)['id'] : null;
        return $result;
    }

    public function show(int $spaceId,int $invoiceId):array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.read');
        $invoice=$this->find($spaceId,$invoiceId);
        if(!$invoice){throw new DomainException('invoice_not_found');}
        $q=$this->db->getQueryBuilder();
        $q->select('id','line_no','description','quantity','unit_price_minor',
            'tax_rule_id','tax_snapshot','base_minor','tax_minor','withheld_minor','total_minor')
            ->from('lfi_invoice_lines')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('invoice_id',$q->createNamedParameter($invoiceId,IQueryBuilder::PARAM_INT)))
            ->orderBy('line_no','ASC');
        $lines=$q->executeQuery()->fetchAllAssociative();
        foreach($lines as &$line){
            foreach(['id','line_no','quantity','unit_price_minor','base_minor',
                'tax_minor','withheld_minor','total_minor'] as $field){$line[$field]=(int)$line[$field];}
            $line['tax_rule_id']=$line['tax_rule_id']!==null?(int)$line['tax_rule_id']:null;
            $line['tax_snapshot']=$line['tax_snapshot']!==null
                ?json_decode($line['tax_snapshot'],true,flags:JSON_THROW_ON_ERROR):null;
        }
        unset($line);
        $q=$this->db->getQueryBuilder();
        $q->select('id','bank_account_id','journal_id','amount_minor','created_at')
            ->from('lfi_allocations')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('invoice_id',$q->createNamedParameter($invoiceId,IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC');
        $allocations=$q->executeQuery()->fetchAllAssociative();
        foreach($allocations as &$a){
            foreach(['id','bank_account_id','journal_id','amount_minor','created_at'] as $f){$a[$f]=(int)$a[$f];}
        }
        unset($a);
        $cast=self::cast($invoice);
        $cast['custom_fields']=$this->customFields->valuesForEntities($spaceId,
            $cast['kind'],[$invoiceId])[$invoiceId]??[];
        return ['invoice'=>$cast,'lines'=>$lines,'allocations'=>$allocations,
            'obligationId'=>$this->obligations->linkedObligationId($spaceId,$invoiceId)];
    }

    /**
     * The invoice is operational. Draft creation never posts a journal or
     * issues a government document. Tax rules are snapshots as of issue_on.
     *
     * @param list<array<string,mixed>> $lines
     */
    public function create(int $spaceId,string $number,string $kind,int $contactId,
        string $issueOn,string $dueOn,array $lines,string $country,?string $region=null,
        array $customFields=[]):array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.manage');
        $number=strtoupper(trim($number));
        if(!preg_match('~^[A-Z0-9_./:-]{2,80}$~',$number)){throw new DomainException('invalid_invoice_number');}
        if(!in_array($kind,['invoice','bill'],true)){throw new DomainException('invalid_invoice_kind');}
        $country=strtoupper(trim($country));
        $region=$region!==null && trim($region)!==''?strtoupper(trim($region)):null;
        if(!preg_match('/^[A-Z]{2}$/',$country)
            ||($region!==null && !preg_match('/^[A-Z0-9-]{1,32}$/',$region))){
            throw new DomainException('invalid_invoice_jurisdiction');
        }
        self::date($issueOn);self::date($dueOn);
        if($dueOn<$issueOn){throw new DomainException('invalid_invoice_period');}
        if($contactId<=0){throw new DomainException('contact_required');}
        $this->finance->requireActiveContact($spaceId,self::MODULE,$contactId);
        if(count($lines)<1 || count($lines)>20){throw new DomainException('invalid_invoice_lines');}
        $details=[];$totals=['baseMinor'=>0,'taxMinor'=>0,'withheldMinor'=>0,'totalMinor'=>0];
        foreach($lines as $index=>$line){
            if(!is_array($line)){throw new DomainException('invalid_invoice_lines');}
            $description=trim((string)($line['description']??''));
            $quantity=$line['quantity']??null;
            $unitPrice=$line['unitPriceMinor']??null;
            $taxId=$line['taxRuleId']??null;
            if($description==='' || mb_strlen($description)>255
                || !is_int($quantity) || !is_int($unitPrice)
                || ($taxId!==null && (!is_int($taxId) || $taxId<=0))){
                throw new DomainException('invalid_invoice_lines');
            }
            $rule=$taxId!==null?$this->taxes->ruleForDate($spaceId,$taxId,$issueOn,$country,$region):null;
            $calculated=TaxCalculator::calculate($quantity,$unitPrice,$rule);
            foreach($totals as $key=>$current){
                $value=$current+$calculated[$key];
                if($value>TaxCalculator::MAX_MINOR){throw new DomainException('invalid_invoice_total');}
                $totals[$key]=$value;
            }
            $details[]=[
                'line_no'=>$index+1,'description'=>$description,'quantity'=>$quantity,
                'unit_price_minor'=>$unitPrice,'tax_rule_id'=>$taxId,
                'tax_snapshot'=>$rule!==null?json_encode($rule,JSON_THROW_ON_ERROR):null,
                'base_minor'=>$calculated['baseMinor'],'tax_minor'=>$calculated['taxMinor'],
                'withheld_minor'=>$calculated['withheldMinor'],'total_minor'=>$calculated['totalMinor'],
            ];
        }
        if($totals['totalMinor']<=0){throw new DomainException('invalid_invoice_total');}
        $currency=$this->finance->currency($spaceId,self::MODULE);
        $custom=$this->customFields->validateValues($spaceId,$customFields,true,0,$kind);
        $this->db->beginTransaction();
        try{
            $this->insert('lfi_invoices',[
                'space_id'=>$spaceId,'number'=>$number,'kind'=>$kind,'status'=>'draft',
                'contact_id'=>$contactId,'currency'=>$currency,
                'issue_on'=>$issueOn,'due_on'=>$dueOn,'country'=>$country,'region'=>$region,
                'subtotal_minor'=>$totals['baseMinor'],'tax_minor'=>$totals['taxMinor'],
                'withheld_minor'=>$totals['withheldMinor'],'total_minor'=>$totals['totalMinor'],
                'paid_minor'=>0,'version'=>0,'created_by'=>$this->uid(),
                'created_at'=>time(),'issued_at'=>null,
            ]);
            $id=(int)$this->db->lastInsertId('*PREFIX*lfi_invoices');
            foreach($details as $line){
                $this->insert('lfi_invoice_lines',['space_id'=>$spaceId,'invoice_id'=>$id]+$line);
            }
            $this->customFields->saveValues($spaceId,$id,$custom,$kind);
            $this->audit($spaceId,$id,'invoice.created');
            $this->db->commit();
            return ['id'=>$id,'number'=>$number,'status'=>'draft','currency'=>$currency,
                'country'=>$country,'region'=>$region,
                'subtotalMinor'=>$totals['baseMinor'],'taxMinor'=>$totals['taxMinor'],
                'withheldMinor'=>$totals['withheldMinor'],'totalMinor'=>$totals['totalMinor']];
        } catch(\Throwable $e){
            $this->db->rollBack();
            if($e instanceof \OCP\DB\Exception &&
                $e->getReason()===\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION){
                throw new DomainException('invoice_number_conflict',previous:$e);
            }
            throw $e;
        }
    }

    public function issue(int $spaceId,int $invoiceId):array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.issue');
        $this->db->beginTransaction();
        try{
            $inv=$this->find($spaceId,$invoiceId);
            if(!$inv){throw new DomainException('invoice_not_found');}
            if($inv['status']!=='draft'){throw new DomainException('invoice_not_draft');}
            $changed=$this->changeStatus($spaceId,$invoiceId,(int)$inv['version'],'draft','issued');
            if(!$changed){throw new DomainException('invoice_concurrent_change');}
            // The commercial document and its payable/receivable become
            // visible together, or neither becomes visible after a rollback.
            $this->obligations->linkIssuedInvoice($spaceId,$invoiceId,$inv);
            $this->audit($spaceId,$invoiceId,'invoice.issued');
            $this->db->commit();
            return ['id'=>$invoiceId,'status'=>'issued','isFiscalDocument'=>false];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function allocate(int $spaceId,int $invoiceId,int $bankAccountId,
        int $journalId,int $amountMinor):array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'invoice.manage');
        if($bankAccountId<=0||$journalId<=0||$amountMinor<=0
            ||$amountMinor>TaxCalculator::MAX_MINOR){throw new DomainException('invalid_allocation');}
        $this->db->beginTransaction();
        try{
            $inv=$this->find($spaceId,$invoiceId);
            if(!$inv){throw new DomainException('invoice_not_found');}
            if(!in_array($inv['status'],['issued','partially_paid'],true)){
                throw new DomainException('invoice_not_issued');
            }
            $amount=(int)$inv['paid_minor']+$amountMinor;
            if($amount>(int)$inv['total_minor']){throw new DomainException('invoice_overpayment');}
            $signed=$inv['kind']==='invoice'?$amountMinor:-$amountMinor;
            $this->finance->reserveBankAccountsForWrite($spaceId, self::MODULE, [$bankAccountId]);
            $this->finance->journalContainsBankPosting($spaceId,self::MODULE,
                $bankAccountId,$journalId,$signed);
            $this->finance->claimJournal($spaceId, self::MODULE, $journalId,
                'invoice:' . $invoiceId);
            $this->insert('lfi_allocations',[
                'space_id'=>$spaceId,'invoice_id'=>$invoiceId,
                'bank_account_id'=>$bankAccountId,'journal_id'=>$journalId,
                'amount_minor'=>$amountMinor,'created_by'=>$this->uid(),'created_at'=>time(),
            ]);
            $newStatus=$amount===(int)$inv['total_minor']?'paid':'partially_paid';
            $q=$this->db->getQueryBuilder();
            $updated=$q->update('lfi_invoices')
                ->set('paid_minor',$q->createNamedParameter($amount,IQueryBuilder::PARAM_INT))
                ->set('status',$q->createNamedParameter($newStatus))
                ->set('version',$q->createNamedParameter((int)$inv['version']+1,IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',$q->createNamedParameter($invoiceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('version',$q->createNamedParameter((int)$inv['version'],IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            if($updated!==1){throw new DomainException('invoice_concurrent_change');}
            // Capture the allocation ID BEFORE inserting the corresponding
            // obligation settlement, which changes the connection's last ID.
            $allocationId=(int)$this->db->lastInsertId('*PREFIX*lfi_allocations');
            // The only payment writer for invoice-linked receivables. Both
            // financial projections change in the same database transaction.
            $this->obligations->recordInvoiceAllocation(
                $spaceId,$invoiceId,$journalId,$amountMinor,
                (int)$inv['paid_minor'],(int)$inv['total_minor']);
            $this->audit($spaceId,$invoiceId,'invoice.allocation_linked');
            $this->db->commit();
            return ['id'=>$allocationId,'invoiceId'=>$invoiceId,'status'=>$newStatus,
                'paidMinor'=>$amount,'ledgerJournalId'=>$journalId];
        }catch(\Throwable $e){
            $this->db->rollBack();
            if($e instanceof \OCP\DB\Exception &&
                $e->getReason()===\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION){
                throw new DomainException('journal_already_allocated',previous:$e);
            }
            throw $e;
        }
    }

    private function changeStatus(int $spaceId,int $invoiceId,int $version,
        string $from,string $to):bool {
        $q=$this->db->getQueryBuilder();
        $affected=$q->update('lfi_invoices')->set('status',$q->createNamedParameter($to))
            ->set('version',$q->createNamedParameter($version+1,IQueryBuilder::PARAM_INT))
            ->set('issued_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($invoiceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('status',$q->createNamedParameter($from)))
            ->andWhere($q->expr()->eq('version',$q->createNamedParameter($version,IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        return $affected===1;
    }

    private function find(int $spaceId,int $invoiceId):?array {
        $q=$this->db->getQueryBuilder();
        $q->select('*')->from('lfi_invoices')
            ->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',$q->createNamedParameter($invoiceId,IQueryBuilder::PARAM_INT)));
        return $q->executeQuery()->fetchAssociative()?:null;
    }

    /** @param array<string,int|string|null> $values */
    private function insert(string $table,array $values):void {
        $q=$this->db->getQueryBuilder();$params=[];
        foreach($values as $field=>$value){
            $params[$field]=$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);
        }
        $q->insert($table)->values($params)->executeStatement();
    }
    private function audit(int $spaceId,int $invoiceId,string $action):void {
        $this->insert('lfi_audit',['space_id'=>$spaceId,'invoice_id'=>$invoiceId,'action'=>$action,
            'actor_uid'=>$this->uid(),'occurred_at'=>time()]);
    }
    private function uid():string {
        $uid=$this->session->getUser()?->getUID();
        if(!$uid){throw new DomainException('authentication_required');}
        return $uid;
    }
    private static function date(string $value):void {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$parsed||$parsed->format('Y-m-d')!==$value){throw new DomainException('invalid_date');}
    }
    private static function cast(array $row):array {
        foreach(['id','subtotal_minor','tax_minor','withheld_minor','total_minor','paid_minor','version'] as $field) {
            $row[$field]=(int)$row[$field];
        }
        $row['contact_id']=$row['contact_id']!==null?(int)$row['contact_id']:null;
        return $row;
    }
}
