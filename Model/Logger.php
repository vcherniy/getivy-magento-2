<?php

declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\Serialize\Serializer\Json;

class Logger
{
    protected const DEBUG_ENABLED_PATH = 'payment/ivy/debug';
    protected $scopeConfig;
    protected $logger;
    protected $json;
    protected $isEnabled = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        Monolog $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * @param Action|string $initiatorName
     * @param string $orderId
     * @param string $message
     * @param array $context
     * @return void
     */
    public function debugApiAction(
        $initiatorName,
        string $orderId,
        string $message,
        array $context = []
    ) {
        if (!$this->scopeConfig->isSetFlag(self::DEBUG_ENABLED_PATH)) {
            return;
        }

        if ($initiatorName instanceof Action) {
            /** @var \Magento\Framework\App\Request\Http $request */
            $request = $initiatorName->getRequest();
            $initiatorName = $request->getControllerName() . '_' . $request->getActionName();
        }

        $message = sprintf('#%s %s: %s',
            $orderId,
            $initiatorName,
            $message
        );
        $this->logger->debug($message, $context);
    }

    /**
     * @param Action $controller
     * @param string $orderId
     * @return void
     */
    public function debugRequest(
        Action $controller,
        string $orderId
    ) {
        if (!$this->scopeConfig->isSetFlag(self::DEBUG_ENABLED_PATH)) {
            return;
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $controller->getRequest();
        $requestData = $this->getRequestData($request);
        $this->debugApiAction($controller, $orderId, 'Request', $requestData);
    }

    /**
     * @param RequestInterface $request
     * @return array
     */
    protected function getRequestData($request): array
    {
        $result = [];
        $content = (string)$request->getContent();
        if ($content) {
            try {
                $content = $this->json->unserialize($content);
                $result['json'] = $content;
            } catch (\InvalidArgumentException $_) {
                $result['content'] = $content;
            }
        }

        $params = $request->getParams();
        if ($params) {
            $result['params'] = $params;
        }
        return $result;
    }
}
