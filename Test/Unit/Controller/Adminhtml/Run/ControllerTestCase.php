<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Controller\Adminhtml\Run;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared admin controller wiring: records redirects and error messages.
 */
abstract class ControllerTestCase extends TestCase
{
    protected array $redirect = [];
    protected array $errors = [];
    protected Redirect $redirectResult;

    protected function context(array $params = []): Context
    {
        $this->redirect = [];
        $this->errors = [];

        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use (&$redirect) {
            $this->redirect = ['path' => $path];
            return $redirect;
        });
        $redirect->method('setUrl')->willReturnCallback(function ($url) use (&$redirect) {
            $this->redirect = ['url' => $url];
            return $redirect;
        });
        $this->redirectResult = $redirect;
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use (&$messages) {
            $this->errors[] = (string) $message;
            return $messages;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);

        return $context;
    }
}
