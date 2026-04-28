<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Panth\PerformanceDebugger\Model\RunRepository;

class View extends Action
{
    public const ADMIN_RESOURCE = 'Panth_PerformanceDebugger::runs';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RunRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $runId = (int) $this->getRequest()->getParam('id');
        if (!$runId || !$this->repository->getById($runId)) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_PerformanceDebugger::runs');
        $page->getConfig()->getTitle()->prepend(__('Profiler Run #%1', $runId));
        return $page;
    }
}
