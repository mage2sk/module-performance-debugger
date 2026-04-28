<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Panth\PerformanceDebugger\Model\RunRepository;

class RunDetail extends Template
{
    public function __construct(
        Context $context,
        private readonly RunRepository $repository,
        private readonly RequestInterface $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRun(): ?array
    {
        return $this->repository->getById((int) $this->request->getParam('id'));
    }

    public function getEvents(): array
    {
        return $this->repository->getEvents((int) $this->request->getParam('id'));
    }

    public function getXlsUrl(): string
    {
        return $this->getUrl('performancedebugger/run/exportXls', ['id' => $this->request->getParam('id')]);
    }

    public function getPdfUrl(): string
    {
        return $this->getUrl('performancedebugger/run/exportPdf', ['id' => $this->request->getParam('id')]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('performancedebugger/run/index');
    }
}
