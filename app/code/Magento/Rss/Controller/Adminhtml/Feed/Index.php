<?php
/**
 *
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Rss\Controller\Adminhtml\Feed;

use Magento\Framework\App\Action\NotFoundException;

/**
 * Class Index
 * @package Magento\Rss\Controller\Feed
 */
class Index extends \Magento\Rss\Controller\Adminhtml\Feed
{
    /**
     * Index action
     *
     * @return void
     * @throws NotFoundException
     */
    public function execute()
    {
        if (!$this->scopeConfig->getValue('rss/config/active', \Magento\Framework\Store\ScopeInterface::SCOPE_STORE)) {
            throw new NotFoundException();
        }

        $type = $this->getRequest()->getParam('type');
        try {
            $provider = $this->rssManager->getProvider($type);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundException($e->getMessage());
        }

        if (!$provider->isAllowed()) {
            throw new NotFoundException();
        }

        /** @var $rss \Magento\Rss\Model\Rss */
        $rss = $this->rssFactory->create();
        $rss->setDataProvider($provider);

        $this->getResponse()->setHeader('Content-type', 'text/xml; charset=UTF-8');
        $this->getResponse()->setBody($rss->createRssXml());
    }
}
