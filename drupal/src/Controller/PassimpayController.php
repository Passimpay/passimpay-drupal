<?php

namespace Drupal\passimpay\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;

/**
 * Returns responses for passimpay module routes.
 */
class PassimpayController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function paymentProcessing() {
    $request_params = \Drupal::request()->request->all();

    $config = \Drupal::service('config.factory')->get('commerce_payment.commerce_payment_gateway.passimpay')->getRawData();

    /** @var \Drupal\passimpay\IPNCPHandler $ipn_handler */
    $ipn_handler = \Drupal::service('passimpay.ipn_cp_handler');

    if ($config['configuration']['ipn_logging'] == 'yes') {
      \Drupal::logger('passimpay')->warning('<pre><code>' . print_r($request_params, TRUE) . '</code></pre>');
    }

    $data = [
      'platform_id' => (int) $request_params['platform_id'], // Platform ID
      'payment_id' => (int) $request_params['payment_id'], // currency ID
      'order_id' => (int) $request_params['order_id'], // Payment ID of your platform
      'amount' => $request_params['amount'], // transaction amount
      'txhash' => $request_params['txhash'], // Hash or transaction ID. You can find the transaction ID in the PassimPay transaction history in your account.
      'address_from' => $request_params['address_from'], // sender address
      'address_to' => $request_params['address_to'], // recipient address
      'fee' => $request_params['fee'], // network fee
    ];

    if (isset($request_params['confirmations'])) {
      $data['confirmations'] = $request_params['confirmations']; // number of network confirmations (Bitcoin, Litecoin, Dogecoin, Bitcoin Cash)
    }

    $payload = http_build_query($data);
    $apikey = $config['configuration']['ipn_secret'];
    $hash = $_POST['hash'];

    if (!isset($hash) || hash_hmac('sha256', $payload, $apikey) != $hash)  {
      \Drupal::logger('passimpay')->error('<pre><code>' . print_r($request_params, TRUE) . '</code></pre>');
      //      return [];
    }

    $order = \Drupal::entityTypeManager()
      ->getStorage('commerce_order')->load($data['order_id']);
    if ($order instanceof OrderInterface) {
      $state = $order->get('state')->value;
      $total_price = $order->getTotalprice()->getNumber();
      $currency = $order->getTotalprice()->getCurrencyCode();


      $payments = \Drupal::entityQuery('commerce_payment')
        ->condition('order_id', $order->id())
        ->execute();

      $total_amount = [];
      foreach ($payments as $value) {
        $payment = Payment::load($value);
        $total_amount[$payment->get('remote_id')->getString()] =  $payment->getAmount()->getNumber();
        $payment->setState('completed')->save();
      }

      // создаём платёж.
      if (!isset($total_amount[$data['txhash']])) {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');

        $curencies_list = $ipn_handler->getCurencies();

        $amount = new Price($data['amount'], $curencies_list[$request_params['payment_id']]['currency']);
        $payment = $payment_storage->create([
          'state' => 'completed',
          'amount' => $amount,
          'payment_gateway' => $order->get('payment_gateway')->getString(),
          'order_id' => $order->id(),
          'remote_id' => $data['txhash'],
        ]);
        $payment->save();
        $total_amount[$payment->get('remote_id')->getString()] =  $payment->getAmount()->getNumber();
      }

      // Проверяе, оплачен ли заказ.
      $status = $ipn_handler->getOrderStatus($order);
      if ($status == 'paid') {
        $order->set('state', 'completed');
        $order->save();
      }
    }
    return [];
  }


}
