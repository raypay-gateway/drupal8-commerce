<?php

namespace Drupal\commerce_raypay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;


/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "raypay_offsite_redirect",
 *   label = "RayPay (Off-site redirect)",
 *   display_label = "RayPay",
 *   forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_raypay\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"}
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

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

  /**
   * OffsiteRedirect constructor.
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param PaymentTypeManager $payment_type_manager
   * @param PaymentMethodTypeManager $payment_method_type_manager
   * @param TimeInterface $time
   * @param Client $http_client
   * @param MessengerInterface $messenger
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, Client $http_client, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['user_id'] = [
      '#type' => 'textfield',
      '#title' => 'شناسه کاربری',
      '#default_value' => $this->configuration['user_id'],
      '#description' => 'از پنل رای پی دریافت کنید.',
      '#required' => TRUE,
    ];
      $form['marketing_id'] = [
          '#type' => 'textfield',
          '#title' => 'شناسه کسب و کار',
          '#default_value' => $this->configuration['marketing_id'],
          '#description' => 'از پنل رای پی دریافت کنید.',
          '#required' => TRUE,
      ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['user_id'] = trim($values['user_id']);
      $this->configuration['marketing_id'] = trim($values['marketing_id']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    $methodGet =  'query';
    $methodPost = 'request';

    $order_id = $request->query->get('order_id');
    $invoice_id = $request->query->get('invoice_id');

    if ($order->id() != $order_id) {
      throw new PaymentGatewayException('Abuse of transaction callback.');
    }

    $payment = $this->loadPayment($invoice_id, $order_id);

    if ($payment) {
        $verify_url = 'https://api.raypay.ir/raypay/api/v1/payment/verify';
        try {
            $ch = curl_init($verify_url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_POST));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $result = curl_exec($ch);
            $response_contents = json_decode($result);

            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http_status == 200) {
                if ($response_contents->Data->Status == 1) {
                    $payment->setState('completed');
                    $payment->setRemoteState('invoice_id: ' . $response_contents->Data->InvoiceID . ' / status: ' . $response_contents->Data->Status);
                    $payment->save();
                }
                else {
                    $payment->setRemoteState('invoice_id: ' . $response_contents->Data->InvoiceID . ' / status: ' . $response_contents->Data->Status);
                    $payment->save();
                    throw new PaymentGatewayException($this->t("commerce_raypay: Payment failed with status code: %code", [
                        '%code' => $response_contents->StatusCode,
                    ]));
                }
            }
            else{
                throw new InvalidResponseException("commerce_raypay: " . $this->t('Payment failed. This is due to an error with http code: %http_code, error_code: %error_code and error_message: "@error_message"', [
                        '%http_code' => $http_status,
                        '%error_code' => $response_contents->StatusCode,
                        '@error_message' => $response_contents->Message
                    ]));
            }
        } catch (RequestException $e) {
          if ($e->getCode() >= 400 && $e->getCode() < 500) {
            if ($e->getResponse()) {
              $response_contents = \GuzzleHttp\json_decode($e->getResponse()
                ->getBody()
                ->getContents());
              $this->messenger->addError($response_contents->Message);

              throw new InvalidResponseException("commerce_raypay: " . $this->t('Payment failed. This is due to an error with http code: %http_code, error_code: %error_code and error_message: "@error_message" when accessing the inquiry endpoint: @url', [
                  '%http_code' => $e->getCode(),
                  '%error_code' => $response_contents->StatusCode,
                  '@error_message' => $response_contents->Message,
                  '@url' => $e->getRequest()->getUri(),
                ]));
            }
            throw new InvalidResponseException('commerce_raypay: ' . $e->getMessage());
          }
          elseif ($e->getCode() >= 500) {
            throw new InvalidResponseException('commerce_raypay: ' . $e->getMessage());
          }
        }
    }
    else {
      throw new PaymentGatewayException($this->t('commerce_raypay: cannot find any payment with remote id: @remote_id and order id: @order id, so that we can update it to completed.', [
        '@remote_id' => $invoice_id,
        '@order_id' => $order_id,
      ]));
    }
  }

  /**
   * Helper function for loading a commerce payment
   *
   * @param $remote_id
   * @param $order_id
   * @return bool|\Drupal\commerce_payment\Entity\Payment
   */
  private function loadPayment($remote_id, $order_id) {
    $payments = $this->paymentStorage->loadByProperties([
      'remote_id' => $remote_id,
      'order_id' => $order_id,
      'state' => 'authorization',
    ]);
    if (count($payments) == 1) {
      $payment_id = array_keys($payments)[0];
      /** @var \Drupal\commerce_payment\Entity\Payment $payment */
      $payment = $payments[$payment_id];

      return $payment;
    }
    else {
      return FALSE;
    }
  }
}
