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
    $items = $order->getItems();

    /** @var \Drupal\passimpay\IPNCPHandler $ipn_handler */
    $ipnHandler = \Drupal::service('passimpay.ipn_cp_handler');
    $curencyList = $ipnHandler->getCurencies('currency');
    foreach ($items as $item) {
      if ($item->getUnitPrice()->getCurrencyCode() !== 'USD') {
        $rateUsd = $curencyList[$item->getUnitPrice()->getCurrencyCode()]['rate_usd'];
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

    $url = 'https://passimpay.io/api/createorder';
    $platformId = $paymentConfiguration['merchant_id']; // Platform ID
    $apikey = $paymentConfiguration['ipn_secret']; // Secret key
    $orderId = $order->id(); // Payment ID of your platform
    $amount = $order->getTotalPrice()->getNumber(); // USD, decimals - 2
    $amount = number_format((float)$amount, 2, '.', '');
    $payload = http_build_query(['platform_id' => $platformId, 'order_id' => $orderId, 'amount' => $amount]);
    $hash = hash_hmac('sha256', $payload, $apikey);

    $data = [
      'platform_id' => $paymentConfiguration['merchant_id'],
      'order_id' => $orderId,
      'amount' => number_format((float)$amount, 2, '.', ''),
      'hash' => $hash
    ];

    $postData = http_build_query($data);


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    //curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    //curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    $result = curl_exec($curl);
    curl_close( $curl );

    $result = json_decode($result, true);

    // Response options
    // In case of success
    if (isset($result['result']) && $result['result'] == 1) {
      $order->set('state', 'checkout');
      $order->save();
      sleep(1);
      $this->buildRedirectForm($form, $formState, $result['url'], []);
    } else {
      return [
        'content' => $result['message'],
      ];
    }
    return [
      'content' => $this->t('Something went wrong...'),
    ];
  }

}
