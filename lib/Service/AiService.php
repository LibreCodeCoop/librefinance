<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DateTimeImmutable;
use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * AI never decides accounting entries or receives personal/contact records.
 * Only aggregates from an authorized, enabled report module are shared, and
 * only after consent to the Nextcloud instance's configured task provider.
 */
final class AiService {
    public function __construct(
        private AccessService $access,
        private FinanceApi $finance,
        private IManager $manager,
        private IUserSession $session,
        private IConfig $config,
    ) {}

    /** @return array<string,mixed> */
    public function capabilities(int $spaceId): array {
        $this->finance->requireModule($spaceId, 'librefinance_reports', 'manager');
        return [
            'available' => $this->available(),
            'taskType' => TextToText::ID,
            'explicitConsentRequired' => true,
            'readOnly' => true,
            'providerMayProcessDataOutsideInstance' => true,
            'dataPolicy' => 'aggregated_totals_only',
            'automaticFinancialWrites' => false,
        ];
    }

    /**
     * Show the exact aggregate payload proposed for the model before
     * consent; no external provider is called and no task is created.
     * Auditors/viewers cannot request an AI task under another role.
     */
    public function preview(int $spaceId, string $from, string $through): array {
        $this->access->requireRole($spaceId, 'manager');
        self::date($from);
        self::date($through);
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($through);
        if ($end < $start || $start->diff($end)->days > 366) {
            throw new DomainException('invalid_period');
        }
        $summary = $this->finance->profitLoss($spaceId, 'librefinance_reports', $from, $through);
        $totals = [
            'periodStart' => $from, 'periodEnd' => $through,
            'currency' => $this->finance->currency($spaceId, 'librefinance_reports'),
            'revenueMinor' => (int)$summary['revenueMinor'],
            'expenseMinor' => (int)$summary['expenseMinor'],
            'resultMinor' => (int)$summary['resultMinor'],
        ];
        // Bind consent to the actor, finance space and exact financial
        // aggregates. Never add this opaque fingerprint to the model prompt.
        return $totals + ['previewHash' => $this->fingerprint(
            $spaceId, $this->access->uid(), $totals)];
        
    }

    /** @return array<string,mixed> */
    public function analyze(int $spaceId, string $from, string $through,
        bool $consent, string $previewHash = ''): array {
        $this->access->requireRole($spaceId, 'manager');
        if (!$consent) {
            throw new DomainException('ai_consent_required');
        }
        $safe = $this->preview($spaceId, $from, $through);
        if (!preg_match('/^[a-f0-9]{64}$/', $previewHash)
            || !hash_equals($safe['previewHash'], $previewHash)) {
            throw new DomainException('ai_preview_changed');
        }
        unset($safe['previewHash']);
        if (!$this->available()) {
            throw new DomainException('ai_provider_unavailable');
        }
        $uid = $this->access->uid();
        $prompt = 'Analise estritamente os valores agregados de receita e despesa a seguir. '
            . 'Explique tendencias possiveis e limites das evidencias sem inventar fatos. '
            . 'Nao forneca aconselhamento fiscal juridico definitivo, nao proponha lancamentos '
            . 'automaticos e nao revele dados de pessoas. Responda em portugues claro. '
            . 'Dados: ' . json_encode($safe, JSON_THROW_ON_ERROR);

        $task = new Task(TextToText::ID, ['input' => $prompt],
            'librefinance', $uid, 'space:' . $spaceId);
        try {
            $this->manager->scheduleTask($task);
        } catch (\OCP\TaskProcessing\Exception\Exception $e) {
            throw new DomainException('ai_provider_unavailable', previous: $e);
        }
        $id = $task->getId();
        if ($id === null || $id < 1) {
            throw new DomainException('ai_task_not_scheduled');
        }
        return ['taskId' => $id, 'status' => 'scheduled', 'scope' => 'aggregated_totals_only'];
    }

    /** @return array<string,mixed> */
    public function status(int $spaceId, int $taskId): array {
        $this->finance->requireModule($spaceId, 'librefinance_reports', 'manager');
        if ($taskId < 1) {
            throw new DomainException('ai_task_not_found');
        }
        try {
            $task = $this->manager->getUserTask($taskId, $this->access->uid());
        } catch (\OCP\TaskProcessing\Exception\Exception) {
            throw new DomainException('ai_task_not_found');
        }
        if ($task->getAppId() !== 'librefinance' ||
            $task->getCustomId() !== 'space:' . $spaceId) {
            throw new DomainException('ai_task_not_found');
        }
        $result = [
            'taskId' => $taskId,
            'status' => match ($task->getStatus()) {
                Task::STATUS_SCHEDULED => 'scheduled',
                Task::STATUS_RUNNING => 'running',
                Task::STATUS_SUCCESSFUL => 'successful',
                Task::STATUS_FAILED => 'failed',
                Task::STATUS_CANCELLED => 'cancelled',
                default => 'unknown',
            },
        ];
        if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
            $value = $task->getOutput()['output'] ?? null;
            $result['output'] = is_string($value) ? mb_substr($value, 0, 8000) : null;
        }
        return $result;
    }

    /** @param array<string,int|string> $totals */
    private function fingerprint(int $spaceId, string $uid, array $totals): string {
        $key = $this->config->getSystemValueString('secret', '');
        if ($key === '') {
            throw new DomainException('ai_provider_unavailable');
        }
        $payload = json_encode(
            ['scope' => 'librefinance:ai-preview:v1', 'spaceId' => $spaceId,
                'actorUid' => $uid, 'data' => $totals],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        return hash_hmac('sha256', $payload, $key);
    }

    private function available(): bool {
        return in_array(TextToText::ID, $this->manager->getAvailableTaskTypeIds(), true);
    }

    private static function date(string $value): void {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('invalid_date');
        }
    }
}
