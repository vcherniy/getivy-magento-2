<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Esparksinc\IvyPayment\Controller\Fail;

use Magento\Framework\App\RequestInterface;

/**
 * Billing agreements controller
 */
class Index extends \Magento\Framework\App\Action\Action
{
    protected $orderManagement;
    protected $orderFactory;
    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\OrderFactory $orderFactory
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
