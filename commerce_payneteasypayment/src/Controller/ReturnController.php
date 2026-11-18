<?php

namespace Drupal\commerce_payneteasypayment\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Turns the gateway's cross-site POST return into a same-site GET.
 *
 * Browsers do not send the Drupal session cookie (SameSite=Lax) with a POST
 * from another site, so posting straight to the checkout return route denies
 * access to guest customers. A 303 redirect makes the browser follow with GET,
 * which carries the cookie. The checkout return route then checks access and
 * onReturn() asks the gateway for the payment status; nothing from this
 * request is trusted.
 */
class ReturnController {

    public function redirectToCheckout(OrderInterface $commerce_order): RedirectResponse {
        $url = Url::fromRoute('commerce_payment.checkout.return', [
            'commerce_order' => $commerce_order->id(),
            'step' => 'payment',
        ], ['absolute' => TRUE])->toString();
        return new RedirectResponse($url, 303);
    }

}
