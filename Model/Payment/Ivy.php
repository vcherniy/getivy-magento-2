<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model\Payment;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use GuzzleHttp\Client;

class Ivy extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = "ivy";
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canUseInternal = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    protected $apiHelper;
    protected $config;
    protected $json;

    public function __construct(
        ApiHelper $apiHelper,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Esparksinc\IvyPayment\Model\Config $config,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->apiHelper = $apiHelper;
        $this->json = $json;
        $this->config = $config;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        return parent::isAvailable($quote);
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if (!$this->canRefund()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }

        $order = $payment->getCreditmemo()->getOrder();
        $data = [
            'referenceId' => $order->getIncrementId(),
            'amount' => $amount,
        ];

        $this->apiHelper->requestApi('payment::#' . $payment->getId(), 'merchant/payment/refund', $data, $order->getIncrementId());

        if ($this->apiHelper->getLastStatusCode() !== 200) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Something went wrong'));
        }
        return $this;
    }

    public function canUseForCurrency($currencyCode)
    {
        $currencies = ['EUR'];
        if (in_array($currencyCode, $currencies)) {
            return true;
        }
        return false;
    }

    public function canRefundPartialPerInvoice()
    {
        return $this->_canRefundInvoicePartial;
    }
}