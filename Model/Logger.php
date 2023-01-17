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

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        string $name,
        array $handlers = [],
        array $processors = [],
        ?DateTimeZone $timezone = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        parent::__construct($name, $handlers, $processors, $timezone);
    }

    public function addRecord(
        int $level,
        string $message,
        array $context = [],
        DateTimeImmutable $datetime = null
    ): bool {
        if ($this->isEnabled === null) {
            $this->isEnabled = (bool) $this->scopeConfig->getValue(self::DEBUG_ENABLED_PATH);
        }

        if ($this->isEnabled) {
            return parent::addRecord($level, $message, $context, $datetime);
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
            'request' => [],
            'context' => $context
        ];

        $content = (string)$request->getContent();
        if ($content) {
            try {
                $content = $this->json->unserialize($content);
                $allContextData['request']['json'] = $content;
            } catch (\InvalidArgumentException $_) {
                $allContextData['request']['content'] = $content;
            }
        }

        $params = $request->getParams();
        if ($params) {
            $allContextData['request']['params'] = $params;
        }

        $message = sprintf('#%s: %s (%d_%d)',
            $orderId,
            $message,
            $request->getControllerName(),
            $request->getActionName()
        );
        $this->debug($message, $allContextData);
    }
}
