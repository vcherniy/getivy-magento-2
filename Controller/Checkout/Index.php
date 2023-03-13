<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use Esparksinc\IvyPayment\Model\Api\CreateCheckoutSessionService;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

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
     * @param CreateCheckoutSessionService $createCheckoutSessionService
     */
    public function __construct(
        Context                      $context,
        JsonFactory                  $jsonFactory,
        Session                      $checkoutSession,
        CreateCheckoutSessionService $createCheckoutSessionService
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->createCheckoutSessionService = $createCheckoutSessionService;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface|void
     * @throws Exception
     */
    public function execute()
    {
        $express = (bool)$this->getRequest()->getParam('express');
        $quote = $this->checkoutSession->getQuote();

        $responseData = $this->createCheckoutSessionService->execute($quote, $express, $this);

        if ($responseData) {
            return $this->jsonFactory->create()->setData(['redirectUrl' => $responseData['redirectUrl']]);
        }
    }
}
