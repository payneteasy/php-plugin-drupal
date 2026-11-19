<?php

namespace Drupal\commerce_payneteasypayment\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payneteasypayment\Plugin\Commerce\PaymentGateway\PaynetpaymentOffsite;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Brings the customer back from the gateway.
 *
 * Browsers do not send the Drupal session cookie (SameSite=Lax) with a POST
 * from another site, so the POST is first turned into a same-site GET with a
 * 303 redirect. On the GET the customer goes on to the checkout return route,
 * where onReturn() asks the gateway for the payment status.
 *
 * Without access to the checkout (session expired, payment finished in another
 * browser) the customer gets a neutral page instead of "Access denied": the
 * payment status is taken from the gateway in the same way as for the server
 * callback, and nothing about the order is shown. Nothing from the request is
 * trusted.
 */
class ReturnController {

    use StringTranslationTrait;

    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
     */
    public function handle(OrderInterface $commerce_order, Request $request) {
        if ($request->isMethod('POST')) {
            $url = Url::fromRoute('commerce_payneteasypayment.return', [
                'commerce_order' => $commerce_order->id(),
            ], ['absolute' => TRUE])->toString();
            return new RedirectResponse($url, 303);
        }

        $checkout = Url::fromRoute('commerce_payment.checkout.return', [
            'commerce_order' => $commerce_order->id(),
            'step' => 'payment',
        ], ['absolute' => TRUE]);
        if ($checkout->access()) {
            return new RedirectResponse($checkout->toString(), 303);
        }

        $gateway = $commerce_order->get('payment_gateway')->entity;
        if ($gateway && $gateway->getPlugin() instanceof PaynetpaymentOffsite) {
            try {
                $gateway->getPlugin()->onNotify(new Request(['client_orderid' => (string) $commerce_order->id()]));
            }
            catch (\Throwable $e) {
                // The server callback will update the order.
            }
        }

        return [
            '#title' => $this->t('Thank you'),
            '#markup' => '<p>' . $this->t('Your payment has been sent for processing. You will receive a confirmation by email once the store receives it.') . '</p>',
            '#cache' => ['max-age' => 0],
        ];
    }

}
