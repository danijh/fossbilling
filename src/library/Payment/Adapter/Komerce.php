<?php

declare(strict_types=1);
/**
 * Komerce (RajaOngkir Payment API) gateway for FOSSBilling.
 * SPDX-License-Identifier: Apache-2.0.
 */

use Box\Mod\Client\Entity\Client;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\Entity\Transaction;

class Payment_Adapter_Komerce implements FOSSBilling\InjectionAwareInterface, FOSSBilling\Payment\JsonWebhookAuthenticatorInterface
{
    private const SANDBOX_URL = 'https://api-sandbox.collaborator.komerce.id/user';
    private const LIVE_URL = 'https://api.collaborator.komerce.id/user';
    private const SANDBOX_PAY_URL = 'https://pay-sandbox.komerce.id/';
    private const LIVE_PAY_URL = 'https://pay.komerce.id/';
    private const MIN_AMOUNT = 10000;

    protected ?Pimple\Container $di = null;

    public function __construct(private $config)
    {
        if ($this->apiKey() === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Komerce', ':missing' => 'API key'], 4001);
        }
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function requiresManualApproval(): bool
    {
        return false;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Pembayaran QRIS dan Virtual Account melalui Komerce (RajaOngkir Payment API). Hanya mata uang IDR.',
            'logo' => [
                'logo' => 'komerce.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'api_key' => ['password', [
                    'label' => 'API key (production)',
                    'required' => false,
                ]],
                'test_api_key' => ['password', [
                    'label' => 'API key (sandbox)',
                    'required' => false,
                ]],
                'default_phone' => ['text', [
                    'label' => 'Nomor telepon cadangan bila klien belum mengisi telepon (contoh 081234567890)',
                    'required' => false,
                ]],
                'expiry_duration' => ['text', [
                    'label' => 'Masa berlaku pembayaran VA dalam detik (minimal 3600)',
                    'required' => false,
                ]],
            ],
        ];
    }

    private function testMode(): bool
    {
        return !empty($this->config['test_mode']);
    }

    private function apiKey(): string
    {
        $key = $this->testMode() ? ($this->config['test_api_key'] ?? '') : ($this->config['api_key'] ?? '');

        return is_string($key) ? trim($key) : '';
    }

    /**
     * Secret sent as callback_api_key when creating a payment; derived so that no extra setting is needed.
     */
    private function callbackKey(): string
    {
        return hash_hmac('sha256', 'komerce-callback', $this->apiKey());
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $base = $this->testMode() ? self::SANDBOX_URL : self::LIVE_URL;
        $ch = curl_init($base . $path);
        $headers = ['x-api-key: ' . $this->apiKey(), 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($json) || $code < 200 || $code >= 300) {
            $this->di['logger']->error('Komerce API error on ' . $path . ' HTTP ' . $code . ': ' . substr((string) $body, 0, 500));

            throw new Payment_Exception('Komerce API request failed', [], 7030);
        }

        return $json;
    }

    private function getKomerceGateway(): PayGateway
    {
        $gateway = $this->di['em']->getRepository(PayGateway::class)->findOneBy(['gateway' => 'Komerce']);
        if (!$gateway instanceof PayGateway) {
            throw new Payment_Exception('Komerce gateway is not installed', [], 7031);
        }

        return $gateway;
    }

    public function getHtml(FOSSBilling\Api\Proxy $api_admin, int $invoice_id, bool $subscription): string
    {
        $invoice = $this->di['em']->getRepository(Invoice::class)->find($invoice_id);
        if (!$invoice instanceof Invoice) {
            throw new FOSSBilling\Exception('Invoice not found');
        }
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return '<p>Invoice ini sudah dibayar.</p>';
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (int) round((float) $invoiceService->getTotalWithTax($invoice));
        if ($total < self::MIN_AMOUNT) {
            return '<p>Nominal minimal pembayaran Komerce adalah Rp ' . number_format(self::MIN_AMOUNT, 0, ',', '.') . '.</p>';
        }

        $gateway = $this->getKomerceGateway();
        $clientRepo = $this->di['em']->getRepository(Client::class);
        $client = $clientRepo->find($invoice->getClientId());

        $name = trim($invoice->getBuyerFirstName() . ' ' . $invoice->getBuyerLastName());
        $email = $invoice->getBuyerEmail() ?: ($client?->getEmail() ?? '');
        $phone = $invoice->getBuyerPhone() ?: ($client?->getPhone() ?? '') ?: ($this->config['default_phone'] ?? '');
        $phone = preg_replace('/[^0-9+]/', '', (string) $phone);

        $methods = $this->request('GET', '/api/v1/user/methods');
        $channels = [];
        foreach ((array) ($methods['data'] ?? []) as $m) {
            if (is_array($m) && isset($m['channel_code'], $m['payment_type'])) {
                $channels[] = $m;
            }
        }

        $selected = $_GET['komerce_method'] ?? '';
        if ($selected === '') {
            return $this->renderMethodPicker($invoice, $channels);
        }

        $payload = [
            'order_id' => 'INV' . $invoice->getId() . '-' . time(),
            'payment_type' => $selected === 'qris' ? 'qris' : 'bank_transfer',
            'amount' => $total,
            'customer' => [
                'name' => $name !== '' ? $name : 'Pelanggan',
                'email' => $email,
                'phone' => $phone,
            ],
            'items' => [[
                'name' => 'Invoice #' . $invoice->getId(),
                'quantity' => 1,
                'price' => $total,
            ]],
            'callback_url' => SYSTEM_URL . 'ipn.php?' . http_build_query(['gateway_id' => $gateway->getId(), 'invoice_id' => $invoice->getId()]),
            'callback_api_key' => $this->callbackKey(),
        ];
        if ($selected !== 'qris') {
            $payload['channel_code'] = $selected;
            $payload['expiry_duration'] = max(3600, (int) ($this->config['expiry_duration'] ?? 86400));
        }

        $res = $this->request('POST', '/api/v1/user/payment/create', $payload);
        $data = $res['data'] ?? [];
        $paymentUrl = $data['payment_url'] ?? '';
        if (!is_string($paymentUrl) || !preg_match('#^https://#', $paymentUrl)) {
            $token = $data['payment_id'] ?? '';
            $paymentUrl = ($this->testMode() ? self::SANDBOX_PAY_URL : self::LIVE_PAY_URL) . rawurlencode((string) $token);
        }
        $safe = htmlspecialchars($paymentUrl, ENT_QUOTES);

        return '<p>Mengalihkan ke halaman pembayaran...</p><p><a href="' . $safe . '">Klik di sini bila tidak dialihkan</a></p>'
            . '<script>window.location.href=' . json_encode($paymentUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    }

    private function renderMethodPicker(Invoice $invoice, array $channels): string
    {
        $html = '<form method="get"><p>Pilih metode pembayaran:</p>';
        foreach ($channels as $m) {
            $code = $m['payment_type'] === 'qris' ? 'qris' : (string) $m['channel_code'];
            $label = (string) ($m['name'] ?? $m['channel_name'] ?? strtoupper($code));
            $html .= '<p><button type="submit" name="komerce_method" value="' . htmlspecialchars($code, ENT_QUOTES) . '">'
                . htmlspecialchars($label, ENT_QUOTES) . '</button></p>';
        }

        return $html . '</form>';
    }

    /**
     * The signature is HMAC-SHA256 of the exact raw body, delivered in the X-Callback-Api-Key header.
     */
    public function authenticateJsonWebhook(array $data): void
    {
        $raw = $data['http_raw_post_data'] ?? '';
        $sig = $data['server']['HTTP_X_CALLBACK_API_KEY'] ?? '';
        if (!is_string($raw) || $raw === '' || !is_string($sig) || $sig === '') {
            throw new FOSSBilling\InformationException('Missing Komerce signature');
        }
        $valid = false;
        foreach ([$this->apiKey(), $this->callbackKey()] as $secret) {
            if (hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($sig))) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new FOSSBilling\InformationException('Invalid Komerce signature');
        }
    }

    public function processTransaction(FOSSBilling\Api\Proxy $api_admin, int $id, array $data, int $gateway_id): void
    {
        $tx = $this->di['em']->getRepository(Transaction::class)->find($id);
        if (!$tx instanceof Transaction) {
            throw new FOSSBilling\Exception('Transaction not found');
        }

        $payload = json_decode((string) ($data['http_raw_post_data'] ?? ''), true);
        if (!is_array($payload)) {
            throw new Payment_Exception('Komerce callback data is missing.', [], 7032);
        }
        $paymentId = (string) ($payload['payment_id'] ?? $payload['data']['payment_id'] ?? '');
        if ($paymentId === '') {
            throw new Payment_Exception('Komerce payment_id is missing.', [], 7033);
        }

        $invoice = $tx->getInvoice();
        if (!$invoice instanceof Invoice && !empty($data['get']['invoice_id'])) {
            $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $data['get']['invoice_id']);
            if ($invoice instanceof Invoice) {
                $tx->setInvoice($invoice);
            }
        }
        if (!$invoice instanceof Invoice) {
            throw new FOSSBilling\InformationException('Invoice not found');
        }

        // Never trust the callback body for settlement; confirm with Komerce directly.
        $res = $this->request('GET', '/api/v1/user/payment/status/' . rawurlencode($paymentId));
        $info = $res['data'] ?? [];
        $status = strtoupper((string) ($info['status'] ?? ''));
        $amount = (float) ($info['amount'] ?? 0);

        $tx->setTxnId($paymentId);
        $tx->setTxnStatus($status);
        $tx->setCurrency('IDR');
        $tx->setAmount((string) $amount);
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);

        if ($status !== 'PAID') {
            $tx->setStatus($status === 'PENDING' ? Transaction::STATUS_RECEIVED : Transaction::STATUS_PROCESSED);
            $tx->setUpdatedAt(new DateTime());
            $this->di['em']->flush();

            return;
        }

        $fresh = $this->di['em']->getRepository(Invoice::class)->find($invoice->getId());
        if ($fresh instanceof Invoice && $fresh->getStatus() === Invoice::STATUS_PAID) {
            $tx->setStatus(Transaction::STATUS_PROCESSED);
            $tx->setUpdatedAt(new DateTime());
            $this->di['em']->flush();

            return;
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        try {
            $invoiceService->validatePaymentAmount($amount, $invoiceService->getTotalWithTax($invoice));
        } catch (FOSSBilling\Exception $e) {
            $tx->setStatus(Transaction::STATUS_ERROR);
            $tx->setError($e->getMessage());
            $tx->setUpdatedAt(new DateTime());
            $this->di['em']->flush();

            throw $e;
        }

        $client = $this->di['em']->getRepository(Client::class)->find($invoice->getClientId())
            ?? throw new FOSSBilling\InformationException('Client not found');
        $this->di['mod_service']('Client')->addFunds($client, $amount, 'Komerce payment ' . $paymentId, [
            'amount' => $amount,
            'description' => 'Komerce payment ' . $paymentId,
            'type' => 'transaction',
            'rel_id' => $tx->getId(),
        ]);

        if (!$invoiceService->isInvoiceTypeDeposit($invoice)) {
            if (!$invoice->isIssued()) {
                $invoiceService->issueInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        } else {
            $invoiceService->markAsPaid($invoice);
        }

        $tx->setStatus(Transaction::STATUS_PROCESSED);
        $tx->setUpdatedAt(new DateTime());
        $this->di['em']->flush();
    }
}
