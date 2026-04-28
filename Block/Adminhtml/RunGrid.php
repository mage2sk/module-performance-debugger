<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\PerformanceDebugger\Model\RunRepository;

class RunGrid extends Template
{
    public const DEFAULT_PAGE_SIZE = 50;

    private ?array $cache = null;

    public function __construct(
        Context $context,
        private readonly RunRepository $repository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPage(): int
    {
        return max(1, (int) $this->getRequest()->getParam('p', 1));
    }

    public function getPageSize(): int
    {
        $size = (int) $this->getRequest()->getParam('limit', self::DEFAULT_PAGE_SIZE);
        return in_array($size, [25, 50, 100, 250], true) ? $size : self::DEFAULT_PAGE_SIZE;
    }

    public function getSort(): string
    {
        return (string) $this->getRequest()->getParam('sort', 'created_at');
    }

    public function getDir(): string
    {
        $d = strtoupper((string) $this->getRequest()->getParam('dir', 'DESC'));
        return $d === 'ASC' ? 'ASC' : 'DESC';
    }

    public function getQuery(): string
    {
        return trim((string) $this->getRequest()->getParam('q', ''));
    }

    public function getMinSeverity(): int
    {
        return (int) $this->getRequest()->getParam('sev', 0);
    }

    public function getResult(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        return $this->cache = $this->repository->getPaged(
            $this->getPage(),
            $this->getPageSize(),
            $this->getSort(),
            $this->getDir(),
            ['q' => $this->getQuery(), 'severity' => $this->getMinSeverity()]
        );
    }

    public function getRuns(): array
    {
        return $this->getResult()['rows'] ?? [];
    }

    public function getTotal(): int
    {
        return (int) ($this->getResult()['total'] ?? 0);
    }

    public function getTotalPages(): int
    {
        $total = $this->getTotal();
        $size = $this->getPageSize();
        return $size > 0 ? (int) ceil($total / $size) : 1;
    }

    /**
     * Build a URL to this same grid with overridden params (page, sort, etc.).
     * Always preserves any unspecified existing param so click-toggling sort
     * doesn't drop the active filter or page size.
     */
    public function gridUrl(array $overrides = []): string
    {
        $current = [
            'p' => $this->getPage(),
            'limit' => $this->getPageSize(),
            'sort' => $this->getSort(),
            'dir' => $this->getDir(),
            'q' => $this->getQuery(),
            'sev' => $this->getMinSeverity(),
        ];
        $merged = array_merge($current, $overrides);
        // Drop empty values to keep URLs clean.
        foreach ($merged as $k => $v) {
            if ($v === '' || $v === null || $v === 0 || $v === '0') {
                unset($merged[$k]);
            }
        }
        return $this->getUrl('performancedebugger/run/index', $merged);
    }

    public function sortUrl(string $column): string
    {
        $current = $this->getSort();
        $dir = $this->getDir();
        $newDir = ($current === $column && $dir === 'DESC') ? 'ASC' : 'DESC';
        return $this->gridUrl(['sort' => $column, 'dir' => $newDir, 'p' => 1]);
    }

    public function sortIndicator(string $column): string
    {
        if ($this->getSort() !== $column) {
            return '↕';
        }
        return $this->getDir() === 'ASC' ? '▲' : '▼';
    }

    public function getViewUrl(int $runId): string
    {
        return $this->getUrl('performancedebugger/run/view', ['id' => $runId]);
    }

    public function getXlsUrl(int $runId): string
    {
        return $this->getUrl('performancedebugger/run/exportXls', ['id' => $runId]);
    }

    public function getPdfUrl(int $runId): string
    {
        return $this->getUrl('performancedebugger/run/exportPdf', ['id' => $runId]);
    }
}
