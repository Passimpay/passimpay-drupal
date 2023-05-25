<?php

namespace Drupal\passimpay\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;

/**
 * Returns responses for passimpay module routes.
 */
class PassimpayController extends ControllerBase 
{

  /**
   * {@inheritdoc}
   */
  public function paymentProcessing() 
  {
    $requestParams = \Drupal::request()->request->all();

    $config = \Drupal::service('config.factory')
		->get('commerce_payment.commerce_payment_gateway.passimpay')
		->getRawData();

    /** @var \Drupal\passimpay\IPNCPHandler $ipnHandler */
    $ipnHandler = \Drupal::service('passimpay.ipn_cp_handler');

    if ($config['configuration']['ipn_logging'] == 'yes') {
      \Drupal::logger('passimpay')->warning('<pre><code>' . print_r($requestParams, true) . '</code></pre>');
    }

    $data = [
      'platform_id' => (int) $requestParams['platform_id'], // Platform ID
      'payment_id' => (int) $requestParams['payment_id'], // currency ID
      'order_id' => (int) $requestParams['order_id'], // Payment ID of your platform
      'amount' => $requestParams['amount'], // transaction amount
      'txhash' => $requestParams['txhash'],
      'address_from' => $requestParams['address_from'], // sender address
      'address_to' => $requestParams['address_to'], // recipient address
      'fee' => $requestParams['fee'], // network fee
    ];

    if (isset($requestParams['confirmations'])) {
      $data['confirmations'] = $requestParams['confirmations'];
    }

    $payload = http_build_query($data);
    $apikey = $config['configuration']['ipn_secret'];
    $hash = $_POST['hash'];

    if (!isset($hash) || hash_hmac('sha256', $payload, $apikey) != $hash) {
      \Drupal::logger('passimpay')->error('<pre><code>' . print_r($requestParams, true) . '</code></pre>');
    }

    $order = \Drupal::entityTypeManager()
      ->getStorage('commerce_order')->load($data['order_id']);
    if ($order instanceof OrderInterface) {


      $payments = \Drupal::entityQuery('commerce_payment')
        ->condition('order_id', $order->id())
        ->execute();

      $totalAmount = [];
      foreach ($payments as $value) {
        $payment = Payment::load($value);
        $totalAmount[$payment->get('remote_id')->getString()] =  $payment->getAmount()->getNumber();
        $payment->setState('completed')->save();
      }

      // создаём платёж.
      if (!isset($totalAmount[$data['txhash']])) {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $paymentStorage = \Drupal::entityTypeManager()->getStorage('commerce_payment');

        $curenciesList = $ipnHandler->getCurencies();

        $amount = new Price($data['amount'], $curenciesList[$requestParams['payment_id']]['currency']);
        $payment = $paymentStorage->create([
          'state' => 'completed',
          'amount' => $amount,
          'payment_gateway' => $order->get('payment_gateway')->getString(),
          'order_id' => $order->id(),
          'remote_id' => $data['txhash'],
        ]);
        $payment->save();
        $totalAmount[$payment->get('remote_id')->getString()] =  $payment->getAmount()->getNumber();
      }

      // Проверяе, оплачен ли заказ.
      $status = $ipnHandler->getOrderStatus($order);
      if ($status == 'paid') {
        $order->set('state', 'completed');
        $order->save();
      }
    }
    return [];
  }


}
