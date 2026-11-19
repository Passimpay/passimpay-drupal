<?php

namespace Drupal\passimpay\PluginForm\PassimpayRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;

class PassimpayForm extends BasePaymentOffsiteForm 
{

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $formState) 
  {

    $form = parent::buildConfigurationForm($form, $formState);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    
    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
    $paymentConfiguration = $paymentGatewayPlugin->getConfiguration();
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
    $lock = \Drupal::lock();
    $lockName = 'passimpay:order:' . $order->id();
    if (!$lock->acquire($lockName)) {
      throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Order is busy');
    }
    try {
      $transaction = \Drupal::database()->startTransaction();
      \Drupal::database()->select('commerce_order', 'o')->fields('o', ['order_id'])
        ->condition('order_id', $order->id())->forUpdate()->execute()->fetchField();
      // После ожидания блокировки нужен актуальный заказ, без сохранённой в кеше версии.
      \Drupal::entityTypeManager()->getStorage('commerce_order')->resetCache([$order->id()]);
      $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadUnchanged($order->id());
      $invoice = $order->getData('passimpay_invoice');
      if ($invoice !== null) {
        // Повторное открытие оплаты использует ранее зафиксированный счёт.
        $parts = is_array($invoice) && isset($invoice['url']) && is_string($invoice['url']) ? parse_url($invoice['url']) : false;
        if (!is_array($invoice) || ($invoice['platform_id'] ?? null) !== (int)$paymentConfiguration['merchant_id']
          || ($invoice['currency'] ?? null) !== 'USD' || !isset($invoice['amount']) || !is_string($invoice['amount'])
          || !preg_match('/^[0-9]+\.[0-9]{2}$/D', $invoice['amount']) || (float)$invoice['amount'] <= 0
          || $order->getTotalPrice()->getCurrencyCode() !== 'USD'
          || number_format((float)$order->getTotalPrice()->getNumber(), 2, '.', '') !== $invoice['amount']
          || !$parts || !filter_var($invoice['url'], FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
          || isset($parts['user']) || isset($parts['pass'])) {
          throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Order no longer matches the invoice');
        }
        return $this->buildRedirectForm($form, $formState, $invoice['url'], [], self::REDIRECT_GET);
      }
      if (\Drupal::entityQuery('commerce_payment')->accessCheck(false)->condition('order_id', $order->id())->execute()) {
        throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Order already has payments');
      }
      $items = $order->getItems();

      /** @var \Drupal\passimpay\IPNCPHandler $ipn_handler */
      $ipnHandler = \Drupal::service('passimpay.ipn_cp_handler');
      $curencyList = $ipnHandler->getCurencies('currency');
      foreach ($items as $item) {
        $currency = $item->getUnitPrice()->getCurrencyCode();
        if ($currency !== 'USD' && (!isset($curencyList[$currency]['rateUsd']) || !is_numeric($curencyList[$currency]['rateUsd']) || $curencyList[$currency]['rateUsd'] <= 0)) {
          throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Currency rate unavailable');
        }
      }
      foreach ($items as $item) {
        if ($item->getUnitPrice()->getCurrencyCode() !== 'USD') {
          $rateUsd = $curencyList[$item->getUnitPrice()->getCurrencyCode()]['rateUsd'];
          $price = $item->getUnitPrice()->getNumber();
          $usdPrice = $rateUsd * $price;
        } else {
          $usdPrice = $item->getUnitPrice()->getNumber();;
        }

        $unitPrice = new Price($usdPrice, 'USD');
        $item->setUnitPrice($unitPrice, true);
        $item->save();
      }
      $order->recalculateTotalPrice();
      $order->save();

      $url = 'https://api.passimpay.io/v2/createorder';
      $platformId = $paymentConfiguration['merchant_id']; // Platform ID
      $apikey = $paymentConfiguration['ipn_secret']; // Secret key
      $orderId = $order->id(); // Payment ID of your platform
      $amount = $order->getTotalPrice()->getNumber(); // USD, decimals - 2
      $amount = number_format((float)$amount, 2, '.', '');
      if ($order->getTotalPrice()->getCurrencyCode() !== 'USD' || (float)$amount <= 0) {
        throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Invalid invoice amount');
      }
      if (filter_var($platformId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !is_string($apikey) || $apikey === '') {
        throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Payment not configured');
      }
      $postData = json_encode(['platformId' => (int)$platformId, 'orderId' => (string)$orderId, 'amount' => $amount, 'symbol' => 'USD', 'type' => 1]);
      $signature = hash_hmac('sha256', $platformId . ';' . $postData . ';' . $apikey, $apikey);

      $curl = curl_init();
      curl_setopt($curl, CURLOPT_HEADER, false);
      curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $signature]);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
      $result = curl_exec($curl);
      $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close( $curl );

      $result = is_string($result) ? json_decode($result, true) : null;
      $parts = is_array($result) && isset($result['url']) && is_string($result['url']) ? parse_url($result['url']) : false;
      if ($httpCode !== 200 || !is_array($result) || ($result['result'] ?? null) !== 1 || !$parts
        || !filter_var($result['url'], FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new \Drupal\commerce_payment\Exception\PaymentGatewayException('Unable to create payment');
      }
      $order->setData('passimpay_invoice', [
        'amount' => $amount, 'currency' => 'USD', 'platform_id' => (int)$platformId, 'url' => $result['url'],
      ]);
      $order->set('state', 'checkout');
      $order->save();
      unset($transaction);
    } catch (\Throwable $ex) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $ex;
    } finally {
      $lock->release($lockName);
    }
    return $this->buildRedirectForm($form, $formState, $result['url'], [], self::REDIRECT_GET);
  }

}
