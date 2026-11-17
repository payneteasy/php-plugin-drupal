<?php

namespace Drupal\commerce_payneteasypayment\Plugin\Commerce\PaymentGateway;

require __DIR__ . '/../../../../vendor/autoload.php';

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_payneteasypayment\Classes\Api\PaynetApi;
use Drupal\commerce_payneteasypayment\Classes\Common\PaynetEasyLogger;
use Drupal\commerce_payneteasypayment\Classes\Exception\PaynetEasyException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Paynet payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_payneteasypayment_offsite",
 *   label = "PaynetEasy (Off-site - Form method)",
 *   display_label = "PaynetEasy",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_payneteasypayment\PluginForm\OffsiteRedirect\PaynetPaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa", "MIR"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class PaynetpaymentOffsite extends OffsitePaymentGatewayBase
{
    private string $login;
    private string $control_key;
    private string $endpoint_id;
    private bool $test_mode;
    private bool $logging;
    private string $live_url;
    private string $sandbox_url;
    private object $logger;
    
    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

        $this->test_mode = true;
        if (isset($configuration['mode']) && $configuration['mode'] == 'live')
            $this->test_mode = false;

        if (isset($configuration['logging']))
        $this->logging = $configuration['logging'] == '1' ? true : false;

        if (isset($configuration['login']))
        $this->login = $configuration['login'];

        if (isset($configuration['control_key']))
        $this->control_key = $configuration['control_key'];

        if (isset($configuration['endpoint_id']))
        $this->endpoint_id = $configuration['endpoint_id'];

        if (isset($configuration['live_url']))
            $this->live_url = $configuration['live_url'];

        if (isset($configuration['sandbox_url']))
            $this->sandbox_url = $configuration['sandbox_url'];

    }


    /**
     * Дефолтные значения формы настроек модуля.
     * @return array - возвращает дефолные значения
     */
    public function defaultConfiguration()
    {
        return [
                'login' => '',
                'control_key' => '',
                'endpoint_id' => '',
                'logging' => true,
                'live_url' => '',
                'sandbox_url' => '',
            ] + parent::defaultConfiguration();
    }


    /**
     * Создание формы настроек модуля.
     * @param array $form - массив параметров настроек
     * @param object $form_state - объект формы настроек
     * @return void
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['login'] = [
            '#title' => $this->t('Login'),
            '#description' => $this->t('Merchant Login is required to call the API.'),
            '#default_value' => $this->configuration['login'],
            '#type' => 'textfield',
            '#required' => true,
        ];

        $form['control_key'] = [
            '#title' => $this->t('Control Key'),
            '#description' => $this->t('Merchant Control key is required to call the API.'),
            '#default_value' => $this->configuration['control_key'],
            '#type' => 'textfield',
            '#required' => true,
        ];

        $form['endpoint_id'] = [
            '#title' => $this->t('Endpoint ID'),
            '#description' => $this->t('Merchant Endpoint ID is required to call the API.'),
            '#default_value' => $this->configuration['endpoint_id'],
            '#type' => 'textfield',
            '#required' => true,
        ];

        $form['logging'] = [
            '#title' => $this->t('Logging'),
            '#description' => $this->t('Logging is used to debug plugin performance by storing API request data.'),
            '#default_value' => $this->configuration['logging'] ?: true,
            '#type' => 'radios',
            '#options' =>  [
                true => 'Enable',
                false => 'Disable'
            ]
        ];

        $form['live_url'] = [
            '#title' => $this->t('Gateway url (LIVE)'),
            '#description' => $this->t('https://gate.payneteasy.com/ etc.'),
            '#default_value' => $this->configuration['live_url'],
            '#type' => 'textfield',
        ];

        $form['sandbox_url'] = [
            '#title' => $this->t('Gateway url (SANDBOX)'),
            '#description' => $this->t('https://sandbox.payneteasy.com/ etc.'),
            '#default_value' => $this->configuration['sandbox_url'],
            '#type' => 'textfield',
        ];

        return $form;
    }


    /**
     * Сохранение параметров настроек модуля.
     * @param array $form - массив параметров настроек
     * @param object $form_state - объект формы настроек
     * @return void
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);

        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['login'] = $values['login'];
            $this->configuration['control_key'] = $values['control_key'];
            $this->configuration['endpoint_id'] = $values['endpoint_id'];
            $this->configuration['logging'] = $values['logging'];
            $this->configuration['live_url'] = $values['live_url'];
            $this->configuration['sandbox_url'] = $values['sandbox_url'];
        }
    }


    /**
     * Обработчик cancel, вызываемый при переходе на страницу cancel_url, после попытки оплаты.
     * Показывает сообщение что заказ был отклонен
     * @param object $order - объект заказа
     * @param object $request - объект запроса к странице возврата
     * @return void
     */
    public function onCancel(OrderInterface $order, Request $request) {
        \Drupal::messenger()->addMessage($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
            '@gateway' => $this->getDisplayLabel(),
        ]));
    }


    /**
     * Обработчик return, вызываемый при переходе на страницу return_url, после попытки оплаты.
     * Добавляет информацию о платеже в заказ
     * @param object $order - объект заказа
     * @param object $request - объект запроса к странице возврата
     * @return void
     */
    public function onReturn(OrderInterface $order, Request $request) {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'onReturn-' . rand(1111, 9999));

        // Only handle orders paid through this gateway, and never touch an
        // order that is already paid (no duplicate or downgrading payments).
        if ($order->get('payment_gateway')->target_id != $this->parentEntity->id()) {
            throw new PaymentGatewayException('Order ' . $order->id() . ' does not use this PaynetEasy gateway.');
        }
        if ($order->isPaid()) {
            return;
        }

        try {
            $remote_state = $this->getPaymentStatus($order->id());
        } catch (PaynetEasyException $e) {
            throw new PaymentGatewayException('PaynetEasy status request failed for order ' . $order->id() . '.');
        }
        if (isset($remote_state['merchant-order-id']) && (string) $remote_state['merchant-order-id'] !== (string) $order->id()) {
            throw new PaymentGatewayException('PaynetEasy status does not match order ' . $order->id() . '.');
        }
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $requestTime = $this->time->getRequestTime();
        $message = '';

        if (trim($remote_state['status']) == 'approved') {
            $payment = $payment_storage->create([
                'state' => 'completed',
                'amount' => $order->getBalance(),
                'payment_gateway' => $this->parentEntity->id(),
                'order_id' => $order->id(),
                'remote_id' => $remote_state['paynet-order-id'] ?? '',
                'remote_state' => trim($remote_state['status']),
            ]);
            $message = $this->t('Your payment was successful with Order id : @orderid has been received at : @date', ['@orderid' => $order->id(), '@date' => date("d-m-Y H:i:s", $requestTime)]);
        } elseif (trim($remote_state['status']) == 'processing') {
            // "processing" is not a final PaynetEasy status: do not record a
            // payment and do not let checkout place the order on it.
            \Drupal::messenger()->addWarning($this->t('Your payment with Order id : @orderid is still being processed. Please wait a few minutes before trying again to avoid a duplicate charge.', ['@orderid' => $order->id()]));
            throw new PaymentGatewayException('PaynetEasy payment for order ' . $order->id() . ' is still processing.');
        } else {
            $payment = $payment_storage->create([
                'state' => 'authorization',
                'amount' => $order->getBalance(),
                'payment_gateway' => $this->parentEntity->id(),
                'order_id' => $order->id(),
                'remote_id' => $remote_state['paynet-order-id'] ?? '',
                'remote_state' => trim($remote_state['status']),
            ]);
            $message = $this->t('Your payment with Order id : @orderid failed at : @date', ['@orderid' => $order->id(), '@date' => date("d-m-Y H:i:s", $requestTime)]);
            throw new PaymentGatewayException();
        }
        $payment->save();
        \Drupal::messenger()->addMessage($message);
    }

    /**
     * Обработчик notify, вызываемый в качестве вебхука, после попытки оплаты.
     * Может использоваться при возврате платежа
     * @param object $request - объект запроса к вебхуку
     * @return string добавляет статус ответа
     */
    public function onNotify(Request $request)
    {
        parent::onNotify($request);
    }


    /**
     * Получение статуса заказа из Paynet API.
     * @param string $orderId - ID заказа
     * @return array
     */
    private function getPaymentStatus(string $orderId): array
    {
        $query = \Drupal::database()->select('payneteasy_payments', 'pp');
        $query->addField('pp', 'paynet_order_id');
        $query->condition('pp.merchant_order_id', $orderId);
        $paynetOrderId = $query->execute()->fetchField();

        $data = [
            'login' => $this->login,
            'client_orderid' => $orderId,
            'orderid' => $paynetOrderId,
        ];
        $data['control'] = $this->signStatusRequest($data, $this->login, $this->control_key);
        
        $action_url = $this->live_url;
        if ($this->test_mode)
            $action_url = $this->sandbox_url;

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > status - INPUT: ', [
            'arguments' => [
                'client_orderid' => $orderId,
                'paynetOrderId' => $paynetOrderId
            ]
        ]);

        $paynetApi = new PaynetApi();

        $response = $paynetApi->status(
            $data,
            $action_url,
            $this->endpoint_id
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > status - OUTPUT: ', [
            'response' => $response
        ]);

        return $response;
    }


    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= $data['amount'] * 100;
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
            ->setLogFilePath(dirname(__DIR__, 4) . '/logs/payneteasy-' . date('d-m-Y') . '.log')
            ->setCustomRecording(function($message) use ($logger) {
                if ($this->logging)
                    $logger->writeToFile($message);
            }, PaynetEasyLogger::LOG_LEVEL_DEBUG);
    }
}
