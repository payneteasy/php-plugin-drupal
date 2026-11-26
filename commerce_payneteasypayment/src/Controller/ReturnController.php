<?php

namespace Drupal\commerce_payneteasypayment\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payneteasypayment\Plugin\Commerce\PaymentGateway\PaynetpaymentOffsite;
use Drupal\commerce_payneteasypayment\Plugin\Commerce\PaymentGateway\Onsite;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $gateway = $commerce_order->get('payment_gateway')->entity;
        $onsite = $gateway && $gateway->getPlugin() instanceof Onsite ? $gateway->getPlugin() : NULL;

        // Card payment: the server callback has its own address and no session.
        // It is answered at once, because the gateway may not follow a redirect.
        if ($onsite && $request->query->get('callback')) {
            try {
                $onsite->completeFromGateway($commerce_order);
            }
            catch (\Throwable $e) {
                return new Response('error', 503);
            }
            return new Response('OK');
        }

        if ($request->isMethod('POST')) {
            $url = Url::fromRoute('commerce_payneteasypayment.return', [
                'commerce_order' => $commerce_order->id(),
            ], ['absolute' => TRUE])->toString();
            return new RedirectResponse($url, 303);
        }

        if ($onsite) {
            // The payment step asks the gateway for the status and finishes the checkout
            $payment_step = Url::fromRoute('commerce_checkout.form', [
                'commerce_order' => $commerce_order->id(),
                'step' => 'payment',
            ], ['absolute' => TRUE]);
            if ($payment_step->access()) {
                return new RedirectResponse($payment_step->toString(), 303);
            }
            try {
                $onsite->completeFromGateway($commerce_order);
            }
            catch (\Throwable $e) {
                // Nothing is shown about the order.
            }
            return $this->neutralPage();
        }

        $checkout = Url::fromRoute('commerce_payment.checkout.return', [
            'commerce_order' => $commerce_order->id(),
            'step' => 'payment',
        ], ['absolute' => TRUE]);
        if ($checkout->access()) {
            return new RedirectResponse($checkout->toString(), 303);
        }

        if ($gateway && $gateway->getPlugin() instanceof PaynetpaymentOffsite) {
            try {
                $gateway->getPlugin()->onNotify(new Request(['client_orderid' => (string) $commerce_order->id()]));
            }
            catch (\Throwable $e) {
                // The server callback will update the order.
            }
        }

        return $this->neutralPage();
    }

    /**
     * Shows the 3-D Secure page of the bank for a card payment in progress.
     *
     * Only for the customer of the order, in the checkout session. The page comes
     * from the gateway status answer; when 3-D Secure is passed the bank sends
     * the customer to handle().
     */
    public function threeDSecure(OrderInterface $commerce_order) {
        $gateway = $commerce_order->get('payment_gateway')->entity;
        if (!$gateway || !$gateway->getPlugin() instanceof Onsite) {
            throw new NotFoundHttpException();
        }
        $payment_step = Url::fromRoute('commerce_checkout.form', [
            'commerce_order' => $commerce_order->id(),
            'step' => 'payment',
        ], ['absolute' => TRUE]);
        if (!$payment_step->access()) {
            throw new AccessDeniedHttpException();
        }

        $html = $gateway->getPlugin()->getThreeDSecureHtml($commerce_order);
        if ($html === '') {
            // Nothing to confirm any more: the payment step shows the result
            return new RedirectResponse($payment_step->toString(), 303);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * A page that tells nothing about the order.
     */
    private function neutralPage(): array {
        return [
            '#title' => $this->t('Thank you'),
            '#markup' => '<p>' . $this->t('Your payment has been sent for processing. You will receive a confirmation by email once the store receives it.') . '</p>',
            '#cache' => ['max-age' => 0],
        ];
    }

}
