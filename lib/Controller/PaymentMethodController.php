<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCA\LibreFinance\Service\PaymentMethodService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
final class PaymentMethodController extends ApiController {
    public function __construct(string $appName,IRequest $request,
        private PaymentMethodService $methods) { parent::__construct($appName,$request); }

    #[NoAdminRequired]
    public function index(int $spaceId,int $afterId=0):JSONResponse {
        return $this->respond(fn()=>$this->methods->list($spaceId,$afterId));
    }
    #[NoAdminRequired]
    public function create(int $spaceId,string $name,string $kind,?int $bankAccountId=null):JSONResponse {
        return $this->respond(fn()=>$this->methods->create($spaceId,$name,$kind,$bankAccountId),201);
    }
    #[NoAdminRequired]
    public function update(int $spaceId,int $methodId,string $name,
        ?int $bankAccountId=null,bool $active=true):JSONResponse {
        return $this->respond(fn()=>$this->methods->update($spaceId,$methodId,$name,$bankAccountId,$active));
    }
    #[NoAdminRequired]
    public function delete(int $spaceId,int $methodId):JSONResponse {
        return $this->respond(fn()=>$this->methods->delete($spaceId,$methodId));
    }
}
