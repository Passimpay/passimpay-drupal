<?php

namespace Drupal\passimpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "passimpay_redirect",
 *   label = "PassimPay.io - Pay with Bitcoin, Litecoin, and other cryptocurrencies (Off-site redirect)",
 *   display_label = "PassimPay.io",
 *    forms = {
 *     "offsite-payment" = "Drupal\passimpay\PluginForm\PassimpayRedirect\PassimpayForm",
 *   },
 *   payment_method_types = {"credit_card"},
 * )
 */

class PassimpayRedirect extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $merchant_id = !empty($this->configuration['merchant_id']) ? $this->configuration['merchant_id'] : '';
    $ipn_secret = !empty($this->configuration['ipn_secret']) ? $this->configuration['ipn_secret'] : '';
    $currency_code = !empty($this->configuration['currency_code']) ? $this->configuration['currency_code'] : '';
    $allow_supported_currencies = !empty($this->configuration['allow_supported_currencies']) ? $this->configuration['allow_supported_currencies'] : '';
    $ipn_logging = !empty($this->configuration['ipn_logging']) ? $this->configuration['ipn_logging'] : '';

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Passimpay.io Merchant ID'),
      '#default_value' => $merchant_id,
      '#description' => $this->t('The Merchant ID of your Passimpay.io account.'),
      '#required' => TRUE,
    ];

    $form['ipn_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IPN Secret'),
      '#default_value' => $ipn_secret,
      '#description' => $this->t('Set on the Edit Settings page at Passimpay.io'),
      '#required' => TRUE,
    ];

    $form['currency_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Default currency'),
      '#options' => ['USD' => 'USD'],
      '#default_value' => $currency_code,
      '#description' => $this->t('Transactions in other currencies will be converted to this currency, so multi-currency sites must be configured to use appropriate conversion rates.'),
    ];

//    $form['allow_supported_currencies'] = [
//      '#type' => 'checkbox',
//      '#title' => $this->t('Allow transactions to use any currency in the options list above.'),
//      '#default_value' => $allow_supported_currencies,
//      '#description' => $this->t('Transactions in unsupported currencies will still be converted into the default currency.'),
//    ];

    $form['ipn_logging'] = [
      '#type' => 'radios',
      '#title' => $this->t('IPN logging'),
      '#options' => [
        'no' => $this->t('Only log IPN errors.'),
        'yes' => $this->t('Log full IPN data (used for debugging).'),
      ],
      '#default_value' => $ipn_logging,
    ];

    $form['mode']['#access'] = FALSE;

    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'business' => '',
      'allow_supported_currencies' => FALSE,
      'ipn_logging' => 'yes',
      'merchant' => '',
      'ipn_secret' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if (!$form_state->getErrors() && $form_state->isSubmitted()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['ipn_secret'] = $values['ipn_secret'];
      $this->configuration['currency_code'] = $values['currency_code'];
      $this->configuration['allow_supported_currencies'] = $values['allow_supported_currencies'];
      $this->configuration['ipn_logging'] = $values['ipn_logging'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['ipn_secret'] = $values['ipn_secret'];
      $this->configuration['currency_code'] = $values['currency_code'];
      $this->configuration['allow_supported_currencies'] = $values['allow_supported_currencies'];
      $this->configuration['ipn_logging'] = $values['ipn_logging'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $status = $request->get('status');
    \Drupal::messenger()->addMessage($this->t('Payment @status on @gateway but may resume the checkout process here when you are ready.', [
      '@status' => $status,
      '@gateway' => $this->getDisplayLabel(),
    ]), 'error');
  }

}
