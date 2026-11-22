<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCA\LibreFinance\Service\CategoryService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class CategoryController extends ApiController {
    public function __construct(string $appName, IRequest $request, private CategoryService $service) {
        parent::__construct($appName,$request);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/categories')]
    public function index(int $spaceId,int $afterId=0):JSONResponse {
        return $this->respond(fn()=> $this->service->list($spaceId,$afterId));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/categories')]
    public function create(int $spaceId,string $code,string $name,string $kind,
        ?int $parentId=null,?int $accountId=null,string $color='#64748B'):JSONResponse {
        return $this->respond(fn()=> $this->service->create($spaceId,$code,$name,$kind,$parentId,$accountId,$color),201);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/categories/{categoryId}')]
    public function update(int $spaceId, int $categoryId, string $name, string $color,
        bool $active=true):JSONResponse {
        return $this->respond(fn()=> $this->service->update($spaceId,$categoryId,$name,$color,$active));
    }
}
