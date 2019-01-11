<?php
/**
 * Copyright 2018 Vipps
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 *  documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 *  the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
 *  and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED
 *  TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL
 *  THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 *  CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 *  IN THE SOFTWARE.
 *
 */

namespace Vipps\Payment\Cron;

use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\Exception\{NoSuchEntityException};
use Magento\Quote\Api\{CartRepositoryInterface};
use Magento\Quote\Model\{Quote, ResourceModel\Quote\Collection, ResourceModel\Quote\CollectionFactory};
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Vipps\Payment\{Api\CommandManagerInterface,
    Gateway\Exception\VippsException,
    Gateway\Transaction\Transaction,
    Gateway\Transaction\TransactionBuilder,
    Model\Monitoring\Quote\CancellationRepository,
    Model\Order\Cancellation\Config,
    Model\OrderPlace};
use Vipps\Payment\Model\Monitoring\Quote\AttemptManagement;
use Vipps\Payment\Model\Monitoring\Quote\CancellationFactory;
use Vipps\Payment\Model\Monitoring\QuoteManagement as QuoteMonitorManagement;
use Vipps\Payment\Model\Monitoring\QuoteRepository as QuoteMonitorRepository;
use Vipps\Payment\Model\ResourceModel\Monitoring\Quote\Cancellation\Type as CancellationTypeResource;

