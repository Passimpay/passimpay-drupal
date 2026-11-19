<?php

namespace Drupal\passimpay\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for passimpay module routes.
 */
class PassimpayController extends ControllerBase
{
  public function paymentProcessing()
  {
    $request = \Drupal::request();
    if (!$request->isMethod('POST')) {
      return new Response('Method not allowed', 405);
    }
    $stream = $request->getContent(true);
    $rawBody = stream_get_contents($stream, 65537);
    if ($rawBody === false || strlen($rawBody) > 65536) {
      return new Response('Invalid body', 413);
    }
    $data = json_decode($rawBody, true);
    $ipnHandler = \Drupal::service('passimpay.ipn_cp_handler');
    $config = $ipnHandler->getConfig();
    $platformId = $config['merchant_id'] ?? null;
    $apikey = $config['ipn_secret'] ?? null;
    if (filter_var($platformId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !is_string($apikey) || $apikey === '') {
      return new Response('Payment not configured', 503);
    }
    if (!$this->validateWebhook($data, (int)$platformId) || !isset($data['paymentId'], $data['amount'], $data['txhash'])
      || $data['txhash'] === '' || strlen($data['txhash']) > 255 || (float)$data['amount'] <= 0) {
      return new Response('Invalid payload', 400);
    }
    $signature = $request->headers->get('x-signature', '');
    $body = json_encode($data);
    if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature) || $body === false
      || !hash_equals(hash_hmac('sha256', $platformId . ';' . $body . ';' . $apikey, $apikey), $signature)) {
      return new Response('Invalid signature', 403);
    }
    $lock = \Drupal::lock();
    $lockName = 'passimpay:order:' . $data['orderId'];
    if (!$lock->acquire($lockName)) {
      return new Response('Order is busy', 503);
    }
    $transaction = \Drupal::database()->startTransaction();
    try {
      // Блокировка строки защищает от повторного зачисления даже при истечении временной блокировки.
      \Drupal::database()->select('commerce_order', 'o')->fields('o', ['order_id'])
        ->condition('order_id', $data['orderId'])->forUpdate()->execute()->fetchField();
      // После ожидания блокировки нужен актуальный заказ, без сохранённой в кеше версии.
      \Drupal::entityTypeManager()->getStorage('commerce_order')->resetCache([$data['orderId']]);
      $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadUnchanged($data['orderId']);
      if (!$order instanceof OrderInterface || $order->get('payment_gateway')->target_id !== 'passimpay') {
        return new Response('Order not found', 404);
      }
      $invoice = $order->getData('passimpay_invoice');
      if (!is_array($invoice) || ($invoice['platform_id'] ?? null) !== (int)$platformId
        || ($invoice['currency'] ?? null) !== 'USD' || !isset($invoice['amount']) || !is_string($invoice['amount'])
        || !preg_match('/^[0-9]+\.[0-9]{2}$/D', $invoice['amount']) || (float)$invoice['amount'] <= 0) {
        return new Response('Invoice amount unavailable', 503);
      }
      $remoteId = $platformId . ':' . $data['orderId'];
      $payments = \Drupal::entityQuery('commerce_payment')->accessCheck(false)
        ->condition('order_id', $order->id())->execute();
      foreach (Payment::loadMultiple($payments) as $payment) {
        if ($payment->get('payment_gateway')->target_id === 'passimpay' && $payment->getRemoteId() === $remoteId
          && $payment->getAmount()->equals(new Price($invoice['amount'], 'USD'))) {
          // Платёж по счёту уже записан: повтор не меняет его и последующие статусы заказа.
          return new Response('OK', 200);
        }
      }
      $status = $ipnHandler->getOrderStatus($order);
      if ($status === null) {
        return new Response('Status check failed', 503);
      }
      if ($status !== 'paid') {
        return new Response('OK', 200);
      }
      if ($payments || $order->getTotalPrice()->getCurrencyCode() !== 'USD'
        || number_format((float)$order->getTotalPrice()->getNumber(), 2, '.', '') !== $invoice['amount']) {
        return new Response('Order no longer matches the invoice', 503);
      }
      try {
        // Commerce учитывает погашенный счёт в USD, а криптосумма остаётся сведениями о поступлении.
        $payment = \Drupal::entityTypeManager()->getStorage('commerce_payment')->create([
          'state' => 'completed',
          'amount' => new Price($invoice['amount'], 'USD'),
          'payment_gateway' => 'passimpay', 'order_id' => $order->id(), 'remote_id' => $remoteId,
        ]);
        $payment->save();
        $order->setData('passimpay_payment', [
          'payment_id' => $data['paymentId'], 'amount' => $data['amount'], 'txhash' => $data['txhash'],
        ]);
        // Пересчёт выполняется внутри транзакции, чтобы ошибка учёта не оставила отдельный платёж.
        \Drupal::service('commerce_payment.order_updater')->updateOrder($order);
        $order->setRefreshState(OrderInterface::REFRESH_SKIP);
        $order->set('state', 'completed');
        $order->save();
        unset($transaction);
      } catch (\Throwable $ex) {
        $transaction->rollBack();
        \Drupal::logger('passimpay')->error('Unable to apply payment notification.');
        return new Response('Unable to apply payment', 503);
      }
      return new Response('OK', 200);
    } finally {
      unset($transaction);
      $lock->release($lockName);
    }
  }

    private function validateWebhook($params, $platformId)
    {
        if (!is_array($params) || !isset($params['type'], $params['platformId'], $params['orderId'])
            || $params['type'] !== 'deposit' || !is_int($params['platformId']) || $params['platformId'] <= 0
            || $params['platformId'] !== $platformId || !is_string($params['orderId'])
            || strlen($params['orderId']) > 64 || !preg_match('/^[1-9][0-9]*$/D', $params['orderId'])
            || filter_var($params['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }
        foreach (['paymentId', 'confirmations', 'destinationTag'] as $field) {
            if (array_key_exists($field, $params) && (!is_int($params[$field]) || $params[$field] < ($field === 'paymentId' ? 1 : 0))) {
                return false;
            }
        }
        foreach (['amount', 'amountReceive', 'feeService', 'feeNetwork'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 128
                || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $params[$field]))) {
                return false;
            }
        }
        foreach (['txhash', 'addressFrom', 'addressTo'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 512)) {
                return false;
            }
        }
        return true;
    }
}
