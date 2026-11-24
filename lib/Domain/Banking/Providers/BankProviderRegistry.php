<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Banking\Providers;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;

final class BankProviderRegistry {
    public function __construct(private FinanceApi $finance,
        private IEventDispatcher $events,private IAppManager $apps,
        private IUserSession $session) {}

    public function list(int $spaceId):array {
        $this->finance->requireModuleCapability($spaceId, 'librefinance_banking', 'bank.read');
        $event=new RegisterBankProvidersEvent();
        $this->events->dispatchTyped($event);
        $user=$this->session->getUser();
        $enabled=$user!==null?$this->apps->getEnabledAppsForUser($user):[];
        $providers=[['id'=>'csv_manual','name'=>'Importação CSV local',
            'automatic'=>false,'credentialsRequired'=>false,'available'=>true]];
        foreach($event->providers() as $p){
            if(in_array($p->appId(),$enabled,true)){
                $providers[]=['id'=>$p->id(),'name'=>$p->name(),
                    'automatic'=>true,'credentialsRequired'=>true,
                    // A configured worker+vault is needed before any bank fetch.
                    'available'=>false];
            }
        }
        return ['providers'=>$providers,'automaticSyncEnabled'=>false];
    }
}
