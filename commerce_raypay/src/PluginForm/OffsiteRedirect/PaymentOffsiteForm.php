<?php

namespace Drupal\commerce_raypay\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;


class PaymentOffsiteForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Client $http_client, MessengerInterface $messenger) {
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();
    $order_id = $order->id();
    $payment_gateway = $payment->getPaymentGateway();

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $gateway_configuration = $payment_gateway_plugin->getConfiguration();

    $user_id = $gateway_configuration['user_id'];
    $marketing_id = $gateway_configuration['marketing_id'];
    $invoice_id             = round(microtime(true) * 1000);

    $amount = (int) $payment->getAmount()->getNumber();

    if ($payment->getAmount()->getCurrencyCode() == 'TMN') {
      // Considers all of currency codes as IRR except TMN (Iranian Toman, an unofficial currency code)
      // If the currency code is 'TMN', converts Iranian Tomans to Iranian Rials by multiplying by 10.
      // This is due to accepting Iranian Rial as the currency code by the gateway.
      $amount *= 10;
    }

    $mode = $gateway_configuration['mode'];
    $sandbox=  ($mode == 'test') ? true : false;
    $callback = Url::fromUri('base:/checkout/' . $order_id . '/payment/return/', ['absolute' => TRUE]);
    $callback->setOption('query', [
          'order_id' => $order_id,
          'invoice_id' => $invoice_id,
      ]);
    $callback = $callback->toString();
    // Customer information
    $name = '';
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile->hasField('address')) {
      /** @var \Drupal\address\AddressInterface|null $address */
      $address = !$billing_profile->get('address')->isEmpty() ? $billing_profile->get('address')->first() : NULL;
      if($address) {
        $name = $address->getGivenName() . ' '. $address->getFamilyName();
      }
    }
    $mail = $order->getEmail();

    $url = 'https://api.raypay.ir/raypay/api/v1/payment/pay';
    $params = [
      'factorNumber' => strval($order_id),
      'amount' => strval($amount),
      'userID' => $user_id,
      'marketingID' => $marketing_id,
      'invoiceID'    => strval($invoice_id),
      'redirectUrl'  => $callback,
      'fullName' => $name,
      'email' => $mail,
      'desc' => 'پرداخت دروپال 8 با شماره سفارش ' . $order_id,
      'enableSandBox' => $sandbox
    ];

    $headers = [
      'Content-Type' => 'application/json'
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'body' => json_encode($params),
      ]);
      $response_content = $response->getBody()->getContents();
      $response_content = json_decode($response_content);
      $token=$response_content->Data;
      $link='https://my.raypay.ir/ipg?token=' . $token;

      // Create a new payment but with state 'Authorization' not completed.
      // On payment return, if everything is ok, the state of this new payment will be converted to 'Completed'.
      $new_payment = $this->paymentStorage->create([
        'state' => 'authorization',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $payment_gateway,
        'order_id' => $order->id(),
        'remote_id' => $invoice_id,
      ]);
      $new_payment->save();

      return $this->buildRedirectForm($form, $form_state, $link, [], PaymentOffsiteForm::REDIRECT_GET);
    } catch (RequestException $e) {
      if ($e->getCode() >= 400 && $e->getCode() < 500) {
        if ($e->getResponse()) {
          $response_content = \GuzzleHttp\json_decode($e->getResponse()
            ->getBody()
            ->getContents());
          $this->messenger->addError($response_content->Message);

          throw new InvalidResponseException(
            "commerce_raypay: " . $this->t(
              'An error occurred with http code: %http_code, error_code: %error_code and error_message: "@error_message" when accessing the payment endpoint: @url', [
              '%http_code' => $e->getCode(),
              '%error_code' => $response_content->StatusCode,
              '@error_message' => $response_content->Message,
              '@url' => $e->getRequest()->getUri(),
            ]));
        }
        throw new InvalidResponseException("commerce_raypay: " . $e->getMessage());
      }
      elseif ($e->getCode() > 500) {
        throw new InvalidResponseException("commerce_raypay: " . $e->getMessage());
      }
    }
  }

}
