<?php

namespace Drupal\passimpay;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

class IPNCPHandler 
{

  /**
   * The database connection to use.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The config object for 'commerce_payment.commerce_payment_gateway.passimpay'.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $commercePassimpay;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config object.
   */
  public function __construct(
	Connection $connection, 
	EntityTypeManagerInterface $entityTypeManager, 
	LoggerInterface $logger, 
	ClientInterface $client, 
	ConfigFactoryInterface $configFactory
	) 
  {
    $this->connection = $connection;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
    $this->httpClient = $client;
    $this->commercePassimpay = $configFactory->get('commerce_payment.commerce_payment_gateway.passimpay');
  }


  /**
   * @param $config
   *
   * @return mixed|void
   */
  public function getCurencies($sortKey = 'id') 
  {
    $url = 'https://api.passimpay.io/v2/currencies';
    $config = $this->getConfig();
    $platformId = filter_var($config['merchant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $apikey = $config['ipn_secret'];
    if ($platformId === false || !is_string($apikey) || $apikey === '') {
      return null;
    }
    $postData = json_encode(['platformId' => $platformId]);
    $signature = hash_hmac('sha256', $platformId . ';' . $postData . ';' . $apikey, $apikey);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-signature: ' . $signature]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    $result = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $result = is_string($result) ? json_decode($result, true) : null;
    // Варианты ответов
    // В случае успеха
    if ($httpCode === 200 && is_array($result) && ($result['result'] ?? null) === 1) {
      if (isset($result['list']) && is_array($result['list'])) {
        return $this->curenciesLocalData($result['list'], $sortKey);
      }
    }
  }

  /**
   * @param $remoteCurenciesList
   *
   * @return array
   */
  private function curenciesLocalData($remoteCurenciesList, $sortKey = 'id') 
  {
    $curenciesList = [];
    foreach ($remoteCurenciesList as $curencie) {
      if (is_array($curencie) && isset($curencie[$sortKey]) && is_scalar($curencie[$sortKey])) {
        $curenciesList[$curencie[$sortKey]] = $curencie;
      }
    }

    return $curenciesList;
  }

  public function getOrderStatus(OrderInterface $order) 
  {

    $url = 'https://api.passimpay.io/v2/orderstatus';
    $platformId = $this->getConfig()['merchant_id']; // Platform ID
    $apikey = $this->getConfig()['ipn_secret'];
    $orderId = $order->id(); // Payment ID of your platform

    if (filter_var($platformId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !is_string($apikey) || $apikey === '') {
      return null;
    }
    $postData = json_encode(['platformId' => (int)$platformId, 'orderId' => (string)$orderId]);
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
    curl_close($curl);

    $result = is_string($result) ? json_decode($result, true) : null;

    if ($httpCode === 200 && is_array($result) && ($result['result'] ?? null) === 1
      && in_array($result['status'] ?? null, ['paid', 'wait', 'error'], true)) {
      return $result['status'];
    }
    return null;
  }

  /**
   * @return mixed
   */
  public function getConfig() 
  {
    $config = \Drupal::service('config.factory')
		->get('commerce_payment.commerce_payment_gateway.passimpay')
		->getRawData();
		
    return $config['configuration'];
  }
}
