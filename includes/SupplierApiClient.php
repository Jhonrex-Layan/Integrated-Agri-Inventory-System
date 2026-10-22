<?php
declare(strict_types=1);

/**
 * Server-side client for the Supplier Management REST API.
 * The API key is read from a PHP file outside htdocs when present (C:/xampp/agri_secrets/inventory_api.php),
 * otherwise from config/api_client.php. It is never sent to the browser.
 *
 * Stage 2 provides request() + ping() (used by the connection indicator). Delivery retrieval and
 * acknowledgments are added in Stage 6 on top of request().
 */
final class SupplierApiClient
{
    private const SECRET_PATHS = ['C:/xampp/agri_secrets/inventory_api.php'];

    private function __construct(private array $cfg) {}

    /** Returns null when no usable configuration exists. */
    public static function load(): ?self
    {
        $candidates = array_merge(self::SECRET_PATHS, [APP_ROOT . '/config/api_client.php']);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $cfg = require $file;
                if (is_array($cfg) && !empty($cfg['base_url']) && !empty($cfg['api_key']) && $cfg['api_key'] !== 'PASTE_KEY_HERE') {
                    return new self($cfg);
                }
            }
        }
        return null;
    }

    /**
     * @return array{ok: bool, status: ?int, data: ?array, error: ?string}
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $logSuccess = true): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => null, 'data' => null, 'error' => 'The PHP cURL extension is not enabled (enable extension=curl in php.ini).'];
        }
        $url = rtrim($this->cfg['base_url'], '/') . '/' . ltrim($path, '/');
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->cfg['api_key']];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout_seconds'] ?? 8),
            CURLOPT_CONNECTTIMEOUT => (int)($this->cfg['connect_timeout'] ?? 3),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $cerr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            $res = ['ok' => false, 'status' => null, 'data' => null, 'error' => 'Cannot reach the Supplier system: ' . $cerr];
        } else {
            $data = json_decode((string)$raw, true);
            $ok = $status >= 200 && $status < 300 && is_array($data);
            $err = null;
            if (!$ok) {
                $err = is_array($data) && isset($data['error']['message'])
                    ? (string)$data['error']['message']
                    : 'Unexpected response (HTTP ' . $status . ').';
            }
            $res = ['ok' => $ok, 'status' => $status, 'data' => is_array($data) ? $data : null, 'error' => $err];
        }

        // keep the dashboard indicator truthful: only real outcomes change it (a 4xx leaves it as is)
        try {
            if ($res['ok']) {
                self::record_status('connected', true);
            } elseif ($res['status'] === null || $res['status'] >= 500) {
                self::record_status('disconnected', false);
            }
        } catch (Throwable $e) {
            app_log('status update failed: ' . $e->getMessage());
        }

        if (!$res['ok'] || $logSuccess) {
            $this->log($method, $path, $res, $idempotencyKey);
        }
        return $res;
    }

    // ---- typed helpers used by receiving, damaged goods and supplier lookups ----

    public function incomingDeliveries(int $perPage = 100): array
    {
        return $this->request('GET', 'deliveries?status=confirmed&per_page=' . $perPage, null, null, false);
    }

    public function delivery(int $id): array
    {
        return $this->request('GET', 'deliveries/' . $id, null, null, false);
    }

    /** The receipt reference doubles as the Idempotency-Key, so a retry can never double-apply. */
    public function acknowledge(int $deliveryId, string $receiptRef, array $items): array
    {
        return $this->request('POST', 'deliveries/' . $deliveryId . '/acknowledge', ['receipt_ref' => $receiptRef, 'items' => $items], $receiptRef);
    }

    public function submitRefund(array $payload): array
    {
        return $this->request('POST', 'refund-requests', $payload, (string)$payload['request_ref']);
    }

    public function refundStatuses(array $refs): array
    {
        return $this->request('GET', 'refund-requests?refs=' . rawurlencode(implode(',', $refs)), null, null, false);
    }

    /** True when a failed result is worth retrying later (network error, 5xx, rate limit) rather than a permanent rejection. */
    public static function isTransient(array $res): bool
    {
        return $res['status'] === null || $res['status'] >= 500 || $res['status'] === 429;
    }

    /** Health check; updates the stored connection status and last-sync time. */
    public function ping(): array
    {
        $res = $this->request('GET', 'ping', null, null, false);
        self::record_status($res['ok'] ? 'connected' : 'disconnected', $res['ok']);
        return $res;
    }

    public static function record_status(string $status, bool $success): void
    {
        setting_set('api_connection_status', $status);
        if ($success) {
            setting_set('last_successful_sync', date('Y-m-d H:i:s'));
        }
    }

    private function log(string $method, string $path, array $res, ?string $idemKey): void
    {
        try {
            db()->prepare('INSERT INTO api_sync_logs (direction, method, endpoint, http_status, status, idempotency_key, error_message, created_by)
                           VALUES (?,?,?,?,?,?,?,?)')
                ->execute([
                    strtoupper($method) === 'GET' ? 'pull' : 'push', strtoupper($method), substr($path, 0, 200), $res['status'],
                    $res['ok'] ? 'success' : 'failed', $idemKey, $res['error'] ? substr($res['error'], 0, 255) : null,
                    current_user()['id'] ?? null,
                ]);
        } catch (Throwable $e) {
            app_log('api log failed: ' . $e->getMessage());
        }
    }
}
