<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\CaptureGate;

class Session extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_PerformanceDebugger::runs';

    public function __construct(
        Context $context,
        private readonly CaptureGate $captureGate,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();

        try {
            $storeId = (int) $this->getRequest()->getParam('store');
            $store = $storeId > 0
                ? $this->storeManager->getStore($storeId)
                : $this->storeManager->getDefaultStoreView();
            if ($store === null || !(int) $store->getId()) {
                throw new \InvalidArgumentException('Unknown store view.');
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('The selected store view does not exist.'));
            return $redirect->setPath('*/*/index');
        }

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(
                __('Enable the profiler first under Stores > Configuration > Panth Extensions > Performance Debugger.')
            );
            return $redirect->setPath('*/*/index');
        }

        $baseUrl = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        if ($this->getRequest()->getParam('stop')) {
            return $redirect->setUrl($baseUrl . $separator . CaptureGate::PARAM . '=0');
        }

        $token = $this->captureGate->createToken($this->config->sessionLifetimeHours() * 3600);

        return $redirect->setUrl($baseUrl . $separator . CaptureGate::PARAM . '=' . rawurlencode($token));
    }
}
