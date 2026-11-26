<?php

namespace Drupal\commerce_payneteasypayment\Plugin\Commerce\PaymentGateway;

require __DIR__ . '/../../../../vendor/autoload.php';

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payneteasypayment\Classes\Api\PaynetApi;
use Drupal\commerce_payneteasypayment\Classes\Common\PaynetEasyLogger;
use Drupal\commerce_payneteasypayment\Classes\Exception\PaynetEasyException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the On-site Paynet payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_payneteasypayment_onsite",
 *   label = "PaynetEasy (On-site - Direct method)",
 *   display_label = "PaynetEasy",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_payneteasypayment\PluginForm\Onsite\PaymentMethodAddForm",
 *     "edit-payment-method" = "Drupal\commerce_payment\PluginForm\PaymentMethodEditForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa", "MIR"
 *   },
 *   requires_billing_information = FALSE
 * )
 */
class Onsite extends OnsitePaymentGatewayBase implements OnsiteInterface
{
    private string $login;
    private string $control_key;
    private string $endpoint_id;
    private bool $test_mode;
    private bool $logging;
    private bool $three_d_secure;
    private string $live_url;
    private string $sandbox_url;
    private object $logger;

    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, MinorUnitsConverterInterface $minor_units_converter)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time, $minor_units_converter);

        $this->setPayneteasyLogger();

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

        if (isset($configuration['three_d_secure']))
            $this->three_d_secure = $configuration['three_d_secure'];
    }


    /**
     * Default values of the module settings form.
     * @return array - the default values
     */
    public function defaultConfiguration()
    {
        return [
                'login' => '',
                'control_key' => '',
                'endpoint_id' => '',
                'logging' => false,
                'three_d_secure' => false,
                'live_url' => '',
                'sandbox_url' => '',
            ] + parent::defaultConfiguration();
    }


    /**
     * Builds the module settings form.
     * @param array $form - settings form array
     * @param object $form_state - settings form state
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
            '#default_value' => $this->configuration['logging'] ? 1 : 0,
            '#type' => 'radios',
            '#options' =>  [
                true => 'Enable',
                false => 'Disable'
            ]
        ];

        $form['three_d_secure'] = [
            '#title' => $this->t('3D Secure'),
            '#description' => $this->t('3D Secure or Non 3D Secure (WORK ONLY WITH DIRECT INTEGRATION METHOD)'),
            '#default_value' => $this->configuration['three_d_secure'] ?: false,
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
     * Saves the module settings.
     * @param array $form - settings form array
     * @param object $form_state - settings form state
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
            $this->configuration['three_d_secure'] = $values['three_d_secure'];
            $this->configuration['live_url'] = $values['live_url'];
            $this->configuration['sandbox_url'] = $values['sandbox_url'];
        }
    }


    /**
     * Gets the payment data.
     * @param $payment - payment object
     * @return array
     */
    private function getPaynetPayment($order,$address,$payment_details): array
    {
        $orderId = $order->id();
        $email = $this->getCustomerEmail($order);
        // Charge what is still due: after a partial payment the balance, not the total.
        $due = $order->getBalance() ?: $order->getTotalPrice();
        $amount = $due ? number_format((float) $due->getNumber(), 2, '.', '') : 0;

        // After 3-D Secure the customer and the server callback come to the route
        // of the module: the checkout return route serves only off-site gateways.
        $return_url = Url::fromRoute('commerce_payneteasypayment.return', ['commerce_order' => $orderId], ['absolute' => TRUE])->toString();

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > saleDirect - INPUT: ', [
            'arguments' => [
                'order_id' => $orderId,
                'email' => $email,
                'time' => time(),
                'amount' => floatval($amount),
                '$address' => $address
            ]
        ]);

        $payneteasy_card_number       = $payment_details['number']??'';
        $payneteasy_card_expiry_month = $payment_details['expiration']['month']??'';
        $payneteasy_card_expiry_year  = $payment_details['expiration']['year']??'';
        $payneteasy_card_cvv          = $payment_details['security_code']??'';

        $card_data = [
            'credit_card_number' => $payneteasy_card_number??'',
            'expire_month' => $payneteasy_card_expiry_month??'',
            'expire_year' => $payneteasy_card_expiry_year??'',
            'cvv2' => $payneteasy_card_cvv??'',
        ];

        $data = [
            'client_orderid' => (string)$orderId,
            'order_desc' => 'Order # ' . $orderId,
            'amount' => $amount,
            'currency' => $due->getCurrencyCode(),
            'address1' => $address['address_line1'],
            'city' => $address['locality'],
            'zip_code' => $address['postal_code'],
            'country' => $address['country_code'],
            'phone'      => '',
            'email'      => $email,
            'ipaddress' => $order->getIpAddress()??$_SERVER['REMOTE_ADDR'],
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => trim($address['given_name'] . ' ' . $address['family_name']),
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $address['given_name'],
            'last_name'  => $address['family_name'],
            'redirect_success_url' => $return_url,
            'redirect_fail_url' => $return_url,
            'redirect_url' => $return_url,
            // The server callback has no checkout session: it is answered at once
            'server_callback_url' => $return_url . '?callback=1',
        ];
        $data['control'] = $this->signPaymentRequest($data, $this->configuration['endpoint_id'], $this->configuration['control_key']);

        $action_url = $this->configuration['live_url'];
        if ($this->configuration['mode'] == 'test')
            $action_url = $this->configuration['sandbox_url'];

        $paynetApi = new PaynetApi();

        $response = $paynetApi->saleDirect(
            $data,
            $action_url,
            $this->configuration['endpoint_id']
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > saleDirect - OUTPUT: ', [
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


    /**
     * {@inheritdoc}
     */
    public function createPayment(PaymentInterface $payment, $capture = TRUE)
    {
        $this->assertPaymentState($payment, ['new']);
        $payment_method = $payment->getPaymentMethod();
        $this->assertPaymentMethod($payment_method);

        $order = $payment->getOrder();
        $orderId = $order->id();

        try {
            $status = $this->getPaymentStatus($orderId);
        } catch (PaynetEasyException $e) {
            throw new PaymentGatewayException('The payment could not be processed. Please try again or use another payment method.');
        }
        $remoteStatus = trim($status['status'] ?? '');

        if ($remoteStatus == 'processing') {
            // "processing" is not a final PaynetEasy status: never let checkout
            // place the order on it. The customer may resubmit once the gateway
            // reports a final status.
            if (!empty($status['html'])) {
                // The bank asks for 3-D Secure: its page is shown by the module,
                // the customer comes back to this step when it is passed.
                throw new NeedsRedirectException(Url::fromRoute('commerce_payneteasypayment.three_d_secure', ['commerce_order' => $orderId], ['absolute' => TRUE])->toString());
            }
            \Drupal::messenger()->addWarning($this->t('Your payment is still being processed. Please wait a moment and submit your order again without re-entering your card details.'));
            throw new PaymentGatewayException('PaynetEasy payment for order ' . $orderId . ' is still processing.');
        } elseif ($remoteStatus == 'approved') {
            // The gateway amount must match the payment: the order may have been
            // changed after the sale was sent.
            $amount   = trim((string) ($status['amount'] ?? ''));
            $currency = strtoupper(trim((string) ($status['currency'] ?? '')));
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || !preg_match('/^[A-Z]{3}$/', $currency)
                || !(new Price($amount, $currency))->equals($payment->getAmount())) {
                $this->logger->error('Order ' . $orderId . ': paid amount does not match the order total');
                throw new PaymentGatewayException('The paid amount does not match the order total. Please contact the store.');
            }
            // The server callback may record this transaction at the same time:
            // the check and the save run under the lock it uses.
            $lock = \Drupal::lock();
            $lockName = 'commerce_payneteasypayment:order:' . $orderId;
            if (!$lock->acquire($lockName, 30.0)) {
                $lock->wait($lockName, 30);
                $lock->acquire($lockName, 30.0);
            }
            $remoteId = trim((string) ($status['paynet-order-id'] ?? ''));
            if ($remoteId !== '' && $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties([
                'order_id' => $orderId,
                'remote_id' => $remoteId,
            ])) {
                $lock->release($lockName);
                return;
            }
            try {
                $payment->setState('completed');
                $payment->setRemoteId($status['paynet-order-id']);
                $payment->setAvsResponseCode('A');
                $payment->save();
            } finally {
                $lock->release($lockName);
            }
            return;
        } elseif (in_array($remoteStatus, ['declined', 'filtered'], true)) {
            throw new HardDeclineException('The payment was declined. Please use another payment method.');
        } else {
            throw new PaymentGatewayException('The payment could not be processed. Please try again or use another payment method.');
        }
    }


    /**
     * {@inheritdoc}
     */
    public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL)
    {
        $this->assertPaymentState($payment, ['authorization']);
        $amount = $amount ?: $payment->getAmount();
        $order = $payment->getOrder();
        $orderId = $order->id();
        $status = $this->getPaymentStatus($orderId);
        if (trim($status['status']) == 'processing') {
            $payment->setState('authorization');
        } elseif (trim($status['status']) == 'approved') {
            $payment->setState('completed');
        }
        $payment->setAmount($amount);
        $payment->save();
    }

    /**
     * {@inheritdoc}
     */
    public function voidPayment(PaymentInterface $payment)
    {
        $this->assertPaymentState($payment, ['authorization']);
        $payment->setState('authorization_voided');
        $payment->save();
    }

    /**
     * {@inheritdoc}
     */
    public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL)
    {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'refundPayment-' . rand(1111, 9999));
        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
        // If not specified, refund the entire amount.
        $amount = $amount ?: $payment->getAmount();
        $this->assertRefundAmount($payment, $amount);
        $orderId = (string) $payment->getOrderId();
        $remoteId = (string) $payment->getRemoteId();
        $value = number_format((float) $amount->getNumber(), 2, '.', '');
        $currency = $amount->getCurrencyCode();

        $data = [
            'login' => $this->configuration['login'],
            'client_orderid' => $orderId,
            'orderid' => $remoteId,
            'amount' => $value,
            'currency' => $currency,
            'comment' => 'Refund',
        ];
        $data['control'] = $this->signString(
            $data['login'] . $orderId . $remoteId . (int) round($value * 100) . $currency,
            $this->configuration['control_key']
        );

        $action_url = $this->configuration['live_url'];
        if ($this->configuration['mode'] == 'test')
            $action_url = $this->configuration['sandbox_url'];

        $paynetApi = new PaynetApi();

        try {
            $response = $paynetApi->return($data, $action_url, $this->configuration['endpoint_id']);
        } catch (PaynetEasyException $e) {
            $this->logger->error('Refund request failed for order ' . $orderId . ': ' . $e->getMessage());
            throw new PaymentGatewayException('The refund could not be sent to PaynetEasy. Please try again later.');
        }

        if (in_array(trim($response['type'] ?? ''), ['error', 'validation-error'], TRUE) || trim($response['status'] ?? '') === 'error') {
            $this->logger->error('Refund refused for order ' . $orderId . ': ' . trim($response['error-message'] ?? ''));
            throw new PaymentGatewayException('PaynetEasy refused the refund: ' . trim($response['error-message'] ?? 'unknown error'));
        }

        // The refund is processed asynchronously: wait briefly for the final answer.
        $final = '';
        for ($i = 0; $i < 5; $i++) {
            try {
                $status = $this->getPaymentStatus($orderId, $remoteId);
            } catch (PaynetEasyException $e) {
                break;
            }
            $final = trim($status['status'] ?? '');
            if ($final !== 'processing') {
                break;
            }
            sleep(1);
        }
        if (in_array($final, ['declined', 'error', 'filtered'], TRUE)) {
            $this->logger->error('Refund declined for order ' . $orderId . ': ' . trim($status['error-message'] ?? ''));
            throw new PaymentGatewayException('PaynetEasy declined the refund: ' . trim($status['error-message'] ?? $final));
        }
        if ($final !== 'approved') {
            // The gateway accepted the request, so the refund is recorded: a second
            // attempt would send a second refund. The final result is not known yet.
            $this->logger->error('Refund for order ' . $orderId . ' is not confirmed yet, status: ' . ($final !== '' ? $final : 'unknown'));
            \Drupal::messenger()->addWarning($this->t('PaynetEasy accepted the refund but has not confirmed it yet. Check its final status in the PaynetEasy account before refunding again.'));
        }

        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
            $payment->setState('partially_refunded');
        } else {
            $payment->setState('refunded');
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
    }

    /**
     * {@inheritdoc}
     */
    public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details)
    {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'createPaymentMethod -' . rand(1111, 9999));
        $required_keys = [
            'type', 'number', 'expiration',
        ];
        foreach ($required_keys as $required_key) {
            if (empty($payment_details[$required_key])) {
                throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
            }
        }

        // A card is charged only for an order in checkout: a card added from the
        // customer account has no order to pay.
        $order = \Drupal::routeMatch()->getParameter('commerce_order');
        if (!$order instanceof OrderInterface) {
            throw new PaymentGatewayException('A card can be added only during checkout.');
        }

        // The card is not stored at the gateway: it cannot be offered again as a saved card
        $payment_method->setReusable(FALSE);

        // Billing information is optional: the address may be missing
        $address = [];
        $billing_profile = $payment_method->getBillingProfile();
        if ($billing_profile && !$billing_profile->get('address')->isEmpty()) {
            $address = $billing_profile->get('address')->first()->getValue();
        }
        foreach (['address_line1', 'locality', 'postal_code', 'country_code', 'given_name', 'family_name'] as $key) {
            $address[$key] = (string) ($address[$key] ?? '');
        }

        // A card submitted again must not send a second sale while the previous
        // one is in progress or approved and not recorded yet.
        $previous = $this->getUnfinishedAttemptStatus($order);
        if ($previous === 'approved') {
            return;
        }
        if ($previous === 'processing') {
            \Drupal::messenger()->addWarning($this->t('Your payment is still being processed. Please wait a moment and submit your order again without re-entering your card details.'));
            throw new PaymentGatewayException('PaynetEasy payment for order ' . $order->id() . ' is still processing.');
        }

        try {
            $response = $this->getPaynetPayment($order, $address, $payment_details);
        } catch (PaynetEasyException $e) {
            $this->logger->error('Sale request failed for order ' . $order->id() . ': ' . $e->getMessage());
            throw new PaymentGatewayException('The payment could not be processed. Please try again or use another payment method.');
        }

        // The gateway did not accept the sale: stop here, not at the payment step
        if (empty($response['paynet-order-id'])) {
            $this->logger->error('Sale refused for order ' . $order->id() . ': '
                . trim((string) ($response['error-message'] ?? $response['type'] ?? 'no response')));
            throw new PaymentGatewayException('The payment could not be processed. Please try again or use another payment method.');
        }
    }

    /**
     * Status of the latest sale of the order if it is in progress, or approved
     * and not recorded as a payment yet; an empty string otherwise.
     */
    private function getUnfinishedAttemptStatus(OrderInterface $order): string
    {
        $orderId = (string) $order->id();
        $known = \Drupal::database()->select('payneteasy_payments', 'pp')
            ->fields('pp', ['paynet_order_id'])
            ->condition('pp.merchant_order_id', $orderId)
            ->range(0, 1)->execute()->fetchField();
        if (!$known) {
            return '';
        }
        try {
            $status = $this->getPaymentStatus($orderId);
        } catch (\Throwable $e) {
            return '';
        }
        $state = trim((string) ($status['status'] ?? ''));
        if ($state === 'processing') {
            return $state;
        }
        $remoteId = trim((string) ($status['paynet-order-id'] ?? ''));
        if ($state === 'approved' && $remoteId !== '' && !$this->entityTypeManager->getStorage('commerce_payment')->loadByProperties([
            'order_id' => $orderId,
            'remote_id' => $remoteId,
        ])) {
            return $state;
        }
        return '';
    }

    /**
     * The 3-D Secure page of the bank for the sale in progress, or an empty string.
     */
    public function getThreeDSecureHtml(OrderInterface $order): string
    {
        $this->setPayneteasyLogger();
        try {
            $status = $this->getPaymentStatus((string) $order->id());
        } catch (\Throwable $e) {
            return '';
        }
        return trim((string) ($status['status'] ?? '')) === 'processing' ? (string) ($status['html'] ?? '') : '';
    }

    /**
     * Records an approved sale and places the order without the checkout session:
     * for the server callback and for a customer who came back in another browser.
     *
     * Nothing from the request is used: the status is asked from the gateway.
     */
    public function completeFromGateway(OrderInterface $order): void
    {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'completeFromGateway-' . rand(1111, 9999));
        $orderId = (string) $order->id();

        if ($order->getState()->getId() !== 'draft' || $order->get('payment_gateway')->target_id != $this->parentEntity->id()) {
            return;
        }
        try {
            $status = $this->getPaymentStatus($orderId);
        } catch (\Throwable $e) {
            return;
        }
        if (trim((string) ($status['status'] ?? '')) !== 'approved'
            || (isset($status['merchant-order-id']) && trim((string) $status['merchant-order-id']) !== $orderId)) {
            return;
        }

        $amount   = trim((string) ($status['amount'] ?? ''));
        $currency = strtoupper(trim((string) ($status['currency'] ?? '')));
        $remoteId = trim((string) ($status['paynet-order-id'] ?? ''));
        $balance  = $order->getBalance();
        if ($remoteId === '' || !$balance || !preg_match('/^\d+(\.\d{1,2})?$/', $amount) || !preg_match('/^[A-Z]{3}$/', $currency)
            || !(new Price($amount, $currency))->equals($balance)) {
            $this->logger->error('Order ' . $orderId . ': paid amount does not match the order total');
            return;
        }

        // The customer return and the server callback may come together
        $lock = \Drupal::lock();
        $lockName = 'commerce_payneteasypayment:order:' . $orderId;
        if (!$lock->acquire($lockName, 30.0)) {
            $lock->wait($lockName, 30);
            if (!$lock->acquire($lockName, 30.0)) {
                return;
            }
        }
        try {
            $storage = $this->entityTypeManager->getStorage('commerce_payment');
            if (!$storage->loadByProperties(['order_id' => $orderId, 'remote_id' => $remoteId])) {
                $storage->create([
                    'state' => 'completed',
                    'amount' => new Price($amount, $currency),
                    'payment_gateway' => $this->parentEntity->id(),
                    'payment_method' => $order->get('payment_method')->target_id,
                    'order_id' => $orderId,
                    'remote_id' => $remoteId,
                    'remote_state' => 'approved',
                ])->save();
            }

            $order = $this->entityTypeManager->getStorage('commerce_order')->loadUnchanged($orderId);
            $state = $order->getState();
            if ($state->getId() === 'draft' && in_array('place', array_keys($state->getTransitions()), TRUE)) {
                $state->applyTransitionById('place');
                $order->unlock();
                $order->set('checkout_step', 'complete');
                $order->save();
            }
            $this->logger->debug('Order ' . $orderId . ' paid without the checkout session');
        } finally {
            $lock->release($lockName);
        }
    }

    /**
     * Customer email for the sale request.
     *
     * In checkout the payment method is created before the contact information
     * pane copies the guest email to the order, so the submitted value is used.
     */
    private function getCustomerEmail($order): string
    {
        $email = (string) $order->getEmail();
        if ($email === '') {
            $contact = \Drupal::request()->request->all()['contact_information'] ?? [];
            $posted = is_array($contact) ? trim((string) ($contact['email'] ?? '')) : '';
            if (filter_var($posted, FILTER_VALIDATE_EMAIL)) {
                $email = $posted;
            }
        }
        if ($email === '' && $order->getCustomer() && !$order->getCustomer()->isAnonymous()) {
            $email = (string) $order->getCustomer()->getEmail();
        }
        return $email;
    }

    /**
     * {@inheritdoc}
     */
    public function deletePaymentMethod(PaymentMethodInterface $payment_method)
    {
        $payment_method->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function updatePaymentMethod(PaymentMethodInterface $payment_method)
    {
        // Note: Since requires_billing_information is FALSE, the payment method
        // is not guaranteed to have a billing profile. Confirm that
        // $payment_method->getBillingProfile() is not NULL before trying to use it.
        //
        // Perform the update request here, throw an exception if it fails.
        // See \Drupal\commerce_payment\Exception for the available exceptions.
    }

    /**
     * {@inheritdoc}
     */
    public function buildAvsResponseCodeLabel($avs_response_code, $card_type)
    {
        return parent::buildAvsResponseCodeLabel($avs_response_code, $card_type);
    }


    /**
     * Gets the order status from the Paynet API.
     * @param string $orderId - order ID
     * @return array
     */
    private function getPaymentStatus(string $orderId, ?string $paynetOrderId = NULL): array
    {
        if ($paynetOrderId === NULL) {
            // The latest attempt: the one this payment has just sent
            $query = \Drupal::database()->select('payneteasy_payments', 'pp');
            $query->addField('pp', 'paynet_order_id');
            $query->condition('pp.merchant_order_id', $orderId);
            $query->orderBy('pp.id', 'DESC');
            $query->range(0, 1);
            $paynetOrderId = $query->execute()->fetchField();
        }

        $data = [
            'login' => $this->login,
            'client_orderid' => $orderId,
            'orderid' => $paynetOrderId,
        ];
        $data['control'] = $this->signStatusRequest($data, $this->configuration['login'], $this->configuration['control_key']);

        $action_url = $this->configuration['live_url'];
        if ($this->configuration['mode'] == 'test')
            $action_url = $this->configuration['sandbox_url'];

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
            $this->configuration['endpoint_id']
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
        $base .= (int) round($data['amount'] * 100);
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }


    /**
     * Creates and configures the PaynetEasyLogger object.
     * This function sets up the logger the PaynetEasy plugin writes its log with.
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
                if ($this->configuration['logging'] == '1')
                    $logger->writeToFile($message);
            }, PaynetEasyLogger::LOG_LEVEL_DEBUG);
    }
}
