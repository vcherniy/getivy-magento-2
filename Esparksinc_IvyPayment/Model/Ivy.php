<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Model;

use Magento\Framework\Model\AbstractModel;

class Ivy extends AbstractModel
{

    /**
     * @inheritDoc
     */
    public function _construct()
    {
        $this->_init(\Esparksinc\IvyPayment\Model\ResourceModel\Ivy::class);
    }
}