<?php

namespace Heidelpay\Gateway\Controller\Index;

use Heidelpay\Gateway\Helper\Payment as PaymentHelper;
use Heidelpay\Gateway\PaymentMethods\HeidelpayAbstractPaymentMethod;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\Collection;

/**
 * heidelpay Push Controller
 *
 * Receives XML Push requests from the heidelpay Payment API and processes them.
 *
 * @license Use of this software requires acceptance of the License Agreement. See LICENSE file.
 * @copyright Copyright © 2016-present heidelpay GmbH. All rights reserved.
 * @link http://dev.heidelpay.com/magento2
 *
 * @author Stephano Vogel
 *
 * @package heidelpay\magento2\controllers
 */
class Push extends \Heidelpay\Gateway\Controller\HgwAbstract
{
    /** @var OrderRepository $orderRepository */
    private $orderRepository;

    /** @var \Heidelpay\PhpPaymentApi\Push */
    private $heidelpayPush;

    /** @var QuoteRepository */
    private $quoteRepository;
    /** @var \Heidelpay\Gateway\Helper\Order */
    private $orderHelper;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Framework\Url\Helper\Data $urlHelper
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteObject
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param PaymentHelper $paymentHelper
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param OrderCommentSender $orderCommentSender
     * @param \Magento\Framework\Encryption\Encryptor $encryptor
     * @param \Magento\Customer\Model\Url $customerUrl
     * @param OrderRepository $orderRepository
     * @param \Heidelpay\PhpPaymentApi\Push $heidelpayPush
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param QuoteRepository $quoteRepository
     * @param \Heidelpay\Gateway\Helper\Order $orderHelper
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Url\Helper\Data $urlHelper,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Quote\Api\CartRepositoryInterface $quoteObject,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        PaymentHelper $paymentHelper,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        OrderCommentSender $orderCommentSender,
        \Magento\Framework\Encryption\Encryptor $encryptor,
        \Magento\Customer\Model\Url $customerUrl,
        OrderRepository $orderRepository,
        \Heidelpay\PhpPaymentApi\Push $heidelpayPush,
        QuoteRepository $quoteRepository,
        \Heidelpay\Gateway\Helper\Order $orderHelper
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $urlHelper,
            $logger,
            $cartManagement,
            $quoteObject,
            $resultPageFactory,
            $paymentHelper,
            $orderSender,
            $invoiceSender,
            $orderCommentSender,
            $encryptor,
            $customerUrl
        );

        $this->orderRepository = $orderRepository;
        $this->heidelpayPush = $heidelpayPush;
        $this->quoteRepository = $quoteRepository;
        $this->orderHelper = $orderHelper;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws \Heidelpay\PhpPaymentApi\Exceptions\XmlResponseParserException
     */
    public function execute()
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();

        if (!$request->isPost()) {
            $this->_logger->debug('Heidelpay - Push: Response is not post.');
            return;
        }

        if ($request->getHeader('Content-Type') !== 'application/xml') {
            $this->_logger->debug('Heidelpay - Push: Content-Type is not "application/xml"');
        }

        if ($request->getHeader('X-Push-Timestamp') != '' && $request->getHeader('X-Push-Retries') != '') {
            $this->_logger->debug('Heidelpay - Push: Timestamp: "' . $request->getHeader('X-Push-Timestamp') . '"');
            $this->_logger->debug('Heidelpay - Push: Retries: "' . $request->getHeader('X-Push-Retries') . '"');
        }

        try {
            // getContent returns php://input, if no other content is set.
            $this->heidelpayPush->setRawResponse($request->getContent());
        } catch (\Exception $e) {
            $this->_logger->critical(
                'Heidelpay - Push: Cannot parse XML Push Request into Response object. '
                . $e->getMessage()
            );
        }

        $pushResponse = $this->heidelpayPush->getResponse();
        $data = $this->_paymentHelper->getDataFromResponse($pushResponse);
        $this->_logger->debug('Push Response: ' . print_r($pushResponse, true));

        list($paymentMethod, $paymentType) = $this->_paymentHelper->splitPaymentCode(
            $pushResponse->getPayment()->getCode()
        );

                // in case of receipts, we process the push message for receipts.
        if ($pushResponse->isSuccess() && $this->_paymentHelper->isNewOrderType($paymentType)) {

            $transactionId = $pushResponse->getIdentification()->getTransactionId();
            $order = $this->orderHelper->fetchOrder($transactionId);
            $quote = $this->quoteRepository->get($transactionId);

            // create order if it doesn't exists already.
            if ($order === null || $order->isEmpty()) {
                $this->_paymentHelper->saveHeidelpayTransaction($pushResponse, $data, 'PUSH');
                $this->_logger->debug('heidelpay Push - Order does not exist for transaction. heidelpay transaction id: '
                    . $transactionId);

                try {
                    $order = $this->_paymentHelper->createOrderFromQuote($quote);
                    if ($order === null || $order->isEmpty())
                    {
                        $this->_logger->error('Heidelpay - Response: Cannot submit the Quote. ' . $e->getMessage());
                        return;
                    }
                } catch (\Exception $e) {
                    $this->_logger->error('Heidelpay - Response: Cannot submit the Quote. ' . $e->getMessage());
                    return;
                }

                $this->_paymentHelper->mapStatus($data, $order);
                $this->_logger->debug('order status: ' . $order->getStatus());
                $this->orderHelper->handleOrderMail($order);
                $this->orderHelper->handleInvoiceMails($order);
                $this->orderRepository->save($order);
            }
            $this->_paymentHelper->handleAdditionalPaymentInformation($quote);


            if ($this->_paymentHelper->isReceiptAble($paymentMethod, $paymentType)) {
                // load the referenced order to receive the order information.
                $payment = $order->getPayment();

                /** @var HeidelpayAbstractPaymentMethod $methodInstance */
                $methodInstance = $payment->getMethodInstance();
                $uniqueId = $pushResponse->getPaymentReferenceId();

                /** @var bool $transactionExists Flag to identify new Transaction */
                $transactionExists = $methodInstance->heidelpayTransactionExists($uniqueId);

                // If Transaction already exists, push wont be processed.
                if ($transactionExists) {
                    $this->_logger->debug('heidelpay - Push Response: ' . $uniqueId . ' already exists');
                    return;
                }

                $paidAmount = (float)$pushResponse->getPresentation()->getAmount();
                $dueLeft = $order->getTotalDue() - $paidAmount;

                $state = Order::STATE_PROCESSING;
                $comment = 'heidelpay - Purchase Complete';

                // if payment is not complete
                if ($dueLeft > 0.00) {
                    $state = Order::STATE_PAYMENT_REVIEW;
                    $comment = 'heidelpay - Partly Paid ('
                        . $this->_paymentHelper->format(
                            $pushResponse->getPresentation()->getAmount()
                        )
                        . ' ' . $pushResponse->getPresentation()->getCurrency() . ')';
                }

                // set the invoice states to 'paid', if no due is left.
                if ($dueLeft <= 0.00) {
                    /** @var \Magento\Sales\Model\Order\Invoice $invoice */
                    foreach ($order->getInvoiceCollection() as $invoice) {
                        $invoice->setState(Invoice::STATE_PAID)->save();
                    }
                }

                $order->setTotalPaid($order->getTotalPaid() + $paidAmount)
                    ->setBaseTotalPaid($order->getBaseTotalPaid() + $paidAmount)
                    ->setState($state)
                    ->addStatusHistoryComment($comment, $state);

                // create a heidelpay Transaction.
                $methodInstance->saveHeidelpayTransaction(
                    $pushResponse,
                    $paymentMethod,
                    $paymentType,
                    'PUSH',
                    []
                );

                // create a child transaction.
                $payment->setTransactionId($uniqueId)
                    ->setParentTransactionId($pushResponse->getIdentification()->getReferenceId())
                    ->setIsTransactionClosed(true)
                    ->addTransaction(Transaction::TYPE_CAPTURE, null, true);

                $this->orderRepository->save($order);
            }
        }
    }
}
