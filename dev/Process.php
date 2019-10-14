<?php
namespace Drmax\PaymentCsob\Controller\Redirect;

use Magento\Framework\App\Action\Context;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface as Logger;

/**
 * Class Process
 * @package Drmax\PaymentCsob\Controller\Redirect
 */
class Process extends \Magento\Framework\App\Action\Action
{
    const ORDER_ID_PARAM = 'order';
    const ORDER_INCREMENT_ID_PARAM = 'orderId';
    const QUOTE_ID_PARAM = 'qid';
    const LOGGER_PREFIX = 'CSOB_payment_gateway::Redirect/Process - ';

    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var CommandPoolInterface
     */
    private $commandPool;
    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;
    /**
     * @var MaskedQuoteIdToQuoteIdInterface
     */
    protected $maskToQuoteId;
    /**
     * @var OrderFactory
     */
    protected $orderFactory;
    /**
     * @var OrderInterface
     */
    protected $order;
    /**
     * @var PaymentDataObjectFactory
     */
    private $paymentDataObjectFactory;

    /**
     * Status constructor.
     * @param Logger $logger
     * @param Context $context
     * @param CommandPoolInterface|null $commandPool
     * @param PaymentDataObjectFactory $paymentDataObjectFactory
     * @param QuoteFactory $quoteFactory
     * @param MaskedQuoteIdToQuoteIdInterface $maskToQuoteId
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        Logger $logger,
        Context $context,
        CommandPoolInterface $commandPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        QuoteFactory $quoteFactory,
        MaskedQuoteIdToQuoteIdInterface $maskToQuoteId,
        OrderFactory $orderFactory
    ) {
        $this->logger = $logger;
        $this->commandPool = $commandPool;
        $this->paymentDataObjectFactory = $paymentDataObjectFactory;
        $this->quoteFactory = $quoteFactory;
        $this->maskToQuoteId = $maskToQuoteId;
        $this->orderFactory = $orderFactory;
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $order = $this->getOrder();
        $payment = $order->getPayment();

        $buildSubject = [
            'order' => $order,
            'payment' => $this->paymentDataObjectFactory->create($payment)
        ];
        // this command should redirect to the Gateway
        $this->commandPool->get('process')->execute($buildSubject);

        // if the redirection failed
        $this->logger->error(self::LOGGER_PREFIX . 'Redirection to the gateway failed | order: %context.incrementId%', ['incrementId' => $order->getIncrementId()]);
        die;
    }


    /**
     * Load the order from params
     *
     * @return OrderInterface|\Magento\Sales\Model\Order
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getOrder()
    {
        if (!$this->order) {
            $orderEntityId = $this->getRequest()->getParam(self::ORDER_ID_PARAM);
            $orderIncrementId = null;

            if ($orderEntityId){
                $this->order = $this->orderFactory->create()->loadByAttribute('entity_id', $orderEntityId);
            } elseif ($orderIncrementId = $this->getOrderIdByQuoteId()){
                $this->order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            } elseif ($orderIncrementId = $this->getRequest()->getParam(self::ORDER_INCREMENT_ID_PARAM)){
                $this->order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            } else {
                $this->logger->error(self::LOGGER_PREFIX . 'no order or quote provided');
            }
            $logContext = [
                'orderEntityId' => $orderEntityId,
                'orderIncrementId' => $orderIncrementId
            ];

            if (!$this->order || !$this->order->getEntityId()) {
                $this->logger->error(self::LOGGER_PREFIX . 'no order loaded', $logContext);
                throw new \InvalidArgumentException('no order loaded');
            }
        }

        return $this->order;
    }


    /**
     * Return Order Increment ID from given Quote ID param
     *
     * @return mixed|string|null
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getOrderIdByQuoteId()
    {
        $quoteId = $this->getRequest()->getParam(self::QUOTE_ID_PARAM);

        if ($quoteId){
            // since 2.3.1 quoteId is being sent as masked_quote_id
            if ( (strlen($quoteId) > 20) && ((string)$quoteId !== (string)(int)$quoteId) ){
                $quoteId = $this->maskToQuoteId->execute($quoteId);
            }
            $logContext = ['quote ID' => $quoteId];
            $this->logger->debug(self::LOGGER_PREFIX . 'loading quote by its ID', $logContext);

            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
            if ($quote->getId() && $quote->getReservedOrderId()){
                $logContext['Order ID'] = $quote->getReservedOrderId();
                $this->logger->debug(self::LOGGER_PREFIX . 'quote loaded', $logContext);

                return $quote->getReservedOrderId();

            } else {
                $this->logger->error(self::LOGGER_PREFIX . 'unable to load quote or get its order id', $logContext);
            }
        }

        return null;
    }
}