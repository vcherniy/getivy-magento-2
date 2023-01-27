<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;

class Discount extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $cartTotalRepository;
    protected $moduleManager;
    protected $objectManager;

    public function __construct(
        CartTotalRepository     $cartTotalRepository,
        ModuleManager           $moduleManager,
        ObjectManagerInterface  $objectManager,
        Context                 $context
    ) {
        $this->cartTotalRepository = $cartTotalRepository;
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
        parent::__construct($context);
    }

    public function applyCouponCode(string $couponCode, CartInterface $quote)
    {
        $couponApplied = false;

        /*
         * The Amasty Gift Card module implements its own logic that is not compatible with the coupons' core logic.
         * We need work with the module directly
         */
        if ($this->moduleManager->isEnabled('Amasty_GiftCardAccount')) {
            $giftCardAccountManagement = $this->objectManager->create(
                'Amasty\GiftCardAccount\Model\GiftCardAccount\GiftCardAccountManagement'
            );

            try {
                $giftCardAccountManagement->applyGiftCardToCart($quote->getId(), $couponCode);
                $couponApplied = true;
            } catch (CouldNotSaveException $exception) {
                // do nothing
            }
        }

        if (!$couponApplied) {
            $quote->setCouponCode($couponCode);
        }
    }

    public function getDiscountAmount(CartInterface $quote)
    {
        if ($this->moduleManager->isEnabled('Amasty_GiftCardAccount')) {
            $discountAmount = $quote->getExtensionAttributes()
                ->getAmGiftcardQuote()
                ->getBaseGiftAmountUsed();
        } else {
            $totals = $this->cartTotalRepository->get($quote->getId());
            $discountAmount = $totals->getDiscountAmount();
        }
        return $discountAmount;
    }
}
