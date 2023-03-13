<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use Esparksinc\IvyPayment\Model\Api\CreateCheckoutSessionService;
use Esparksinc\IvyPayment\Model\Logger;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;

class Index extends Action
{
    protected $jsonFactory;
    protected $checkoutSession;
    protected $logger;
    protected $createCheckoutSessionService;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Session $checkoutSession
     * @param CartTotalRepository $cartTotalRepository
     * @param Logger $logger
     * @param CreateCheckoutSessionService $createCheckoutSessionService
     */
    public function __construct(
        Context                      $context,
        JsonFactory                  $jsonFactory,
        Session                      $checkoutSession,
        CartTotalRepository          $cartTotalRepository,
        Logger                       $logger,
        CreateCheckoutSessionService $createCheckoutSessionService
    ) {
        $this->logger = $logger;
        $this->createCheckoutSessionService = $createCheckoutSessionService;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface|void
     * @throws Exception
     */
    public function execute()
    {
        $express = $this->getRequest()->getParam('express');
        $quote = $this->checkoutSession->getQuote();

        $responseData = $this->createCheckoutSessionService->execute($quote, $express, $this);

        if ($responseData) {
            return $this->jsonFactory->create()->setData(['redirectUrl' => $responseData['redirectUrl']]);
        }
    }
}