/**
 * Class FetchOrderStatus
 * @package Vipps\Payment\Cron
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CancelQuoteByAttempts
{
    /**
     * Order collection page size
     */
    const COLLECTION_PAGE_SIZE = 100;

    /**
     * @var CollectionFactory
     */
    private $quoteCollectionFactory;

    /**
     * @var CommandManagerInterface
     */
    private $commandManager;

    /**
     * @var TransactionBuilder
     */
    private $transactionBuilder;

    /**
     * @var OrderPlace
     */
    private $orderPlace;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeCodeResolver
     */
    private $scopeCodeResolver;
    /**
     * @var QuoteMonitorManagement
     */
    private $quoteManagement;
    /**
     * @var Config
     */
    private $cancellationConfig;
    /**
     * @var CancellationFactory
     */
    private $cancellationFactory;
    /**
     * @var CancellationRepository
     */
    private $cancellationRepository;
    /**
     * @var QuoteMonitorRepository
     */
    private $quoteMonitorRepository;
    /**
     * @var AttemptManagement
     */
    private $attemptManagement;
    /**
     * @var \Vipps\Payment\Model\Monitoring\Quote\CancellationFacade
     */
    private $cancellationFacade;

    /**
     * FetchOrderFromVipps constructor.
     *
     * @param CollectionFactory $quoteCollectionFactory
     * @param CommandManagerInterface $commandManager
     * @param TransactionBuilder $transactionBuilder
     * @param OrderPlace $orderManagement
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param ScopeCodeResolver $scopeCodeResolver
     */
    public function __construct(
        CollectionFactory $quoteCollectionFactory,
        CommandManagerInterface $commandManager,
        TransactionBuilder $transactionBuilder,
        OrderPlace $orderManagement,
        CartRepositoryInterface $cartRepository,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ScopeCodeResolver $scopeCodeResolver,
        QuoteMonitorManagement $quoteManagement,
        Config $cancellationConfig,
        CancellationFactory $cancellationFactory,
        CancellationRepository $cancellationRepository,
        QuoteMonitorRepository $quoteMonitorRepository,
        AttemptManagement $attemptManagement,
        \Vipps\Payment\Model\Monitoring\Quote\CancellationFacade $cancellationFacade
    ) {
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->commandManager = $commandManager;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderPlace = $orderManagement;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->scopeCodeResolver = $scopeCodeResolver;
        $this->quoteManagement = $quoteManagement;
        $this->cancellationConfig = $cancellationConfig;
        $this->cancellationFactory = $cancellationFactory;
        $this->cancellationRepository = $cancellationRepository;
        $this->quoteMonitorRepository = $quoteMonitorRepository;
        $this->attemptManagement = $attemptManagement;
        $this->cancellationFacade = $cancellationFacade;
    }

    /**
     * Create orders from Vipps that are not created in Magento yet
     *
     * @throws NoSuchEntityException
     * @throws \Exception
     */
    public function execute()
    {
        try {
            $currentStore = $this->storeManager->getStore()->getId();
            $currentPage = 1;
            do {
                $quoteCollection = $this->createCollection($currentPage);
                $this->logger->debug(
                    'Fetched quote collection to cancel',
                    ['current page' => $currentPage],
                    ['collection count' => $quoteCollection->count()]
                );
                foreach ($quoteCollection as $quote) {
                    $this->processQuote($quote);
                    usleep(1000000); //delay for 1 second
                }
                $currentPage++;
            } while ($currentPage <= $quoteCollection->getLastPageNumber());
        } finally {
            $this->storeManager->setCurrentStore($currentStore);
        }
    }

    /**
     * Get quote collection to cancel.
     *
     * @param $currentPage
     *
     * @return Collection
     */
    private function createCollection($currentPage)
    {
        /** @var Collection $collection */
        $collection = $this->quoteCollectionFactory->create();

        $collection
            ->setPageSize(self::COLLECTION_PAGE_SIZE)
            ->setCurPage($currentPage)
            ->addFieldToSelect(['entity_id', 'reserved_order_id', 'store_id', 'updated_at'])
            ->join(
                ['p' => $collection->getTable('quote_payment')],
                'main_table.entity_id = p.quote_id',
                ['p.method']
            )
            ->addFieldToFilter('p.method', ['eq' => 'vipps'])
            ->join(
                ['vq' => $collection->getTable('vipps_quote')],
                'main_table.entity_id = vq.quote_id',
                ['vq.attempts']
            )
            ->addFieldToFilter(
                'vq.attempts',
                ['gteq' => $this->cancellationConfig->getAttemptsMaxCount()]
            );

        // Filter not cancelled quotes.
        $collection
            ->getSelect()
            ->joinLeft(
                ['vqc' => $collection->getTable('vipps_quote_cancellation')],
                'vq.entity_id = vqc.parent_id',
                []
            );
        $collection->addFieldToFilter('vqc.entity_id', ['null' => 1]);


        return $collection;
    }

    /**
     * Main process
     *
     * @param Quote $quote
     *
     * @throws NoSuchEntityException
     * @throws \Exception
     */
    private function processQuote(Quote $quote)
    {
        $transaction = null;
        $this->logger->info('Start quote cancelling', ['quote_id' => $quote->getId()]);

        try {
            // Load vipps quote monitoring as extension attribute.
            $this->quoteManagement->loadExtensionAttribute($quote);

            $this->prepareEnv($quote);

            $transaction = $this->fetchOrderStatus($quote->getReservedOrderId());
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage(), ['quote_id' => $quote->getId()]);
        } finally {
            $this
                ->cancellationFacade
                ->cancelMagento(
                    $quote,
                    CancellationTypeResource::MAGENTO,
                    __('Number of attempts reached: %1', $this->cancellationConfig->getAttemptsMaxCount()),
                    $transaction
                );
        }
    }

    /**
     * Prepare environment.
     *
     * @param Quote $quote
     */
    private function prepareEnv(Quote $quote)
    {
        // set quote store as current store
        $this->scopeCodeResolver->clean();
        $this->storeManager->setCurrentStore($quote->getStore()->getId());
    }

    /**
     * @param $orderId
     *
     * @return Transaction
     * @throws VippsException
     */
    private function fetchOrderStatus($orderId)
    {
        $response = $this->commandManager->getOrderStatus($orderId);
        return $this->transactionBuilder->setData($response)->build();
    }

    /**
     * @param Quote $quote
     * @param \DateInterval $interval
     *
     * @return bool
     */
//    private function isQuoteExpired(Quote $quote, \DateInterval $interval) //@codingStandardsIgnoreLine
//    {
//        $quoteExpiredAt = (new \DateTime($quote->getUpdatedAt()))->add($interval); //@codingStandardsIgnoreLine
//        $isQuoteExpired = !$quoteExpiredAt->diff(new \DateTime())->invert; //@codingStandardsIgnoreLine
//        return $isQuoteExpired;
//    }
}
