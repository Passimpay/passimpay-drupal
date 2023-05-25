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
    $config = $this->getConfig();
    $url = 'https://passimpay.io/api/currencies';
    $apikey = $config['ipn_secret'];
    $platformId = $config['merchant_id'];

    $payload = http_build_query(['platform_id' => $platformId ]);
    $hash = hash_hmac('sha256', $payload, $apikey);

    $data = [
      'platform_id' => $platformId,
      'hash' => $hash,
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
    curl_close($curl);

    $result = json_decode($result, true);
    // Варианты ответов
    // В случае успеха
    if (isset($result['result']) && $result['result'] == 1) {
      return $this->curenciesLocalData($result['list'], $sortKey);
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
      $curenciesList[$curencie[$sortKey]] = $curencie;
    }

    return $curenciesList;
  }

  public function getOrderStatus(OrderInterface $order) 
  {

    $url = 'https://passimpay.io/api/orderstatus';
    $platformId = $this->getConfig()['merchant_id']; // Platform ID
    $apikey = $this->getConfig()['ipn_secret'];
    $orderId = $order->id(); // Payment ID of your platform

    $payload = http_build_query(['platform_id' => $platformId, 'order_id' => $orderId ]);
    $hash = hash_hmac('sha256', $payload, $apikey);

    $data = [
      'platform_id' => $platformId,
      'order_id' => $orderId,
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
    curl_close($curl);

    $result = json_decode($result, true);

    // Response options
    // In case of success
    if (isset($result['result']) && $result['result'] == 1) {
      $status = $result['status']; // paid, error, wait
      \Drupal::logger('passimpay')->warning('<pre><code>' . print_r($result, true) . '</code></pre>');
      return $status;
    } else {
      $error = $result['message']; // Error text
    }
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
