<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Convert\ExcelFactory;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunRepository;

/**
 * Streams an Excel-compatible SpreadsheetML file built from a profiler run.
 *
 * Magento\Framework\Convert\Excel emits Microsoft's XML Spreadsheet 2003
 * format. Excel and LibreOffice both open .xls files in this format
 * natively without any third-party PHP library on the server.
 */
class ExportXls extends Action
{
    public const ADMIN_RESOURCE = 'Panth_PerformanceDebugger::export';

    public function __construct(
        Context $context,
        private readonly RunRepository $repository,
        private readonly ExcelFactory $excelFactory,
        private readonly FileFactory $fileFactory,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $runId = (int) $this->getRequest()->getParam('id');
        $run = $this->repository->getById($runId);
        if (!$run || !$this->config->enableXls()) {
            $this->messageManager->addErrorMessage(__('Run not found or XLS export disabled.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $events = $this->repository->getEvents($runId);
        $rows = [['Kind', 'Label', 'Source', 'Duration (ms)', 'Invocations']];
        foreach ($events as $e) {
            $rows[] = [
                (string) $e['kind'],
                (string) $e['label'],
                (string) ($e['source'] ?? ''),
                (float) $e['duration'],
                (int) $e['invocations'],
            ];
        }

        $iterator = new \ArrayIterator($rows);
        $excel = $this->excelFactory->create(['iterator' => $iterator]);
        $content = $excel->convert('panth_perf_run_' . $runId);

        return $this->fileFactory->create(
            'panth_perf_run_' . $runId . '.xls',
            $content,
            \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
            'application/vnd.ms-excel'
        );
    }
}
