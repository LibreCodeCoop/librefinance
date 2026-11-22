<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use DomainException;
use OCA\LibreFinance\Domain\Invoicing\TaxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class TaxController extends Controller {
    public function __construct(string $appName,IRequest $request,private TaxService $service) {
        parent::__construct($appName,$request);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/tax-rules')]
    public function index(int $spaceId,int $afterId=0):JSONResponse {
        return $this->respond(fn()=> $this->service->list($spaceId,$afterId));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/tax-rules')]
    public function create(int $spaceId,string $code,string $label,string $country,string $kind,
        int $rateBps,bool $inclusive,string $validFrom,?string $validThrough=null,
        ?string $region=null,string $sourceRef=''):JSONResponse {
        return $this->respond(fn()=>$this->service->create($spaceId,$code,$label,$country,
            $kind,$rateBps,$inclusive,$validFrom,$validThrough,$region,$sourceRef),201);
    }
    private function respond(callable $fn,int $status=200):JSONResponse {
        try {return new JSONResponse($fn(),$status);}
        catch(DomainException $e){
            $code=$e->getMessage();
            return new JSONResponse(['error'=>$code],match($code){
                'space_not_found','module_not_found','tax_rule_not_found'=>404,
                'permission_denied','module_disabled'=>403,
                'tax_period_overlap','tax_concurrent_change'=>409,
                'authentication_required'=>401,
                default=>422,
            });
        }
    }
}
