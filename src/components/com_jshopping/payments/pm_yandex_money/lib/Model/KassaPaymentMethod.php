<?php

namespace YandexMoney\Model;

use YaMoney\Client\YandexMoneyApi;
use YaMoney\Model\ConfirmationType;
use YaMoney\Model\PaymentInterface;
use YaMoney\Model\PaymentMethodType;
use YaMoney\Model\PaymentStatus;
use YaMoney\Request\Payments\CreatePaymentRequest;
use YaMoney\Request\Payments\Payment\CreateCaptureRequest;

class KassaPaymentMethod
{
    private $module;
    private $client;
    private $shopId;
    private $password;
    private $defaultTaxRateId;
    private $taxRates;

    /**
     * KassaPaymentMethod constructor.
     * @param \pm_yandex_money $module
     * @param array $pmConfig
     */
    public function __construct($module, $pmConfig)
    {
        $this->module = $module;
        $this->shopId = $pmConfig['shop_id'];
        $this->password = $pmConfig['shop_password'];

        $this->defaultTaxRateId = 1;
        if (isset($pmConfig['tax_id'])) {
            if (isset($pmConfig['ya_kassa_tax_'.$pmConfig['tax_id']])) {
                $this->defaultTaxRateId = $pmConfig['ya_kassa_tax_'.$pmConfig['tax_id']];
            }
        }

        $this->taxRates = array();
        foreach ($pmConfig as $key => $value) {
            if (strncmp('ya_kassa_tax_', $key, 13) === 0) {
                $taxRateId = substr($key, 13);
                $this->taxRates[$taxRateId] = $value;
            }
        }
    }

    public function getShopId()
    {
        return $this->shopId;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function createPayment($order, $cart, $returnUrl)
    {
        try {
            $builder = CreatePaymentRequest::builder();
            $builder->setAmount($order->order_total)
                ->setCapture(false)
                ->setClientIp($_SERVER['REMOTE_ADDR'])
                ->setMetadata(array(
                    'order_id'       => $order->order_id,
                    'cms_name'       => 'ya_api_joomshopping',
                    'module_version' => _JSHOP_YM_VERSION,
                ));

            $confirmation = array(
                'type' => ConfirmationType::REDIRECT,
                'returnUrl' => $returnUrl,
            );
            $params = unserialize($order->payment_params_data);
            if (!empty($params['payment_type'])) {
                $paymentType = $params['payment_type'];
                if ($paymentType === PaymentMethodType::ALFABANK) {
                    $paymentType = array(
                        'type' => $paymentType,
                        'login' => trim($params['alfaLogin']),
                    );
                    $confirmation = ConfirmationType::EXTERNAL;
                } elseif ($paymentType === PaymentMethodType::QIWI) {
                    $paymentType = array(
                        'type' => $paymentType,
                        'phone' => preg_replace('/[^\d]+/', '', $params['qiwiPhone']),
                    );
                }
                $builder->setPaymentMethodData($paymentType);
            }
            $builder->setConfirmation($confirmation);

            $receipt = null;
            if (count($cart->products) && isset($pmConfigs['ya_kassa_send_check']) && $pmConfigs['ya_kassa_send_check']) {
                $this->factoryReceipt($builder, $cart, $order);
            }

            $request = $builder->build();
            if ($request->hasReceipt()) {
                $request->getReceipt()->normalize($request->getAmount());
            }
        } catch (\Exception $e) {
            $this->module->log('error', 'Failed to build request: ' . $e->getMessage());
            return null;
        }

        try {
            $tries = 0;
            $key = uniqid('', true);
            do {
                $payment = $this->getClient()->createPayment($request, $key);
                if ($payment === null) {
                    $tries++;
                    if ($tries > 3) {
                        break;
                    }
                    sleep(2);
                }
            } while ($payment === null);
        } catch (\Exception $e) {
            $this->module->log('error', 'Failed to create payment: ' . $e->getMessage());
            return null;
        }
        return $payment;
    }

    /**
     * @param PaymentInterface $notificationPayment
     * @param bool $fetchPayment
     * @return PaymentInterface|null
     */
    public function capturePayment($notificationPayment, $fetchPayment = true)
    {
        if ($fetchPayment) {
            $payment = $this->fetchPayment($notificationPayment->getId());
        } else {
            $payment = $notificationPayment;
        }
        if ($payment->getStatus() !== PaymentStatus::WAITING_FOR_CAPTURE) {
            return $payment->getStatus() === PaymentStatus::SUCCEEDED ? $payment : null;
        }

        try {
            $builder = CreateCaptureRequest::builder();
            $builder->setAmount($payment->getAmount());
            $request = $builder->build();
        } catch (\Exception $e) {
            $this->module->log('error', 'Failed to create capture payment: ' . $e->getMessage());
            return null;
        }

        try {
            $tries = 0;
            $key = uniqid('', true);
            do {
                $response = $this->getClient()->capturePayment($request, $payment->getId(), $key);
                if ($response === null) {
                    $tries++;
                    if ($tries > 3) {
                        break;
                    }
                    sleep(2);
                }
            } while ($response === null);
        } catch (\Exception $e) {
            $this->module->log('error', 'Failed to capture payment: ' . $e->getMessage());
            return null;
        }

        return $response;
    }

    /**
     * @param string $paymentId
     * @return PaymentInterface|null
     */
    public function fetchPayment($paymentId)
    {
        $payment = null;
        try {
            $payment = $this->getClient()->getPaymentInfo($paymentId);
        } catch (\Exception $e) {
            $this->module->log('error', 'Failed to fetch payment information from API: ' . $e->getMessage());
        }
        return $payment;
    }

    /**
     * @param \YaMoney\Request\Payments\CreatePaymentRequestBuilder $builder
     * @param $cart
     * @param $order
     */
    private function factoryReceipt($builder, $cart, $order)
    {
        $shippingModel = \JSFactory::getTable('shippingMethod', 'jshop');
        $shippingMethods = $shippingModel->getAllShippingMethodsCountry($order->d_country, $order->payment_method_id);

        $builder->setTaxSystemCode($this->defaultTaxRateId);
        $builder->setReceiptEmail($order->email);

        $shipping = false;
        foreach ($shippingMethods as $tmp) {
            if ($tmp->shipping_id == $order->shipping_method_id) {
                $shipping = $tmp;
            }
        }

        foreach ($cart->products as $product) {
            if (isset($product['tax_id']) && isset($this->taxRates[$product['tax_id']])) {
                $taxId = $this->taxRates[$product['tax_id']];
                $builder->addReceiptItem($product['product_name'], $product['price'], $product['quantity'], $taxId);
            } else {
                $builder->addReceiptItem($product['product_name'], $product['price'], $product['quantity']);
            }
        }

        if ($order->shipping_method_id && $shipping) {
            if (isset($this->taxRates[$shipping->shipping_tax_id])) {
                $taxId = $this->taxRates[$shipping->shipping_tax_id];
                $builder->addReceiptShipping($shipping->name, $shipping->shipping_stand_price, $taxId);
            } else {
                $builder->addReceiptShipping($shipping->name, $shipping->shipping_stand_price);
            }
        }
    }

    /**
     * @return YandexMoneyApi
     */
    private function getClient()
    {
        if ($this->client === null) {
            $this->client = new YandexMoneyApi();
            $this->client->setAuth($this->shopId, $this->password);
            $this->client->setLogger($this->module);
        }
        return $this->client;
    }
}