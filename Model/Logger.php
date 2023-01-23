<?php

declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\Serialize\Serializer\Json;

class Logger extends Monolog
{
    protected const DEBUG_ENABLED_PATH = 'payment/ivy/debug';
    protected $scopeConfig;
    protected $json;
    protected $isEnabled = null;
    protected $keptRequestForActions = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        string $name,
        array $handlers = [],
        array $processors = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        parent::__construct($name, $handlers, $processors);
    }

    public function addRecord(
        $level,
        $message,
        array $context = []
    ): bool {
        if ($this->isEnabled === null) {
            $this->isEnabled = (bool) $this->scopeConfig->getValue(self::DEBUG_ENABLED_PATH);
        }

        if ($this->isEnabled) {
            return parent::addRecord($level, $message, $context);
        }
        return false;
    }

    public function debugApiAction(
        \Magento\Framework\App\Action\Action $controller,
        string $orderId,
        string $message,
        array $context = []
    ) {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $controller->getRequest();
        $actionId = $request->getControllerName() . '_' . $request->getActionName();

        $allContextData = [
            'context' => $context
        ];

        if (!in_array($actionId, $this->keptRequestForActions)) {
            $allContextData['request'] = $this->getRequestData($request);
            $this->keptRequestForActions[] = $actionId;
        }

        $message = sprintf('#%s %s: %s',
            $orderId,
            $actionId,
            $message
        );
        $this->debug($message, $allContextData);
    }

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
