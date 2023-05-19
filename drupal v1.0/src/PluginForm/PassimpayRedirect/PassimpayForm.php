<?php

namespace Drupal\passimpay\PluginForm\PassimpayRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;

class PassimpayForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    /** @var \Drupal\passimpay\IPNCPHandler $ipn_handler */
    $ipn_handler = \Drupal::service('passimpay.ipn_cp_handler');

    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $payment_configuration = $payment_gateway_plugin->getConfiguration();
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
    $items = $order->getItems();


//    $currency_code = $payment_configuration['currency_code'];

    /** @var \Drupal\passimpay\IPNCPHandler $ipn_handler */
    $ipn_handler = \Drupal::service('passimpay.ipn_cp_handler');
    $curency_list = $ipn_handler->getCurencies('currency');
    foreach ($items as $item) {
      if ($item->getUnitPrice()->getCurrencyCode() !== 'USD') {
        $rate_usd = $curency_list[$item->getUnitPrice()->getCurrencyCode()]['rate_usd'];
        $price = $item->getUnitPrice()->getNumber();
        $usd_price = $rate_usd * $price;
      } else {
        $usd_price = $item->getUnitPrice()->getNumber();;
      }

      $unit_price = new Price($usd_price, 'USD');
      $item->setUnitPrice($unit_price,TRUE);
      $item->save();
    }
    $order->recalculateTotalPrice();
    $order->save();

    $url = 'https://passimpay.io/api/createorder';
    $platform_id = $payment_configuration['merchant_id']; // Platform ID
    $apikey = $payment_configuration['ipn_secret']; // Secret key
    $order_id = $order->id(); // Payment ID of your platform
    $amount = $order->getTotalPrice()->getNumber(); // USD, decimals - 2
    $amount = number_format((float)$amount, 2, '.', '');
    $payload = http_build_query(['platform_id' => $platform_id, 'order_id' => $order_id, 'amount' => $amount]);
    $hash = hash_hmac('sha256', $payload, $apikey);

    $data = [
      'platform_id' => $payment_configuration['merchant_id'],
      'order_id' => $order_id,
      'amount' => number_format((float)$amount, 2, '.', ''),
      'hash' => $hash
    ];

    $post_data = http_build_query($data);


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    $result = curl_exec($curl);
    curl_close( $curl );

    $result = json_decode($result, true);

    // Response options
    // In case of success
    if (isset($result['result']) && $result['result'] == 1)  {
      $order->set('state', 'checkout');
      $order->save();
      sleep(1);
      $this->buildRedirectForm($form, $form_state, $result['url'], []);
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
