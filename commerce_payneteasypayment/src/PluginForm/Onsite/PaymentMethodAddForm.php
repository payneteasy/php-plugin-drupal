<?php

namespace Drupal\commerce_payneteasypayment\PluginForm\Onsite;

require __DIR__ . '/../../../vendor/autoload.php';

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payneteasypayment\Classes\Api\PaynetApi;
use Drupal\commerce_payneteasypayment\Classes\Common\PaynetEasyLogger;
use Drupal\commerce_payneteasypayment\Classes\Exception\PaynetEasyException;

class PaymentMethodAddForm extends BasePaymentMethodAddForm
{

    protected function buildCreditCardForm(array $element, FormStateInterface $form_state)
    {
        $payment = $this->entity;
        $plugin = $payment->getPaymentGateway()->getPlugin();
        $configuration = $plugin->getConfiguration();
        $element = parent::buildCreditCardForm($element, $form_state);
        // Default to a known valid test credit card number.
        return $element;
    }

}
