<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Esparksinc\IvyPayment\Controller\Fail;

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

    /**
     * @param Context $context
     * @param OrderManagementInterface $orderManagement
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        Context $context,
        OrderManagementInterface $orderManagement,
        OrderFactory $orderFactory
    ) {
        $this->orderManagement = $orderManagement;
        $this->orderFactory = $orderFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');

        $order = $this->orderFactory->create()->loadByIncrementId($magentoOrderId);
        if($order->getId())
        $this->orderManagement->cancel($order->getId());

        $this->messageManager->addErrorMessage(__('Sorry, but something went wrong during payment process'));

        $this->_redirect('checkout/cart');
    }
}
