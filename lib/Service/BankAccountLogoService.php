<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

/**
 * Account logos are private to a finance space and stored in Nextcloud AppData.
 * There is no public URL, external host, SVG, local file path, or shared user folder.
 */
final class BankAccountLogoService {
    private const MAX_BYTES = 524288;
    private const MAX_SIDE = 2048;

    public function __construct(private IDBConnection $db,
        private AccessService $access, private WriteService $writer,
        private IAppData $appData) {}

    public function upload(int $spaceId, int $accountId, string $imageBase64): array {
        $this->access->requireRole($spaceId, 'manager');
        $this->requireAccount($spaceId, $accountId);
        if ($imageBase64 === '' || strlen($imageBase64) > (int)ceil(self::MAX_BYTES * 4 / 3) + 8
            || preg_match('~[^A-Za-z0-9+/=]~', $imageBase64)) {
            throw new DomainException('invalid_account_logo');
        }
        $content = base64_decode($imageBase64, true);
        if ($content === false || strlen($content) === 0 || strlen($content) > self::MAX_BYTES) {
            throw new DomainException('invalid_account_logo');
        }
        self::mimeAndDimensions($content);
        $folder = $this->folder($spaceId, true);
        $name = 'account-' . $accountId;
        if ($folder->fileExists($name)) $folder->getFile($name)->putContent($content);
        else $folder->newFile($name, $content);
        // One signed, random version per change; no global public appdata path.
        $version = random_int(1, 2147483647);
        $this->writer->update('lf_accounts',
            ['logo_version' => $version],
            ['id' => $accountId, 'space_id' => $spaceId]);
        $this->audit($spaceId, $accountId, 'account.logo_updated');
        return ['accountId' => $accountId, 'logoVersion' => $version];
    }

    /** @return array{content:string,mime:string} */
    public function read(int $spaceId, int $accountId): array {
        $this->access->requireRole($spaceId, 'viewer');
        $account = $this->requireAccount($spaceId, $accountId);
        if ($account['logo_version'] === null) throw new DomainException('account_logo_not_found');
        try {
            $folder = $this->folder($spaceId);
            $file = $folder->getFile('account-' . $accountId);
            if ($file->getSize() > self::MAX_BYTES) throw new DomainException('invalid_account_logo');
            $content = $file->getContent();
            $mime = self::mimeAndDimensions($content);
            return ['content' => $content, 'mime' => $mime];
        } catch (NotFoundException) {
            throw new DomainException('account_logo_not_found');
        }
    }

    public function remove(int $spaceId, int $accountId): array {
        $this->access->requireRole($spaceId, 'manager');
        $this->requireAccount($spaceId, $accountId);
        $this->writer->update('lf_accounts', ['logo_version' => null],
            ['id' => $accountId, 'space_id' => $spaceId]);
        try {
            $folder = $this->folder($spaceId);
            if ($folder->fileExists('account-' . $accountId)) {
                $folder->getFile('account-' . $accountId)->delete();
            }
        } catch (NotFoundException) {}
        $this->audit($spaceId, $accountId, 'account.logo_removed');
        return ['accountId' => $accountId, 'logoVersion' => null];
    }

    /** @return array{logo_version:int|null} */
    private function requireAccount(int $spaceId, int $accountId): array {
        $q = $this->db->getQueryBuilder();
        $q->select('logo_version')->from('lf_accounts')
            ->where($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('is_bank', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $account = $q->executeQuery()->fetchAssociative();
        if (!$account) throw new DomainException('account_not_found');
        return $account;
    }

    private function folder(int $spaceId, bool $create = false): ISimpleFolder {
        try {
            $root = $this->appData->getFolder('account-logos');
        } catch (NotFoundException) {
            if (!$create) throw new DomainException('account_logo_not_found');
            try { $root = $this->appData->newFolder('account-logos'); }
            catch (\Throwable) { $root = $this->appData->getFolder('account-logos'); }
        }
        try { return $root->getFolder('space-' . $spaceId); }
        catch (NotFoundException) {
            if (!$create) throw new DomainException('account_logo_not_found');
            return $root->getOrCreateFolder('space-' . $spaceId);
        }
    }

    private static function mimeAndDimensions(string $data): string {
        $image = @getimagesizefromstring($data);
        if (!is_array($image) || count($image) < 3
            || (int)$image[0] < 1 || (int)$image[1] < 1
            || (int)$image[0] > self::MAX_SIDE || (int)$image[1] > self::MAX_SIDE) {
            throw new DomainException('invalid_account_logo');
        }
        $mime = (string)($image['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new DomainException('invalid_account_logo');
        }
        return $mime;
    }

    private function audit(int $spaceId, int $accountId, string $action): void {
        $this->writer->insert('lf_audit', ['space_id' => $spaceId,
            'actor_uid' => $this->access->uid(), 'action' => $action,
            'entity' => 'account', 'entity_id' => $accountId, 'occurred_at' => time()]);
    }
}
