<?php
/**
 * FOSSBilling payment gateway adapter for GoCardless (Direct Debit).
 *
 * Drop into:  src/library/Payment/Adapter/GoCardless.php
 *
 * Provides one-time payments and recurring subscriptions through the
 * GoCardless redirect-flows flow (hosted Direct Debit mandate setup):
 *
 *   1. getHtml() creates a /redirect_flows object and renders an auto-submitting
 *      form that sends the customer to GoCardless' hosted page.
 *   2. When the customer completes the mandate setup, GoCardless redirects back
 *      to the invoice callback URL (ipn.php) with redirect_flow_id=RE... in the
 *      query string. processTransaction() completes the flow and either creates
 *      one payment or, for subscription invoices, a subscription.
 *   3. Collections land via webhooks (payments.paid_out / payments.confirmed) on
 *      the FOSSBilling notification URL (ipn.php?gateway_id=X) as application/json.
 *      The adapter verifies the Webhook-Signature HMAC and credits the invoice
 *      only once the money actually moves (paid_out by default, or confirmed).
 *
 * Recurring collections on an active subscription pay a freshly generated
 * renewal invoice every cycle (see generateRenewalInvoiceForSubscriptionPayment).
 *
 * @see https://developer.gocardless.com/api-reference
 */

use Box\Mod\Client\Entity\Client as BoxClient;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Subscription;
use Box\Mod\Invoice\Entity\Transaction;
use FOSSBilling\Period;
use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;
use Symfony\Component\Intl\Currencies as SymfonyCurrencies;

class Payment_Adapter_GoCardless implements InjectionAwareInterface
{
    private Container $di;

    private string $token;
    private string $webhookSecret;
    private string $baseUri;

    private const URI_LIVE = 'https://api.gocardless.com';
    private const URI_SANDBOX = 'https://api-sandbox.gocardless.com';
    private const API_VERSION = '2015-07-06';

    // Direct Debit is only offered in these currencies.
    private const SUPPORTED_CURRENCIES = ['AUD', 'CAD', 'DKK', 'EUR', 'GBP', 'NZD', 'SEK', 'USD'];

    // Payment statuses (GoCardless): pending_submission, submitted, confirmed,
    // paid_out, failed, cancelled, charged_back.
    private const SETTLED_STATUSES = ['paid_out'];
    private const CONFIRMED_STATUSES = ['confirmed', 'paid_out'];
    private const FAILED_STATUSES = ['failed', 'cancelled', 'charged_back'];

