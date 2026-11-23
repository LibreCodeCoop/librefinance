<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use DomainException;
use OCA\LibreFinance\Domain\Invoicing\InvoiceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class InvoiceController extends Controller {
    public function __construct(string $appName,IRequest $request,private InvoiceService $service) {
        parent::__construct($appName,$request);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/invoices')]
    public function index(int $spaceId,int $afterId=0,string $kind='',string $status=''):JSONResponse {
        return $this->respond(fn()=>$this->service->list($spaceId,$afterId,$kind,$status));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/invoices/{invoiceId}')]
    public function show(int $spaceId,int $invoiceId):JSONResponse {
        return $this->respond(fn()=>$this->service->show($spaceId,$invoiceId));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/invoices')]
    public function create(int $spaceId,string $number,string $kind,int $contactId,
        string $issueOn,string $dueOn,array $lines,string $country,?string $region=null,
        array $customFields=[]):JSONResponse {
        return $this->respond(fn()=>$this->service->create($spaceId,$number,$kind,
            $contactId,$issueOn,$dueOn,$lines,$country,$region,$customFields),201);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/invoices/{invoiceId}/issue')]
    public function issue(int $spaceId,int $invoiceId):JSONResponse {
        return $this->respond(fn()=>$this->service->issue($spaceId,$invoiceId));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/invoices/{invoiceId}/allocations')]
    public function allocate(int $spaceId,int $invoiceId,int $bankAccountId,
        int $journalId,int $amountMinor):JSONResponse {
        return $this->respond(fn()=>$this->service->allocate($spaceId,$invoiceId,
            $bankAccountId,$journalId,$amountMinor),201);
    }
    private function respond(callable $fn,int $status=200):JSONResponse {
        try {return new JSONResponse($fn(),$status);}
        catch(DomainException $e){
            $code=$e->getMessage();
            return new JSONResponse(['error'=>$code],match($code){
                'invoice_not_found','space_not_found','module_not_found',
                'contact_not_found','tax_rule_not_found','bank_account_not_found',
                'journal_posting_not_found'=>404,
                'permission_denied','module_disabled'=>403,
                'invoice_not_draft','invoice_concurrent_change','invoice_overpayment',
                'invoice_not_issued','invoice_number_conflict','journal_already_allocated',
                'account_reference_busy',
                'journal_already_claimed','journal_not_available',
                'invoice_obligation_unlinked', 'invoice_obligation_mismatch',
                'invoice_already_linked' => 409,
                'authentication_required'=>401,
                default=>422,
            });
        }
    }
}
