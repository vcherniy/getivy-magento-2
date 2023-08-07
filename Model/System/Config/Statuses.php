<?php

namespace Esparksinc\IvyPayment\Model\System\Config;

use Magento\Sales\Model\Order\Config;

class Statuses implements \Magento\Framework\Option\ArrayInterface
{
    const PAID = 'paid';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @param Config $config
     */
    public function __construct(
        Config $config
    ) {
        $this->config = $config;
    }

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $result = [];
        foreach ($this->toArray() as $status => $label) {
            $result[] = ['value' => $status, 'label' => $label];
        }
        return $result;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $statuses = $this->config->getStateStatuses('new');
        $statuses = array_merge($statuses, $this->config->getStateStatuses('pending_payment'));
        $statuses = array_merge($statuses, $this->config->getStateStatuses('processing'));
        $statuses[self::PAID] = (string)__('Paid');
        unset($statuses['fraud']);
        return $statuses;
    }
}
