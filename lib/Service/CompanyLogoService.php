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
 * Company logos are private to a finance space and stored in Nextcloud AppData.
 * There is no public URL, external host, SVG, local file path, or shared user folder.
 */
final class CompanyLogoService {
    private const MAX_BYTES = 524288;
    private const MAX_SIDE = 2048;

    public function __construct(private IDBConnection $db,
        private AccessService $access, private WriteService $writer,
        private IAppData $appData) {}

    public function upload(int $spaceId, int $companyId, string $imageBase64): array {
        $this->access->requireFinancialCapability($spaceId, 'companies.manage');
        $this->requireCompany($spaceId, $companyId);
        if ($imageBase64 === '' || strlen($imageBase64) > (int)ceil(self::MAX_BYTES * 4 / 3) + 8
            || preg_match('~[^A-Za-z0-9+/=]~', $imageBase64)) {
            throw new DomainException('invalid_company_logo');
        }
        $content = base64_decode($imageBase64, true);
        if ($content === false || strlen($content) === 0 || strlen($content) > self::MAX_BYTES) {
            throw new DomainException('invalid_company_logo');
        }
        self::mimeAndDimensions($content);
        $folder = $this->folder($spaceId, true);
        $name = 'company-' . $companyId;
        if ($folder->fileExists($name)) $folder->getFile($name)->putContent($content);
        else $folder->newFile($name, $content);
        // One signed, random version per change; no global public appdata path.
        $version = random_int(1, 2147483647);
        $this->writer->update('lf_companies',
            ['logo_version' => $version, 'updated_at' => time()],
            ['id' => $companyId, 'space_id' => $spaceId]);
        $this->audit($spaceId, $companyId, 'company.logo_updated');
        return ['companyId' => $companyId, 'logoVersion' => $version];
    }

    /** @return array{content:string,mime:string} */
    public function read(int $spaceId, int $companyId): array {
        $this->access->requireFinancialCapability($spaceId, 'companies.read');
        $company = $this->requireCompany($spaceId, $companyId);
        if ($company['logo_version'] === null) throw new DomainException('company_logo_not_found');
        try {
            $folder = $this->folder($spaceId);
            $file = $folder->getFile('company-' . $companyId);
            if ($file->getSize() > self::MAX_BYTES) throw new DomainException('invalid_company_logo');
            $content = $file->getContent();
            $mime = self::mimeAndDimensions($content);
            return ['content' => $content, 'mime' => $mime];
        } catch (NotFoundException) {
            throw new DomainException('company_logo_not_found');
        }
    }

    public function remove(int $spaceId, int $companyId): array {
        $this->access->requireFinancialCapability($spaceId, 'companies.manage');
        $this->requireCompany($spaceId, $companyId);
        $this->writer->update('lf_companies', ['logo_version' => null, 'updated_at' => time()],
            ['id' => $companyId, 'space_id' => $spaceId]);
        try {
            $folder = $this->folder($spaceId);
            if ($folder->fileExists('company-' . $companyId)) {
                $folder->getFile('company-' . $companyId)->delete();
            }
        } catch (NotFoundException) {}
        $this->audit($spaceId, $companyId, 'company.logo_removed');
        return ['companyId' => $companyId, 'logoVersion' => null];
    }

    /** @return array{logo_version:int|null} */
    private function requireCompany(int $spaceId, int $companyId): array {
        $q = $this->db->getQueryBuilder();
        $q->select('logo_version')->from('lf_companies')
            ->where($q->expr()->eq('id', $q->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $company = $q->executeQuery()->fetchAssociative();
        if (!$company) throw new DomainException('company_not_found');
        return $company;
    }

    private function folder(int $spaceId, bool $create = false): ISimpleFolder {
        try {
            $root = $this->appData->getFolder('company-logos');
        } catch (NotFoundException) {
            if (!$create) throw new DomainException('company_logo_not_found');
            try { $root = $this->appData->newFolder('company-logos'); }
            catch (\Throwable) { $root = $this->appData->getFolder('company-logos'); }
        }
        try { return $root->getFolder('space-' . $spaceId); }
        catch (NotFoundException) {
            if (!$create) throw new DomainException('company_logo_not_found');
            return $root->getOrCreateFolder('space-' . $spaceId);
        }
    }

    private static function mimeAndDimensions(string $data): string {
        $image = @getimagesizefromstring($data);
        if (!is_array($image) || count($image) < 3
            || (int)$image[0] < 1 || (int)$image[1] < 1
            || (int)$image[0] > self::MAX_SIDE || (int)$image[1] > self::MAX_SIDE) {
            throw new DomainException('invalid_company_logo');
        }
        $mime = (string)($image['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new DomainException('invalid_company_logo');
        }
        return $mime;
    }

    private function audit(int $spaceId, int $companyId, string $action): void {
        $this->writer->insert('lf_audit', ['space_id' => $spaceId,
            'actor_uid' => $this->access->uid(), 'action' => $action,
            'entity' => 'company', 'entity_id' => $companyId, 'occurred_at' => time()]);
    }
}
