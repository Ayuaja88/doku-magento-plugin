<?php

namespace Jokul\Magento2\Controller\Payment;

use Magento\Sales\Model\Order;
use \Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ResourceConnection;
use Jokul\Magento2\Model\DokuMerchanthostedConfigProvider;
use Jokul\Magento2\Helper\Data;
use \Magento\Framework\App\Action\Context;
use \Magento\Framework\View\Result\PageFactory;
use \Magento\Customer\Model\SessionFactory;
use Magento\Framework\App\Request\Http;
use Jokul\Magento2\Model\GeneralConfiguration;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class RequestVa extends \Magento\Framework\App\Action\Action
{

    protected $_pageFactory;
    protected $session;
    protected $order;
    protected $logger;
    protected $resourceConnection;
    protected $config;
    protected $helper;
    protected $sessionFactory;
    protected $httpRequest;
    protected $generalConfiguration;
    protected $storeManagerInterface;
    protected $_timezoneInterface;

    public function __construct(
        Session $session,
        Order $order,
        ResourceConnection $resourceConnection,
        DokuMerchanthostedConfigProvider $config,
        Data $helper,
        Context $context,
        PageFactory $pageFactory,
        LoggerInterface $loggerInterface,
        SessionFactory $sessionFactory,
        Http $httpRequest,
        GeneralConfiguration $_generalConfiguration,
        StoreManagerInterface $_storeManagerInterface,
        TimezoneInterface $timezoneInterface
    ) {
        $this->session = $session;
        $this->logger = $loggerInterface;
        $this->order = $order;
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->helper = $helper;
        $this->_pageFactory = $pageFactory;
        $this->sessionFactory = $sessionFactory;
        $this->httpRequest = $httpRequest;
        $this->generalConfiguration = $_generalConfiguration;
        $this->storeManagerInterface = $_storeManagerInterface;
        $this->_timezoneInterface = $timezoneInterface;
        return parent::__construct($context);
    }

    protected function getOrder()
    {

        if (!$this->session->getLastRealOrder()->getIncrementId()) {

            $order = $this->order->getCollection()
                ->addFieldToFilter('quote_id', $this->session->getQuote()->getId())
                ->getFirstItem();

            if (!$order->getEntityId()) {
                $customerSession = $this->sessionFactory->create();
                $customerData = $customerSession->getCustomer();
                $order = $this->order->getCollection()
                    ->addFieldToFilter('customer_id', $customerData->getEntityId())
                    ->setOrder('created_at', 'DESC')
                    ->getFirstItem();
            }

            return $order;
        } else {
            return $this->order->loadByIncrementId($this->session->getLastRealOrder()->getIncrementId());
        }
    }

    public function execute()
    {

        $this->logger->info('===== Jokul - VA Request Controller ===== Start');

        $this->logger->info('===== Jokul - VA Request Controller ===== Find Order to Execute');

        $result = array();
        $redirectData = array();

        $order = $this->getOrder();

        if ($order->getEntityId()) {
            $order->setState(Order::STATE_NEW);
            $this->session->getLastRealOrder()->setState(Order::STATE_NEW);
            $order->save();

            $this->logger->info('===== Jokul - VA Request Controller ===== Order Found!');

            $configCode = $this->config->getRelationPaymentChannel($order->getPayment()->getMethod());

            $billingData = $order->getBillingAddress();
            $config = $this->config->getAllConfig();

            $realGrandTotal = $order->getGrandTotal();

            $totalAdminFeeDisc = $this->helper->getTotalAdminFeeAndDisc(
                $config['payment'][$order->getPayment()->getMethod()]['admin_fee'],
                $config['payment'][$order->getPayment()->getMethod()]['admin_fee_type'],
                $config['payment'][$order->getPayment()->getMethod()]['disc_amount'],
                $config['payment'][$order->getPayment()->getMethod()]['disc_type'],
                $realGrandTotal
            );

            $grandTotal = $realGrandTotal + $totalAdminFeeDisc['total_admin_fee'];

            $buffGrandTotal = $grandTotal - $totalAdminFeeDisc['total_discount'];

            $grandTotal = $buffGrandTotal;

            $clientId = $config['payment']['core']['client_id'];
            $sharedKey = $this->config->getSharedKey();
            $expiryTime = isset($config['payment']['core']['expiry']) && (int) $config['payment']['core']['expiry'] != 0 ? $config['payment']['core']['expiry'] : 60;

            $customerName = trim($billingData->getFirstname() . " " . $billingData->getLastname());

            $requestTarget = "";
            if ($configCode == 01) {
                $requestTarget = "/mandiri-virtual-account/v2/payment-code";
            } elseif ($configCode == 02) {
                $requestTarget = "/bsm-virtual-account/v2/payment-code";
            } elseif ($configCode == 03) {
                $requestTarget = "/doku-virtual-account/v2/payment-code";
            } elseif ($configCode == 04) {
                $requestTarget = "/bca-virtual-account/v2/payment-code";
            } elseif($configCode == 05){
                $requestTarget = "/permata-virtual-account/v2/payment-code";
            }

            $requestTimestamp = date("Y-m-d H:i:s");
            $requestTimestamp = date(DATE_ISO8601, strtotime($requestTimestamp));

            $signatureParams = array(
                "clientId" => $clientId,
                "key" => $sharedKey,
                "requestTarget" => $requestTarget,
                "requestId" => rand(1, 100000),
                "requestTimestamp" => substr($requestTimestamp, 0, 19) . "Z"
            );

            $params = array(
                "order" => array(
                    "invoice_number" => $order->getIncrementId(),
                    "amount" => $grandTotal
                ),
                "virtual_account_info" => array(
                    "expired_time" => $expiryTime,
                    "reusable_status" => false,
                    "info1" => '',
                    "info2" => '',
                    "info3" => '',
                ),
                "customer" => array(
                    "name" => $customerName,
                    "email" => $billingData->getEmail()
                ),
                "additional_info" => array(
                    "integration" => array(
                        "name" => "magento-plugin",
                        "version" => "1.1.1"
                    )
                )
            );

            $this->logger->info('===== Jokul - VA Request Controller ===== Request params: ' . json_encode($params, JSON_PRETTY_PRINT));
            $this->logger->info('===== Jokul - VA Request Controller ===== Payment channel: ' . $requestTarget);
            $this->logger->info('===== Jokul - VA Request Controller ===== Send request to Jokul');

            $orderStatus = 'FAILED';
            try {
                $signature = $this->helper->doCreateRequestSignature($signatureParams, $params);
                $result = $this->helper->doGeneratePaycode($signatureParams, $params, $signature);
            } catch (\Exception $e) {
                $this->logger->info('===== Jokul - VA Request Controller ===== Exception: ' . $e);
                $result['res_response_code'] = "500";
                $result['res_response_msg'] = "Can't connect to server";
            }

            $this->logger->info('===== Jokul - VA Request Controller ===== Response from Jokul: ' . json_encode($result, JSON_PRETTY_PRINT));

            if (isset($result['virtual_account_info'])) {
                $orderStatus = 'PENDING';
                $result['result'] = 'SUCCESS';
            } else {
                $result['result'] = 'FAILED';
                $result['errorMessage'] = $result['error']['message'];
            }

            $params['shared_key'] = $sharedKey;
            $params['response'] = $result;
            
            $vaNumber = $result['virtual_account_info']['virtual_account_number'];

            $jsonResult = json_encode(array_merge($params), JSON_PRETTY_PRINT);

            $this->resourceConnection->getConnection()->insert('jokul_transaction', [
                'quote_id' => $order->getQuoteId(),
                'store_id' => $order->getStoreId(),
                'order_id' => $order->getId(),
                'invoice_number' => $order->getIncrementId(),
                'payment_channel_id' => $configCode,
                'order_status' => $orderStatus,
                'request_params' => $jsonResult,
                'va_number' => $vaNumber,
                'created_at' => 'now()',
                'updated_at' => 'now()',
                'doku_grand_total' => $grandTotal,
                'admin_fee_type' => $config['payment'][$order->getPayment()->getMethod()]['admin_fee_type'],
                'admin_fee_amount' => !empty($config['payment'][$order->getPayment()->getMethod()]['admin_fee']) ? $config['payment'][$order->getPayment()->getMethod()]['admin_fee'] : 0,
                'admin_fee_trx_amount' => $totalAdminFeeDisc['total_admin_fee'],
                'discount_type' => $config['payment'][$order->getPayment()->getMethod()]['disc_type'],
                'discount_amount' => !empty($config['payment'][$order->getPayment()->getMethod()]['disc_amount']) ? $config['payment'][$order->getPayment()->getMethod()]['disc_amount'] : 0,
                'discount_trx_amount' => $totalAdminFeeDisc['total_discount']
            ]);

            $base_url = $this->storeManagerInterface
                ->getStore($order->getStore()->getId())
                ->getBaseUrl();

            $redirectData['url'] = $base_url . "jokulbackend/service/redirect";
            $redirectData['invoice_number'] = $order->getIncrementId();

            $redirectSignatureParams = array(
                'amount' => $grandTotal,
                'sharedkey' => $sharedKey,
                'invoice' => $order->getIncrementId(),
                'status' => $result['result']
            );

            $redirectSignature = $this->helper->generateRedirectSignature($redirectSignatureParams);
            $redirectData['redirect_signature'] = $redirectSignature;
            $redirectData['status'] = $result['result'];
        } else {
            $this->logger->info('===== Jokul - VA Request Controller ===== Order not found!');
        }

        $this->logger->info('===== Jokul - VA Request Controller ===== End');

        if ($result['result'] == 'SUCCESS') {
            $this->logger->info('===== Jokul - VA Request Controller ===== Redirecting to Success Page' . print_r($result, true));

            echo json_encode(array(
                'err' => false,
                'response_msg' => 'VA Number Generated',
                'result' => $redirectData
            ));
        } else {
            $this->logger->info('===== Jokul - VA Request Controller ===== Show Error Popup: ' . print_r($result, true));

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $_checkoutSession = $objectManager->create('\Magento\Checkout\Model\Session');
            $_quoteFactory = $objectManager->create('\Magento\Quote\Model\QuoteFactory');

            $order = $_checkoutSession->getLastRealOrder();
            $quote = $_quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $_checkoutSession->replaceQuote($quote);
                echo json_encode(array(
                    'err' => true,
                    'response_msg' => $result['errorMessage'],
                    'result' => $redirectData
                ));
            }
        }
    }
}