    public function __construct(private $config)
    {
        // test_mode is injected by FOSSBilling for every gateway adapter instance.
        $this->baseUri = !empty($config['test_mode']) ? self::URI_SANDBOX : self::URI_LIVE;

        if (!empty($config['test_mode'])) {
            $token = $config['test_access_token'] ?? '';
            $secret = $config['test_webhook_secret'] ?? '';
            $labels = ['sandbox access token', 'sandbox webhook endpoint secret'];
        } else {
            $token = $config['access_token'] ?? '';
            $secret = $config['webhook_secret'] ?? '';
            $labels = ['access token', 'webhook endpoint secret'];
        }

        $missing = [];
        if (empty($token)) {
            $missing[] = $labels[0];
        }
        if (empty($secret)) {
            $missing[] = $labels[1];
        }

        if (!empty($missing)) {
            throw new Payment_Exception(
                'The following GoCardless configuration options are required: :fields.',
                [':fields' => implode(' and ', $missing)],
                4001
            );
        }

        $this->token = (string) $token;
        $this->webhookSecret = (string) $secret;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => 'GoCardless collects payments by Direct Debit from EU and UK bank accounts. Customers are taken to GoCardless to grant a Direct Debit mandate, then payments are collected by the scheme.',
            'form' => [
                'access_token' => [
                    'password',
                    [
                        'label' => 'Live Access Token',
                        'description' => 'From GoCardless Dashboard → Developers. Starts with "live_".',
                    ],
                ],
                'webhook_secret' => [
                    'password',
                    [
                        'label' => 'Live Webhook Endpoint Secret',
                        'description' => 'The signing secret of the webhook endpoint. Configure the endpoint URL as your site\'s /ipn.php?gateway_id=YOUR_GATEWAY_ID.',
                    ],
                ],
                'test_access_token' => [
                    'password',
                    [
                        'label' => 'Sandbox Access Token',
                        'description' => 'Only used when FOSSBilling is in test (development) mode. Starts with "sandbox_".',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                    ],
                ],
                'test_webhook_secret' => [
                    'password',
                    [
                        'label' => 'Sandbox Webhook Endpoint Secret',
                        'description' => 'Configured on the sandbox webhook endpoint (api-sandbox.gocardless.com). Only used in test mode.',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                    ],
                ],
                'settlement_status' => [
                    'select',
                    [
                        'label' => 'Mark invoices paid when a payment is',
                        'multiOptions' => [
                            'paid_out' => 'Paid out to your bank (money has arrived)',
                            'confirmed' => 'Confirmed / collected (funds secured, payout later)',
                        ],
                    ],
                ],
                'auto_redirect' => [
                    'radio',
                    [
                        'label' => 'Redirect to GoCardless automatically',
                        'multiOptions' => ['Yes' => 1, 'No' => 0],
                        'default' => '0',
                    ],
                ],
            ],
        ];
    }

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    /**
     * Builds the checkout UI: a hosted GoCardless Direct Debit flow the customer
     * is redirected to (or sent to via the button).
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $invoice_id);
        if (!$invoice instanceof Invoice) {
            throw new Payment_Exception('GoCardless: invoice not found');
        }

        $this->assertSupportedCurrency($invoice);

        $sessionToken = 'fossbilling_' . bin2hex(random_bytes(24));

        $successUrl = $this->config['redirect_url']
            . '&gc_sess=' . rawurlencode($sessionToken)
            . '&gc_sub=' . ($subscription ? '1' : '0');

        $flow = $this->createRedirectFlow(
            $sessionToken,
            $successUrl,
            $this->getInvoiceTitle($invoice),
            $this->buildPrefilledCustomer($invoice)
        );

        $hostedUrl = $flow['confirmation_url'] ?? $flow['redirect_url'] ?? null;
        if (empty($hostedUrl)) {
            throw new Payment_Exception('GoCardless did not return a hosted payment URL. Check the access token and try again.');
        }

        $autoSubmit = !empty($this->config['auto_redirect']);

        return '<form name="payment_form" action="' . htmlspecialchars((string) $hostedUrl) . '" method="get">'
            . '<input class="btn btn-primary" type="submit" value="Pay with GoCardless Direct Debit" id="payment_button"/>'
            . '</form>'
            . ($autoSubmit ? '<script type="text/javascript">window.onload=function(){document.forms["payment_form"].submit();};</script>' : '');
    }

    /**
     * Handles (a) the customer returning from the hosted GoCardless page via the
     * invoice callback (ipn.php GET) and (b) GoCardless webhooks delivered to the
     * notification URL (ipn.php POST, application/json).
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $tx = $this->di['em']->getRepository(Transaction::class)->find((int) $id);
        if (!$tx instanceof Transaction) {
            throw new Payment_Exception('GoCardless: transaction not found');
        }

        if ($this->isWebhook($data)) {
            $this->processWebhook($api_admin, $tx, $data, (int) $gateway_id);
        } else {
            $this->processRedirectReturn($api_admin, $tx, $data, (int) $gateway_id);
        }
    }

    /**
     * FOSSBilling asks the gateway to cancel a subscription. GoCardless cancels
     * immediately; there is no "at period end" mode. We deliberately do NOT
     * implement cancelSubscriptionAtPeriodEnd, so FOSSBilling falls back here
     * for scheduled cancels too.
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->request('POST', '/subscriptions/' . rawurlencode($subscriptionId) . '/actions/cancel', [], 'fossbilling_cancel_' . $subscriptionId);
    }

    // ---------------------------------------------------------------------
    // Redirect return handling (customer comes back from GoCardless)
    // ---------------------------------------------------------------------

    private function processRedirectReturn($api_admin, Transaction $tx, array $data, int $gateway_id): void
    {
        $get = $data['get'] ?? [];
        $flowId = $get['redirect_flow_id'] ?? null;
        $sessionToken = $get['gc_sess'] ?? null;

        if (empty($flowId) || !is_string($flowId) || empty($sessionToken)) {
            throw new Payment_Exception('GoCardless returned the customer without a valid redirect flow reference.');
        }

        $invoice = $tx->getInvoice() instanceof Invoice
            ? $tx->getInvoice()
            : $this->di['em']->getRepository(Invoice::class)->find((int) ($get['invoice_id'] ?? 0));
        if (!$invoice instanceof Invoice) {
            throw new Payment_Exception('GoCardless: invoice not found in callback from payment provider.');
        }

        // A webhook may have already settled this invoice while the customer was
        // still navigating back. Do not double charge.
        if (Invoice::STATUS_PAID === $invoice->getStatus() && empty($tx->getError())) {
            $this->markProcessed($tx);
            return;
        }

        $completed = $this->completeRedirectFlow((string) $flowId, (string) $sessionToken);
        $mandateId = $completed['links']['mandate'] ?? $completed['data']['links']['mandate'] ?? null;
        if (empty($mandateId)) {
            throw new Payment_Exception('GoCardless did not provide a Direct Debit mandate for the completed redirect flow.');
        }

        $isSubscription = ('1' === (string) ($get['gc_sub'] ?? '0'));

        if ($isSubscription) {
            $this->createOrUpdateSubscription($api_admin, $tx, $invoice, (string) $mandateId, $gateway_id);
        } else {
            $this->createOneTimePayment($tx, $invoice, (string) $mandateId, $gateway_id);
        }
    }

    private function createOneTimePayment(Transaction $tx, Invoice $invoice, string $mandateId, int $gateway_id): void
    {
        $payment = $this->createPayment([
            'amount' => (string) $this->invoiceAmountInMinorUnits($invoice),
            'currency' => strtoupper((string) $invoice->getCurrency()),
            'description' => $this->getInvoiceTitle($invoice),
            'metadata' => [
                'fossbilling_invoice_id' => (string) $invoice->getId(),
                'fossbilling_gateway_id' => (string) $gateway_id,
            ],
            'links' => [
                'mandate' => $mandateId,
            ],
        ], 'fossbilling_pay_invoice_' . $invoice->getId() . '_' . $gateway_id . '_' . $mandateId);

        $tx->setInvoice($invoice);
        $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_RECEIVED, null, 'Direct Debit scheduled. Estimated collection date: ' . ($payment['charge_date'] ?? 'assigned by GoCardless') . '. The invoice is paid once the collection is settled by the scheme.');
    }

    private function createOrUpdateSubscription($api_admin, Transaction $tx, Invoice $invoice, string $mandateId, int $gateway_id): void
    {
        $period = $this->getSubscriptionPeriodForInvoice($invoice);

        // A local subscription row may already exist with an empty sid (created
        // when the invoice was generated). Re-check for an existing GoCardless
        // subscription so a repeated redirect cannot create a second one.
        $existing = $this->findSubscriptionForInvoice($invoice);
        if ($existing instanceof Subscription && !empty($existing->getSid())) {
            $this->recordTransactionFromSubscription($tx, $invoice, $existing);
            return;
        }

        $gcSub = $this->createSubscription([
            'amount' => (string) $this->invoiceAmountInMinorUnits($invoice),
            'currency' => strtoupper((string) $invoice->getCurrency()),
            'name' => $this->getInvoiceTitle($invoice),
            'interval' => (string) $this->getPeriodQuantity($period),
            'interval_unit' => $this->getGoCardlessIntervalUnit($period),
            'metadata' => [
                'fossbilling_invoice_id' => (string) $invoice->getId(),
                'fossbilling_gateway_id' => (string) $gateway_id,
            ],
            'links' => [
                'mandate' => $mandateId,
            ],
        ], 'fossbilling_sub_invoice_' . $invoice->getId() . '_' . $gateway_id . '_' . $mandateId);

        $this->createLocalSubscriptionRecord($api_admin, $invoice, $gcSub, $gateway_id, $period);

        $local = $this->findSubscriptionForInvoice($invoice);
        if ($local instanceof Subscription) {
            $this->recordTransactionFromSubscription($tx, $invoice, $local);
        }
    }

    private function createLocalSubscriptionRecord($api_admin, Invoice $invoice, array $gcSub, int $gateway_id, string $period): void
    {
        $subId = (string) ($gcSub['id'] ?? '');
        $existing = $this->findSubscriptionForInvoice($invoice);
        if ($existing instanceof Subscription && $subId !== '' && ($existing->getSid() ?? '') === $subId) {
            return; // already recorded
        }

        try {
            $api_admin->invoice_subscription_create([
                'client_id' => $invoice->getClientId(),
                'gateway_id' => $gateway_id,
                'currency' => strtoupper((string) $invoice->getCurrency()),
                'sid' => $subId,
                'status' => 'active',
                'period' => $period,
                'amount' => (string) $this->amountInMajorUnits($this->invoiceAmountInMinorUnits($invoice), strtoupper((string) $invoice->getCurrency())),
                'rel_type' => 'invoice',
                'rel_id' => $invoice->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to record the GoCardless subscription ' . $subId . ' in FOSSBilling: ' . $e->getMessage());
        }
    }

    private function recordTransactionFromSubscription(Transaction $tx, Invoice $invoice, Subscription $subscription): void
    {
        $tx->setInvoice($invoice);
        $tx->setSId((string) $subscription->getSid());
        $tx->setSPeriod($subscription->getPeriod() ?? '1M');
        $tx->setAmount((string) $this->minorUnitsToAmount($this->invoiceAmountInMinorUnits($invoice), strtoupper((string) $invoice->getCurrency())));
        $tx->setCurrency(strtoupper((string) $invoice->getCurrency()));
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);
        $tx->setStatus(Transaction::STATUS_RECEIVED);
        $tx->setNote('Recurring Direct Debit mandate created. The invoice is paid when the first collection is settled by the scheme.');
        $tx->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();
    }

    // ---------------------------------------------------------------------
    // Webhook handling (GoCardless -> ipn.php POST application/json)
    // ---------------------------------------------------------------------

    private function isWebhook(array $data): bool
    {
        $server = $data['server'] ?? [];
        $contentType = (string) ($server['HTTP_CONTENT_TYPE'] ?? '');
        return (str_starts_with(strtolower($contentType), 'application/json') && !empty($data['http_raw_post_data']));
    }

    private function processWebhook($api_admin, Transaction $tx, array $data, int $gateway_id): void
    {
        $rawBody = (string) ($data['http_raw_post_data'] ?? '');
        if (!$this->verifyWebhookSignature($rawBody, $data['server'] ?? [])) {
            throw new FOSSBilling\InformationException('GoCardless webhook failed signature verification.');
        }
        if ('' === $rawBody) {
            throw new FOSSBilling\InformationException('GoCardless webhook received an empty body.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new FOSSBilling\InformationException('GoCardless webhook body is not valid JSON.');
        }

        $events = $payload['events'] ?? [];
        $realEvents = is_array($events) ? array_values(array_filter($events, static fn ($e) => is_array($e))) : [];
        if ([] === $realEvents) {
            // A "ping" event carries no resource. Nothing to record.
            $this->di['em']->remove($tx);
            $this->di['em']->flush();
            return;
        }

        $handled = false;
        foreach ($realEvents as $event) {
            $resourceType = (string) ($event['resource_type'] ?? '');
            $action = (string) ($event['action'] ?? '');
            $key = $resourceType . '.' . $action;

            try {
                switch ($key) {
                    case 'payments.paid_out':
                    case 'payments.confirmed':
                    case 'payments.pending_submission':
                    case 'payments.submitted':
                    case 'payments.customer_approval_granted':
                        $handled = $this->handlePaymentStatus($api_admin, $tx, $event, $gateway_id) || $handled;
                        break;

                    case 'payments.failed':
                    case 'payments.cancelled':
                    case 'payments.charged_back':
                    case 'payments.chargeback_settled':
                        $this->handleFailedPayment($tx, $event, $gateway_id);
                        $handled = true;
                        break;

                    case 'subscriptions.cancelled':
                    case 'subscriptions.finished':
                        $this->handleSubscriptionEnded($event);
                        break;

                    case 'subscriptions.payment_created':
                        // Each collection produces its own payments.* lifecycle
                        // events; nothing further to do here.
                        break;

                    default:
                        $this->logDebug('GoCardless webhook event ignored: ' . $key);
                        break;
                }
            } catch (\Throwable $e) {
                $this->logError('GoCardless webhook handling failed for ' . $key . ': ' . $e->getMessage());
            }
        }

        if (!$handled && '' === (string) $tx->getTxnId()) {
            // Only informational events (created, submitted, ...) arrived. Drop
            // the placeholder transaction; the invoice is handled on settlement.
            $this->di['em']->remove($tx);
            $this->di['em']->flush();
        }
    }

    private function handlePaymentStatus($api_admin, Transaction $tx, array $event, int $gateway_id): bool
    {
        $paymentId = (string) ($event['links']['payment'] ?? $event['resource_id'] ?? '');
        if ('' === $paymentId) {
            return false;
        }

        // Fetch the authoritative payment state rather than trusting the action.
        $payment = $this->getPayment($paymentId);
        $payment['id'] = $paymentId;
        $status = (string) ($payment['status'] ?? 'unknown');

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $this->handleFailedPaymentState($tx, $payment, $status);
            return true;
        }

        $settleOn = (string) ($this->config['settlement_status'] ?? 'paid_out');
        $settledStatuses = ('confirmed' === $settleOn) ? self::CONFIRMED_STATUSES : self::SETTLED_STATUSES;

        if (!in_array($status, $settledStatuses, true)) {
            // Not yet collected: progress note only, no credit yet.
            $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_RECEIVED, null, 'Payment status at GoCardless: ' . $status . '.');
            return true;
        }

        return $this->creditSettledPayment($api_admin, $tx, $payment, $gateway_id);
    }

    private function creditSettledPayment($api_admin, Transaction $tx, array $payment, int $gateway_id): bool
    {
        $paymentId = (string) ($payment['id'] ?? '');

        // Dedupe against an already-processed transaction for the same payment.
        $existing = $this->di['em']->getRepository(Transaction::class)
            ->findOneByTxnIdAndGatewayId($paymentId, $gateway_id, $tx->getId());
        if ($existing instanceof Transaction && Transaction::STATUS_PROCESSED === $existing->getStatus()) {
            $this->di['em']->remove($tx);
            $this->di['em']->flush();
            return false;
        }

        $links = $payment['links'] ?? [];
        $subscriptionSid = (string) ($links['subscription'] ?? '');
        $subscription = ('' !== $subscriptionSid)
            ? $this->di['em']->getRepository(Subscription::class)->findOneBy(['sid' => $subscriptionSid])
            : null;

        // Which invoice should this collection pay?
        //  - Subscription payment: the original invoice (rel_id) while it is
        //    still unpaid (first collection), else a generated renewal invoice.
        //  - One-time payment: the invoice recorded in metadata.
        $invoice = null;
        if ($subscription instanceof Subscription) {
            $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $subscription->getRelId());
            if ($invoice instanceof Invoice && Invoice::STATUS_PAID === $invoice->getStatus()) {
                $invoice = null;
            }
            if ($tx->getInvoice() instanceof Invoice && !($invoice instanceof Invoice)) {
                $invoice = $tx->getInvoice();
            }
        }

        if (!$invoice instanceof Invoice) {
            $metadata = $payment['metadata'] ?? [];
            $metadataInvoiceId = (int) ($metadata['fossbilling_invoice_id'] ?? 0);
            if ($metadataInvoiceId > 0) {
                $invoice = $this->di['em']->getRepository(Invoice::class)->find($metadataInvoiceId);
            }
        }

        if (!$invoice instanceof Invoice) {
            $invoice = $tx->getInvoice();
        }

        if ($invoice instanceof Invoice && Invoice::STATUS_PAID === $invoice->getStatus()) {
            $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_PROCESSED, $invoice, 'Invoice already paid by a previous GoCardless collection.');
            return true;
        }

        // Renewal for an active subscription whose original invoice is already
        // paid or cannot be found.
        if ('' !== $subscriptionSid && !($invoice instanceof Invoice)) {
            if ($this->handleRenewalPayment($api_admin, $tx, $payment, $subscriptionSid, $gateway_id)) {
                return true;
            }
        }

        if (!$invoice instanceof Invoice) {
            $this->logError('GoCardless payment ' . $paymentId . ' cannot be matched to an invoice. No credit applied.');
            return false;
        }

        $this->applyPaymentToInvoice($tx, $invoice, $payment, $gateway_id);
        return true;
    }

    private function handleRenewalPayment($api_admin, Transaction $tx, array $payment, string $subscriptionSid, int $gateway_id): bool
    {
        $subscription = $this->di['em']->getRepository(Subscription::class)->findOneBy(['sid' => $subscriptionSid]);
        if (!($subscription instanceof Subscription)) {
            return false;
        }

        $invoiceService = $this->di['mod_service']('Invoice');

        $clientId = (int) ($subscription->getClientId() > 0 ? $subscription->getClientId() : 0);
        if ($clientId <= 0) {
            $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $subscription->getRelId());
            if ($invoice instanceof Invoice) {
                $clientId = (int) $invoice->getClientId();
            }
        }
        if ($clientId <= 0) {
            throw new FOSSBilling\InformationException('GoCardless: could not determine the client for subscription payment.');
        }

        $renewal = $invoiceService->generateRenewalInvoiceForSubscriptionPayment($subscriptionSid, $clientId);
        if ($renewal instanceof Invoice) {
            $this->applyPaymentToInvoice($tx, $renewal, $payment, $gateway_id);
            return true;
        }

        // No renewal invoice could be generated: credit the client balance and
        // let FOSSBilling apply it to whatever is due.
        $this->creditBalanceOnly($api_admin, $tx, $payment, $clientId);
        return true;
    }

    private function applyPaymentToInvoice(Transaction $tx, Invoice $invoice, array $payment, int $gateway_id): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');

        if (!$transactionService->claimForProcessing((int) $tx->getId())) {
            $this->logDebug('GoCardless payment ' . ($payment['id'] ?? '') . ' is already being processed by another request.');
            return;
        }

        $amount = $this->minorUnitsToAmount((int) ($payment['amount'] ?? 0), (string) ($payment['currency'] ?? 'GBP'));

        $tx->setInvoice($invoice);
        $tx->setStatus(Transaction::STATUS_PROCESSING);

        $expected = $invoiceService->getTotalWithTax($invoice);
        try {
            $invoiceService->validatePaymentAmount($amount, $expected);
        } catch (FOSSBilling\Exception $e) {
            $this->markError($tx, $amount, (string) ($payment['currency'] ?? 'GBP'), $e->getMessage());
            throw $e;
        }

        $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_PROCESSING, $invoice, null);

        $clientService = $this->di['mod_service']('client');
        $client = $this->di['em']->getRepository(BoxClient::class)->find($invoice->getClientId());
        if (!$client instanceof BoxClient) {
            throw new FOSSBilling\InformationException('GoCardless: the client for invoice #' . $invoice->getId() . ' could not be found.');
        }

        $bd = [
            'amount' => $amount,
            'description' => 'GoCardless Direct Debit payment ' . ($payment['id'] ?? '') . ' — invoice #' . $invoice->getId(),
            'type' => 'transaction',
            'rel_id' => (int) $tx->getId(),
        ];
        $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->markAsPaid($invoice);
        } else {
            if (!$invoice->isApproved()) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        }

        $this->markProcessed($tx);
    }

    private function creditBalanceOnly($api_admin, Transaction $tx, array $payment, int $clientId): void
    {
        $clientService = $this->di['mod_service']('client');
        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');

        if (!$transactionService->claimForProcessing((int) $tx->getId())) {
            return;
        }

        $client = $this->di['em']->getRepository(BoxClient::class)->find($clientId);
        if (!$client instanceof BoxClient) {
            throw new FOSSBilling\InformationException('GoCardless: the client for subscription payment could not be found.');
        }

        $amount = $this->minorUnitsToAmount((int) ($payment['amount'] ?? 0), (string) ($payment['currency'] ?? 'GBP'));
        $description = 'GoCardless Direct Debit collection ' . ($payment['id'] ?? '');
        $clientService->addFunds($client, $amount, $description, [
            'amount' => $amount,
            'description' => $description,
            'type' => 'transaction',
            'rel_id' => (int) $tx->getId(),
        ]);

        $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_PROCESSED, null, 'Credited to client balance (no matching invoice to pay).');
    }

    private function handleFailedPayment(Transaction $tx, array $event, int $gateway_id): void
    {
        $paymentId = (string) ($event['links']['payment'] ?? $event['resource_id'] ?? '');
        $status = (string) ($event['action'] ?? 'failed');
        if ('' === $paymentId) {
            return;
        }
        $this->handleFailedPaymentState($tx, ['id' => $paymentId, 'status' => $status], $status);
    }

    private function handleFailedPaymentState(Transaction $tx, array $payment, string $status): void
    {
        $this->recordTransactionPayment($tx, $payment, Transaction::STATUS_ERROR, null, 'The GoCardless payment ended with status "' . $status . '". The collection was not successful.');
    }

    private function handleSubscriptionEnded(array $event): void
    {
        $subscriptionSid = (string) ($event['links']['subscription'] ?? $event['resource_id'] ?? '');
        if ('' === $subscriptionSid) {
            return;
        }
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        $localId = $subscriptionService->findIdBySid($subscriptionSid);
        if (null !== $localId && $localId > 0) {
            $subscriptionService->finalizeCancellationFromGateway((int) $localId);
            $this->logDebug('GoCardless subscription ' . $subscriptionSid . ' ended; local subscription #' . $localId . ' finalized.');
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function verifyWebhookSignature(string $rawBody, array $server): bool
    {
        if ('' === $this->webhookSecret || '' === $rawBody) {
            return false;
        }
        $signature = (string) ($server['HTTP_WEBHOOK_SIGNATURE'] ?? '');
        if ('' === $signature) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    private function assertSupportedCurrency(Invoice $invoice): void
    {
        $currency = strtoupper((string) $invoice->getCurrency());
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new Payment_Exception(
                'GoCardless does not support the currency ":currency". Supported currencies are :currencies.',
                [':currency' => $currency, ':currencies' => implode(', ', self::SUPPORTED_CURRENCIES)]
            );
        }
    }

    private function getInvoiceTitle(Invoice $invoice): string
    {
        $buyerName = trim(trim((string) $invoice->getBuyerFirstName() . ' ' . (string) $invoice->getBuyerLastName()));
        $prefix = (string) ($invoice->getSerie() ?? '');
        $number = (string) ($invoice->getNr() ?? $invoice->getId());

        return trim(($buyerName !== '' ? $buyerName . ' — ' : '') . ($prefix . $number));
    }

    private function buildPrefilledCustomer(Invoice $invoice): array
    {
        $givenName = trim((string) $invoice->getBuyerFirstName());
        $familyName = trim((string) $invoice->getBuyerLastName());

        $prefilled = [];
        if ('' !== trim((string) $invoice->getBuyerEmail())) {
            $prefilled['email'] = trim((string) $invoice->getBuyerEmail());
        }
        if ('' !== $givenName) {
            $prefilled['given_name'] = $givenName;
        }
        if ('' !== $familyName) {
            $prefilled['family_name'] = $familyName;
        }
        // GoCardless requires given_name and family_name to differ.
        if (isset($prefilled['given_name'], $prefilled['family_name']) && strtolower($prefilled['given_name']) === strtolower($prefilled['family_name'])) {
            unset($prefilled['given_name']);
        }

        return $prefilled;
    }

    private function findSubscriptionForInvoice(Invoice $invoice): ?Subscription
    {
        $rows = $this->di['em']->getRepository(Subscription::class)->findBy(['relType' => 'invoice', 'relId' => $invoice->getId()]);

        // Prefer the record that carries a GoCardless sid (a subscription row
        // with an empty sid is created when the invoice is first generated).
        $fallback = null;
        foreach ($rows as $row) {
            if (!$row instanceof Subscription) {
                continue;
            }
            if ('' !== (string) $row->getSid()) {
                return $row;
            }
            $fallback ??= $row;
        }

        return $fallback;
    }

    private function getSubscriptionPeriodForInvoice(Invoice $invoice): string
    {
        $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
        $period = $subscriptionService->getSubscriptionPeriod($invoice);

        return $period ?? '1M';
    }

    private function getPeriodQuantity(string $period): int
    {
        try {
            $p = new Period($period);
            return max(1, $p->getQty());
        } catch (\Throwable) {
            return 1;
        }
    }

    private function getGoCardlessIntervalUnit(string $period): string
    {
        try {
            $unit = (new Period($period))->getUnit();
        } catch (\Throwable) {
            $unit = Period::UNIT_MONTH;
        }

        return match ($unit) {
            Period::UNIT_WEEK => 'weekly',
            Period::UNIT_MONTH => 'monthly',
            Period::UNIT_YEAR => 'yearly',
            default => throw new Payment_Exception('GoCardless Direct Debit does not support the billing period ":period". Supported periods are weekly, monthly and yearly.', [':period' => $period]),
        };
    }

    private function invoiceAmountInMinorUnits(Invoice $invoice): int
    {
        $totalWithTax = (float) $this->di['mod_service']('Invoice')->getTotalWithTax($invoice);

        return $this->amountToMinorUnits($totalWithTax, strtoupper((string) $invoice->getCurrency()));
    }

    private function amountToMinorUnits(float $amount, string $currency): int
    {
        $digits = $this->fractionDigitsFor($currency);
        return (int) round($amount * (10 ** $digits), 0);
    }

    private function amountInMajorUnits(int $minorUnits, string $currency): float
    {
        return round($minorUnits / (10 ** $this->fractionDigitsFor($currency)), $this->fractionDigitsFor($currency));
    }

    private function minorUnitsToAmount(int $minorUnits, string $currency): float
    {
        return round($minorUnits / (10 ** $this->fractionDigitsFor($currency)), $this->fractionDigitsFor($currency));
    }

    private function fractionDigitsFor(string $currency): int
    {
        static $cache = [];
        $currency = strtoupper($currency);
        if (array_key_exists($currency, $cache)) {
            return $cache[$currency];
        }

        $digits = 2;
        if (class_exists(SymfonyCurrencies::class)) {
            $candidate = SymfonyCurrencies::getFractionDigits($currency);
            if (null !== $candidate && $candidate >= 0) {
                $digits = (int) $candidate;
            }
        }
        $cache[$currency] = $digits;

        return $digits;
    }

    /**
     * Populates a transaction with the payment details and status. Used by both
     * the redirect-return path and the webhook path.
     */
    private function recordTransactionPayment(Transaction $tx, array $payment, string $txStatus, ?Invoice $invoice, ?string $note): void
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ('' !== $paymentId) {
            $tx->setTxnId($paymentId);
        }
        $amount = $this->minorUnitsToAmount((int) ($payment['amount'] ?? 0), (string) ($payment['currency'] ?? 'GBP'));
        $currency = strtoupper((string) ($payment['currency'] ?? 'GBP'));
        if ($amount > 0) {
            $tx->setAmount((string) $amount);
            $tx->setCurrency($currency);
        }
        $tx->setTxnStatus((string) ($payment['status'] ?? $txStatus));
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);
        $tx->setStatus($txStatus);
        if ($invoice instanceof Invoice) {
            $tx->setInvoice($invoice);
        }
        if (null !== $note) {
            $tx->setNote($note);
        }
        if (Transaction::STATUS_ERROR === $txStatus) {
            $tx->setError($note);
            $tx->setErrorCode(null);
        }
        $tx->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();
    }

    private function markProcessed(Transaction $tx): void
    {
        $tx->setStatus(Transaction::STATUS_PROCESSED);
        $tx->setError(null);
        $tx->setErrorCode(null);
        $tx->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();
    }

    private function markError(Transaction $tx, float $amount, string $currency, string $message): void
    {
        $tx->setAmount((string) $amount);
        $tx->setCurrency(strtoupper($currency));
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);
        $tx->setStatus(Transaction::STATUS_ERROR);
        $tx->setError($message);
        $tx->setErrorCode('amount_mismatch');
        $tx->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();
    }

    // ---------------------------------------------------------------------
    // GoCardless API client (minimal cURL transport, no SDK dependency)
    // ---------------------------------------------------------------------

    private function request(string $method, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if (!function_exists('curl_init')) {
            throw new Payment_Exception('GoCardless: the cURL PHP extension is not available on this server.');
        }

        $ch = curl_init($this->baseUri . $path);
        if (false === $ch) {
            throw new Payment_Exception('GoCardless: could not initialise the API request.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
            'GoCardless-Version: ' . self::API_VERSION,
            'User-Agent: FOSSBilling-GoCardless/1.0',
        ];
        if (null !== $idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ('GET' !== strtoupper($method) && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $responseBody || ('' === $responseBody && '' !== $curlError)) {
            throw new Payment_Exception('GoCardless API connection error: :error', [':error' => '' !== $curlError ? $curlError : 'no response received']);
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            throw new Payment_Exception('GoCardless API returned an unreadable response (HTTP :code).', [':code' => $httpCode]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiError = $decoded['error'] ?? [];
            $message = (string) ($apiError['message'] ?? 'Unknown GoCardless API error');
            $apiCode = (string) ($apiError['code'] ?? (string) $httpCode);

            $fieldProblems = [];
            foreach (($apiError['errors'] ?? []) as $fieldError) {
                if (is_array($fieldError)) {
                    $fieldProblems[] = trim(implode(' ', array_filter([
                        (string) ($fieldError['field'] ?? ''),
                        (string) ($fieldError['message'] ?? ''),
                    ])));
                }
            }
            if (!empty($fieldProblems)) {
                $message .= ' — ' . implode('; ', array_unique($fieldProblems));
            }

            throw new Payment_Exception('GoCardless API error (:code): :message', [':code' => $apiCode, ':message' => $message]);
        }

        return $decoded;
    }

    private function createRedirectFlow(string $sessionToken, string $successUrl, string $description, array $prefilled): array
    {
        $flow = $this->request('POST', '/redirect_flows', [
            'redirect_flows' => [
                'description' => $description,
                'session_token' => $sessionToken,
                'success_redirect_url' => $successUrl,
                'prefilled_customer' => $prefilled,
            ],
        ]);

        return $flow['redirect_flows'] ?? $flow;
    }

    private function completeRedirectFlow(string $flowId, string $sessionToken): array
    {
        $response = $this->request('POST', '/redirect_flows/' . rawurlencode($flowId) . '/actions/complete', [
            'data' => ['session_token' => $sessionToken],
        ], 'fossbilling_complete_flow_' . $flowId);

        return $response['redirect_flows'] ?? $response;
    }

    private function createPayment(array $payment, ?string $idempotencyKey): array
    {
        $response = $this->request('POST', '/payments', ['payments' => $payment], $idempotencyKey);
        return $response['payments'] ?? $response;
    }

    private function createSubscription(array $subscription, ?string $idempotencyKey): array
    {
        $response = $this->request('POST', '/subscriptions', ['subscriptions' => $subscription], $idempotencyKey);
        return $response['subscriptions'] ?? $response;
    }

    private function getPayment(string $paymentId): array
    {
        $response = $this->request('GET', '/payments/' . rawurlencode($paymentId));
        return $response['payments'] ?? $response;
    }

    private function logDebug(string $message): void
    {
        if (isset($this->di['logger'])) {
            $this->di['logger']->debug($message, ['gateway' => 'GoCardless']);
        }
    }

    private function logError(string $message): void
    {
        if (isset($this->di['logger'])) {
            $this->di['logger']->error($message, ['gateway' => 'GoCardless']);
        }
    }
}