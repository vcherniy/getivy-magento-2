<?php

declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\Serialize\Serializer\Json;
use Monolog\DateTimeImmutable;

class Logger extends Monolog
{
    private const DEBUG_ENABLED_PATH = 'payment/ivy/debug';

    private ScopeConfigInterface $scopeConfig;
    private Json $json;
    private ?bool $isEnabled = null;
    private bool $alreadyKeptRequest = false;

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
        $request = $controller->getRequest();

        $allContextData = [
            'context' => $context
        ];

        if (!$this->alreadyKeptRequest) {
            $allContextData['request'] = $this->getRequestData($request);
            $this->alreadyKeptRequest = true;
        }

        $message = sprintf('#%s: %s (%s_%s)',
            $orderId,
            $message,
            $request->getControllerName(),
            $request->getActionName()
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
