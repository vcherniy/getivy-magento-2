<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Helper;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\Context;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository;

class Quote extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $searchCriteriaBuilder;
    protected $quoteRepository;
    protected $quoteFactory;

    public function __construct(
        Context $context,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        QuoteFactory $quoteFactory,
        QuoteRepository $quoteRepository
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        parent::__construct($context);
    }

    public function getQuote(string $magentoOrderId, $metadataQuoteId = null)
    {
        if (!$metadataQuoteId) {
            $metadataQuoteId = $this->getQuoteId($magentoOrderId);
        }
        return $this->quoteRepository->get($metadataQuoteId);
    }

    private function getQuoteId(string $reservedOrderId): int
    {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('reserved_order_id', $reservedOrderId)->create();
        $quotes = $this->quoteRepository->getList($searchCriteria)->getItems();

        if (count($quotes) === 1) {
            $quote = array_values($quotes)[0];
        } else {
            $quote = $this->quoteFactory->create()->load($reservedOrderId, 'reserved_order_id');
        }

        return $quote->getId();
    }
}
