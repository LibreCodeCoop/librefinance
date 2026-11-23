<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use DomainException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

abstract class ApiController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    protected function respond(callable $callback, int $successStatus = 200): JSONResponse {
        try {
            return new JSONResponse($callback(), $successStatus);
        } catch (DomainException $e) {
            $code = $e->getMessage();
            $status = match ($code) {
                'authentication_required' => 401,
                'permission_denied' => 403,
                'unknown_policy_key', 'environment_not_found', 'space_not_found', 'account_not_found', 'bank_account_not_found', 'payment_account_not_found', 'journal_not_found', 'group_not_found', 'user_not_found', 'membership_not_found', 'contact_not_found', 'item_not_found', 'company_not_found', 'company_logo_not_found', 'account_logo_not_found', 'payment_method_not_found', 'custom_field_not_found', 'module_not_found', 'category_parent_not_found', 'category_not_found' => 404,
                'revision_conflict', 'provision_conflict', 'governance_group_conflict', 'category_code_conflict', 'company_tax_id_conflict', 'custom_field_key_conflict', 'ai_preview_changed' => 409,
                'ai_task_not_found' => 404,
                'ai_provider_unavailable' => 503,
                'policy_override_forbidden', 'module_disabled', 'governance_disabled' => 403,
                'environment_suspended' => 423,
                'account_has_balance', 'account_in_use', 'account_reference_busy', 'payment_method_in_use', 'idempotency_conflict', 'sku_conflict', 'journal_claimed', 'journal_already_claimed', 'journal_not_available', 'invoice_overpayment', 'invoice_not_issued', 'invoice_tax_allocation_required', 'obligation_closed', 'amount_exceeds_balance', 'invoice_payment_via_allocation', 'invoice_obligation_unlinked', 'concurrent_change', 'invoice_concurrent_change', 'space_archived', 'space_already_archived', 'space_not_archived' => 409,
                default => 422,
            };
            return new JSONResponse(['error' => $code], $status);
        }
    }
}
