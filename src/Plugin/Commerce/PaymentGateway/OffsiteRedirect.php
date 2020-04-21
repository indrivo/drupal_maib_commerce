<?php

namespace Drupal\commerce_maib\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_price\Price;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Fruitware\MaibApi\MaibClient;
use GuzzleHttp\Client;
use Drupal\Core\Url;
use Drupal\commerce_maib\MAIBGateway;
use Drupal\commerce_maib\Exception\MAIBException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "maib_redirect",
 *   label = "MAIB (Off-site redirect)",
 *   display_label = "MAIB",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_maib\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'private_key_path' => '',
      'private_key_password' => '',
      'public_key_path' => '',
      'intent' => 'capture',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $urls = [
      Url::fromRoute('commerce_maib.checkout_return', [], ['absolute' => TRUE])->toString(),
      Url::fromRoute('commerce_maib.checkout_cancel', [], ['absolute' => TRUE])->toString(),
    ];
    $form['urls_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Return and cancel URLs to be provided to bank'),
      '#markup' => implode('<br>' . PHP_EOL, $urls),
    ];

    // @TODO: validate key/certificate pairing and expiration.
    $form['private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the private key PEM file'),
      '#default_value' => $this->configuration['private_key_path'],
    ];

    $form['private_key_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password for private key'),
      '#description' => $this->t('Leave empty if no change intended'),
      '#default_value' => '',
    ];

    $form['public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the certificate PEM file containing public key'),
      '#default_value' => $this->configuration['public_key_path'],
    ];

    $form['intent'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transaction type'),
      '#options' => [
        'capture' => $this->t("Capture (capture payment immediately after customer's approval)"),
        'authorize' => $this->t('Authorize (requires manual or automated capture after checkout)'),
      ],
      '#description' => $this->t('For more information on capturing a prior authorization,'
        . 'please refer to <a href=":url" target="_blank">Capture an authorization</a>.',
        [':url' => 'https://docs.drupalcommerce.org/commerce2/user-guide/payments/capture']),
      '#default_value' => $this->configuration['intent'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $old_configuration = $this->getConfiguration();
    $old_pass = $old_configuration['private_key_password'] ?? '';
    //\Drupal::config('commerce_payment.commerce_payment_gateway.maib')->get('configuration.private_key_password');
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['private_key_path'] = $values['private_key_path'];
      $this->configuration['public_key_path'] = $values['public_key_path'];
      $this->configuration['private_key_password'] = empty($values['private_key_password']) ? $old_pass : $values['private_key_password'];
      $this->configuration['intent'] = $values['intent'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $transactionId = $request->get(MAIBGateway::MAIB_TRANS_ID);;
    if (empty($transactionId)) {
      throw new MAIBException($this->t('MAIB return redirect error: Missing TRANSACTION_ID'));
    }
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties([
      'remote_id' => $transactionId,
      'payment_gateway' => commerce_maib_get_all_gateway_ids(),
    ]);
    if (empty($payments)) {
      throw new MAIBException($this->t('MAIB error: failed to locate payment for TRANSACTION_ID @id', ['@id' => $transactionId]));
    }
    $payment = reset($payments);

    try {
      // Get transaction information.
      $payment_info = $this->getClient()->getTransactionResult($transactionId, $order->getIpAddress());
    }
    catch (\Exception $e) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $e->getMessage()]));
    }

    if (!empty($payment_info['error'])) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $payment_info['error']]));
    }

    if ($payment_info[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      $intent = $this->configuration['intent'] ?? null;
      if ($intent == 'authorize') {
        $payment->setState('authorization')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
        $this->messenger()->addMessage($this->t('Your transaction was successful.'));
        \Drupal::logger('commerce_maib')
          ->notice('Completed authorization payment @payment with transaction id @trans_id for order @order. @data',
            [
              '@trans_id' => $transactionId,
              '@order' => $order->id(),
              '@payment' => $payment->id(),
              '@data' => Json::encode($payment_info),
            ]);
      }
      else {
        $payment->setState('completed')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
        $this->messenger()->addMessage($this->t('Your transaction was successful.'));
        \Drupal::logger('commerce_maib')
          ->notice('Completed payment @payment with transaction id @trans_id for order @order. @data',
            [
              '@trans_id' => $transactionId,
              '@order' => $order->id(),
              '@payment' => $payment->id(),
              '@data' => Json::encode($payment_info),
            ]);
      }
    }
    elseif ($payment_info[MAIBGateway::MAIB_RESULT] != MAIBGateway::MAIB_RESULT_PENDING) {
      $this->messenger()->addError($this->t('Your transaction was cancelled. Remote status: @status', [
        '@status' => $payment_info[MAIBGateway::MAIB_RESULT],
      ]));
      \Drupal::logger('commerce_maib')->error('Voided payment @payment with transaction id @trans_id for order @order. Remote status was @remote. @data',
        [
          '@trans_id' => $transactionId,
          '@order' => $order->id(),
          '@payment' => $payment->id(),
          '@remote' => $payment_info[MAIBGateway::MAIB_RESULT],
          '@data' => Json::encode($payment_info),
        ]);
      $payment->delete();

      throw new MAIBException($this->t('Payment failed. Remote status: @status. Remote reason: @reason.', [
        '@status' => $payment_info[MAIBGateway::MAIB_RESULT],
        '@reason' => $payment_info[MAIBGateway::MAIB_RESULT_CODE],
      ]));
    }
    else {
      $payment->setState('pending')->setRemoteState($payment_info[MAIBGateway::MAIB_RESULT])->save();
      $this->messenger()->addMessage($this->t('Your transaction is still in pendin process. Please check its status later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    return parent::onCancel($order, $request);
  }

  /**
   * Gets the redirect URL.
   *
   * @return string
   *   The redirect URL.
   */
  public function getRedirectUrl() {
    if ($this->getMode() == 'test') {
      return MAIBGateway::MAIB_TEST_REDIRECT_URL;
    }
    else {
      return MAIBGateway::MAIB_LIVE_REDIRECT_URL;
    }
  }

  /**
   * Gets endpoint URI.
   *
   * @return string
   *   The endpoint URI.
   */
  public function getBaseUri() {
    if ($this->getMode() == 'test') {
      return MAIBGateway::MAIB_TEST_BASE_URI;
    }
    else {
      return MAIBGateway::MAIB_LIVE_BASE_URI;
    }
  }

  /**
   * Gets MAIB Client object.
   *
   * @return Fruitware\MaibApi\MaibClient
   *   Return MAIB client object with required values.
   */
  public function getClient(): MaibClient {
    $configuration = $this->getConfiguration();
    $options = [
      'base_uri' => $this->getBaseUri(),
      'debug' => FALSE,
      'verify' => TRUE,
      'cert' => $configuration['public_key_path'],
      'ssl_key' => [
        $configuration['private_key_path'],
        $configuration['private_key_password'],
      ],
      'config' => [
        'curl' => [
          CURLOPT_SSL_VERIFYHOST => TRUE,
          CURLOPT_SSL_VERIFYPEER => TRUE,
        ],
      ],
    ];
    $guzzleClient = new Client($options);
    $client = new MaibClient($guzzleClient);

    return $client;
  }

  /**
   * Store payment with transaction id from remote before redirect.
   *
   * @param Drupal\commerce_order\Entity\OrderInterface $order
   *   Order object.
   * @param string $transactionId
   *   Transition id.
   *
   * @return Drupal\commerce_payment\Entity\PaymentInterface
   *   Commerce payment object.
   */
  public function storePendingPayment(OrderInterface $order, string $transactionId): PaymentInterface {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'new',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $transactionId,
      'remote_state' => MAIBGateway::MAIB_RESULT_CREATED,
    ]);
    $payment->save();

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Perform the capture request here, throw an exception if it fails.
    try {
      $transaction_id = $payment->getRemoteId();
      $decimal_amount = $amount->getNumber();
      $currency = $payment->getAmount()->getCurrencyCode();
      $currencyObj = \Drupal::entityTypeManager()->getStorage('commerce_currency')->load($currency);
      $clientIpAddr = $payment->getOrder()->getIpAddress();
      $description = (string) $this->t('Order #@id', ['@id' => $payment->getOrderId()]);
      $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

      $result = $this->getClient()->makeDMSTrans($transaction_id, $decimal_amount, $currencyObj->getNumericCode(), $clientIpAddr, $description, $language);
    }
    catch (\Exception $e) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $e->getMessage()]));
    }

    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      $payment->setState('completed');
      $payment->setRemoteState($result[MAIBGateway::MAIB_RESULT]);
      $payment->setAmount($amount);
      $payment->save();
      \Drupal::logger('commerce_maib')
        ->notice('Completed authorized payment @payment with transaction id @trans_id for order @order and amount @amount @curr',
          [
            '@trans_id' => $payment->getRemoteId(),
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
            '@amount' => $decimal_amount,
            '@curr' => $currencyObj->id(),
          ]);
    }

    else {
      throw new MAIBException($this->t('MAIB result not OK: @data', ['@data' => Json::encode($result)]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    $transactionId = $payment->getRemoteId();
    try {
      $result = $this->getClient()->revertTransaction($transactionId, $payment->getAmount());
    }
    catch (\Exception $e) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $e->getMessage()]));
    }

    if (!empty($result['error'])) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $result['error']]));
    }
    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {
      \Drupal::logger('commerce_maib')
        ->notice('Voided payment @payment with transaction id @trans_id for order @order',
          [
            '@trans_id' => $transactionId,
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
          ]);
      $payment->delete();
    }
    else {
      throw new MAIBException($this->t('MAIB result not OK: @data', ['@data' => Json::encode($result)]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    try {
      $this->assertPaymentState($payment, ['completed']);
      $amount = $amount ?: $payment->getAmount();
      $this->assertRefundAmount($payment, $amount);
    }
    catch (\Exception $e) {
      throw new MAIBException($this->t('Refund error: @error', ['@error' => $e->getMessage()]));
    }

    try {
      // MAIB only support full refund for the payment of the authorization.
      $result = $this->getClient()->revertTransaction($payment->getRemoteId(), $amount);
    }
    catch (\Exception $e) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $e->getMessage()]));
    }

    if (!empty($result['error'])) {
      throw new MAIBException($this->t('MAIB error: @error', ['@error' => $result['error']]));
    }
    // If MAIB response was OK do refund.
    if ($result[MAIBGateway::MAIB_RESULT] == MAIBGateway::MAIB_RESULT_OK) {

      $payment->setState('refunded');
      $payment->setRefundedAmount($amount);
      $payment->save();

      \Drupal::logger('commerce_maib')
        ->notice('Refunded payment @payment with transaction id @trans_id for order @order. Data: @data.',
          [
            '@trans_id' => $payment->getRemoteId(),
            '@order' => $payment->getOrder()->id(),
            '@payment' => $payment->id(),
            '@data' => Json::encode($result),
          ]);
    }
    else {
      throw new MAIBException($this->t('MAIB result not OK: @data', ['@data' => Json::encode($result)]));
    }
  }

}
