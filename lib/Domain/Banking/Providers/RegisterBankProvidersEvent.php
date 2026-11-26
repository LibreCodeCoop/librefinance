<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Banking\Providers;
use LogicException;
use OCP\EventDispatcher\Event;
final class RegisterBankProvidersEvent extends Event {
    /** @var array<string,BankProviderInterface> */
    private array $providers=[];
    public function register(BankProviderInterface $provider):void {
        $id=$provider->id();
        if(!preg_match('/^[a-z][a-z0-9_]{1,40}$/D',$id) ||
            !preg_match('/^librefinance_[a-z][a-z0-9_]{2,40}$/D',$provider->appId()) ||
            $id === 'csv_manual' ||
            trim($provider->name())==='' ||
            isset($this->providers[$id])) {
            throw new LogicException('invalid_or_duplicate_bank_provider');
        }
        $this->providers[$id]=$provider;
    }
    /** @return array<string,BankProviderInterface> */
    public function providers():array {
        ksort($this->providers);
        return $this->providers;
    }
}
