<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Esparksinc\IvyPayment\Controller\Fail;

use Esparksinc\IvyPayment\Model\Logger;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\OrderFactory;

/**
 * Billing agreements controller
 */
class Index extends Action
{
    protected $orderManagement;
    protected $orderFactory;
    protected $logger;

    /**
     * @param Context $context
     * @param OrderManagementInterface $orderManagement
     * @param OrderFactory $orderFactory
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        OrderManagementInterface $orderManagement,
        OrderFactory $orderFactory,
        Logger $logger
    ) {
        $this->orderManagement = $orderManagement;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');

        $this->logger->debugRequest($this, $magentoOrderId);

        $order = $this->orderFactory->create()->loadByIncrementId($magentoOrderId);
        if($order->getId())
        $this->orderManagement->cancel($order->getId());

        $this->messageManager->addErrorMessage(__('Sorry, but something went wrong during payment process'));

        $this->_redirect('checkout/cart');
    }
}
