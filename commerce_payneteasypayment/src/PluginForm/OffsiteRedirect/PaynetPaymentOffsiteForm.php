<?php

namespace Drupal\commerce_payneteasypayment\PluginForm\OffsiteRedirect;

require __DIR__ . '/../../../vendor/autoload.php';

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payneteasypayment\Classes\Api\PaynetApi;
use Drupal\commerce_payneteasypayment\Classes\Common\PaynetEasyLogger;
use Drupal\commerce_payneteasypayment\Classes\Exception\PaynetEasyException;

class PaynetPaymentOffsiteForm extends BasePaymentOffsiteForm
{
    private string $login;
    private string $control_key;
    private string $endpoint_id;
    private bool $test_mode;
    private bool $logging;
    private bool $three_d_secure;
    private string $payment_method;
    private string $live_url;
    private string $sandbox_url;
    private object $logger;


    /**
     * Формирует запрос и перенаправление на платежную страница.
     * @param array $form - массив формы оплаты
     * @param object $form_state - объект формы оплаты
     * @return array|null Массив, содержащий результат и URL перенаправления, или null, если произошла ошибка.
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
        $form = parent::buildConfigurationForm($form, $form_state);
        $payment = $this->entity;
        $order = $payment->getOrder();
        $orderId = $order->id();
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'payment-' . rand(1111, 9999));

        try {
            $plugin = $payment->getPaymentGateway()->getPlugin();
            $configuration = $plugin->getConfiguration();
            $this->logging = $configuration['logging'] == '1' ? true : false;
            $this->login = $configuration['login'];
            $this->control_key = $configuration['control_key'];
            $this->endpoint_id = $configuration['endpoint_id'];

            $this->test_mode = true;
            if (isset($configuration['mode']) && $configuration['mode'] == 'live')
                $this->test_mode = false;

            $redirectUrl = $this->getPaymentUrl($form);
            if (isset($redirectUrl['error-message']))
                \Drupal::messenger()->addError($redirectUrl['error-message']);
            else
                return $this->buildRedirectForm($form, $form_state, $redirectUrl['redirect-url'], [], self::REDIRECT_GET);
        } catch (Exception | PaynetEasyException $e) {
            // Handle exception and log error
            $context = [
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ];
            if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

            $this->logger->error(sprintf(
                __FUNCTION__ . ' > PaynetEasy exception : %s; Order id: %s;',
                $e->getMessage(),
                $orderId ?: ''
            ), $context);
            \Drupal::messenger()->addError($e->getMessage());
            return null;
        }

  }


    /**
     * Получает URL-адрес платежа для перенаправления.
     * @param array $form - массив формы оплаты для получения адреса возврата
     * @return array
     */
    private function getPaymentUrl(array $form): array
    {
        $payment = $this->entity;
        $order = $payment->getOrder();
        $orderId = $order->id();
        $plugin = $payment->getPaymentGateway()->getPlugin();
        $configuration = $plugin->getConfiguration();
        $email = $order->getEmail();
        // Charge what is still due: after a partial payment the balance, not the total.
        $due = $order->getBalance() ?: $order->getTotalPrice();
        $amount = $due ? number_format((float) $due->getNumber(), 2, '.', '') : 0;
//        $return_url = $form['#return_url'];
//        $cancel_url = $form['#cancel_url'];
        // The customer comes back through a public route that re-issues the
        // request as GET, so the guest session cookie is sent (see ReturnController).
        $return_url = \Drupal\Core\Url::fromRoute('commerce_payneteasypayment.return', ['commerce_order' => $orderId], ['absolute' => TRUE])->toString();
        // The gateway's server-to-server callback has no session: send it to the notify route.
        $notify_url = $plugin->getNotifyUrl()->toString();

        $test_mode = true;
        if (isset($configuration['mode']) && $configuration['mode'] == 'live')
            $test_mode = false;

        if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty() ) {
            $order_shipments = $order->get('shipments')->referencedEntities();
            $order_shipment = reset($order_shipments);
            $shipment_profile = $order_shipment->getShippingProfile();
            $address = $shipment_profile->address->getValue();
        } else {
            $address = $order->billing_profile->entity->address->getValue();
        }

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > saleForm - INPUT: ', [
            'arguments' => [
                'order_id' => $orderId,
                'email' => $email,
                'time' => time(),
                'amount' => floatval($amount),
                '$address' => $address
            ]
        ]);

        $data = [
            'client_orderid' => (string)$orderId,
            'order_desc' => 'Order # ' . $orderId,
            'amount' => $amount,
            'currency' => $due->getCurrencyCode(),
            'address1' => $address[0]['address_line1'],
            'city' => $address[0]['locality'],
            'zip_code' => $address[0]['postal_code'],
            'country' => $address[0]['country_code'],
            'phone'      => '',
            'email'      => $order->getEmail(),
            'ipaddress' => $order->getIpAddress()??$_SERVER['REMOTE_ADDR'],
            'cvv2' => '',
            'credit_card_number' => '',
            'card_printed_name' => '',
            'expire_month' => '',
            'expire_year' => '',
            'first_name' => $address[0]['given_name'],
            'last_name'  => $address[0]['family_name'],
//            'redirect_success_url' => $success_url,
            'redirect_success_url' => $return_url,
            'redirect_fail_url' => $return_url,
            'redirect_url' => $return_url,
            'server_callback_url' => $notify_url,
        ];
        $data['control'] = $this->signPaymentRequest($data, $configuration['endpoint_id'], $configuration['control_key']);

        $action_url = $configuration['live_url'];
        if ($test_mode)
            $action_url = $configuration['sandbox_url'];

        $paynetApi = new PaynetApi();

        $response = $paynetApi->saleForm(
            $data,
            $action_url,
            $configuration['endpoint_id']
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > saleForm - OUTPUT: ', [
            'response' => $response
        ]);

        if (isset($response['paynet-order-id'])) {
            // Every attempt is kept: the customer may pay in an older tab
            \Drupal::database()->insert('payneteasy_payments')
                ->fields(['merchant_order_id' => (int) $response['merchant-order-id'], 'paynet_order_id' => trim((string) $response['paynet-order-id'])])
                ->execute();
        }

        return $response;
    }

    
    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= (int) round($data['amount'] * 100);
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }


    /**
     * Инициализация и настройка объекта класса PaynetEasyLogger.
     * Эта функция инициализирует и настраивает логгер, используемый плагином PaynetEasy для ведения журнала.
     * @return void
     */
    private function setPayneteasyLogger(): void
    {
        $logger = PaynetEasyLogger::getInstance();
        $this->logger = $logger
            ->setOption('showBacktrace', true)
            ->setOption('maskSensitiveData', true)
            ->setLogFilePath(PaynetEasyLogger::getLogDirectory() . '/payneteasy-' . date('d-m-Y') . '.log')
            ->setCustomRecording(function($message) use ($logger) {
                if ($this->logging)
                    $logger->writeToFile($message);
            }, PaynetEasyLogger::LOG_LEVEL_DEBUG);
    }
}