<?php

declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class Debug
{
    private const DEBUG_ENABLED_PATH = 'payment/ivy/debug';

    private LoggerInterface $logger;
    private ScopeConfigInterface $scopeConfig;
    private ?bool $isEnabled = null;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface      $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param string $message
     * @param array|null $data
     * @return void
     */
    public function log(string $message, ?array $data): void
    {
        if ($this->isEnabled === null) {
            $this->isEnabled = (bool) $this->scopeConfig->getValue(self::DEBUG_ENABLED_PATH);
        }

        if ($this->isEnabled) {
            $this->logger->info($message, $data);
        }
    }
}
