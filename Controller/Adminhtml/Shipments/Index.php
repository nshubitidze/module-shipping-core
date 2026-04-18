<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Controller\Adminhtml\Shipments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin shipments grid landing page.
 *
 * Renders the `shubo_shipping_shipments_listing` ui_component via the
 * standard layout handle `shubo_shipping_admin_shipments_index`.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_ShippingCore::shipments';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Shubo_ShippingCore::shipments');
        $resultPage->getConfig()->getTitle()->prepend((string)__('Shipments'));

        return $resultPage;
    }
}
