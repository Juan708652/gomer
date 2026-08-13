<?php
// ============================================================================
// 1. CLASS GOMERCHANT (HELPER & SDK GOBIZ)
// ============================================================================
class GoMerchant {
    private $baseUrl = 'https://api.gobiz.co.id';
    private $clientId = 'go-biz-web-new';
    private $appId = 'go-biz-web-dashboard';
    private $uniqueId;

    public function __construct() {
        $this->uniqueId = $this->generateUuid();
    }

    private function generateUuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function getHeaders($token = null) {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Authentication-Type: go-id',
            'X-PhoneMake: Android 10',
            'X-PhoneModel: K',
            'x-DeviceOS: Web',
            'X-Platform: Web',
            'X-User-Type: merchant',
            'x-appId: ' . $this->appId,
            'x-uniqueid: ' . $this->uniqueId,
            'X-AppVersion: platform-v3.101.0-8918927d',
            'Gojek-Country-Code: ID',
            'Gojek-Timezone: Asia/Jakarta',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Mobile Safari/537.36'
        ];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        return $headers;
    }

    private function httpRequest($url, $method = 'GET', $payload = null, $customHeaders = []) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $customHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? json_encode($decoded) : $response;
            throw new Exception($msg, $httpCode);
        }

        return $decoded;
    }

    public function convertCRC16($str) {
        $crc = 0xFFFF;
        $strlen = strlen($str);
        for ($c = 0; $c < $strlen; $c++) {
            $crc ^= ord($str[$c]) << 8;
            for ($i = 0; $i < 8; $i++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        $hex = strtoupper(dechex($crc & 0xFFFF));
        return str_pad($hex, 4, '0', STR_PAD_LEFT);
    }

    public function createDynamicQRIS($amount, $staticQr) {
        $qrisData = substr($staticQr, 0, -4);
        $step1 = str_replace("010211", "010212", $qrisData);
        $step2 = explode("5802ID", $step1);
        
        if (count($step2) < 2) {
            throw new Exception("Format static QRIS tidak valid.");
        }

        $amountStr = (string)$amount;
        $uang = "54" . str_pad(strlen($amountStr), 2, "0", STR_PAD_LEFT) . $amountStr;
        $uang .= "5802ID";

        $result = $step2[0] . $uang . $step2[1] . $this->convertCRC16($step2[0] . $uang . $step2[1]);
        $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($result);

        return [
            'image_url'  => $qrImageUrl,
            'qr_string'  => $result,
            'amount'     => (int)$amount,
            'created_at' => date('Y-m-d\TH:i:s.000\Z')
        ];
    }

    public function requestOtp($phoneNumber) {
        if (strpos($phoneNumber, "62") === 0) {
            $phoneNumber = substr($phoneNumber, 2);
        }
        $payload = [
            'client_id'    => $this->clientId,
            'phone_number' => $phoneNumber,
            'country_code' => '62'
        ];
        return $this->httpRequest("{$this->baseUrl}/goid/login/request", 'POST', $payload, $this->getHeaders());
    }

    public function verifyOtp($otp, $otpToken) {
        $payload = [
            'client_id' => $this->clientId,
            'data'      => ['otp' => $otp, 'otp_token' => $otpToken],
            'grant_type'=> 'otp'
        ];
        return $this->httpRequest("{$this->baseUrl}/goid/token", 'POST', $payload, $this->getHeaders());
    }

    public function refreshToken($refreshToken) {
        $payload = [
            'client_id' => $this->clientId,
            'grant_type'=> 'refresh_token',
            'data'      => ['refresh_token' => $refreshToken]
        ];
        return $this->httpRequest("{$this->baseUrl}/goid/token", 'POST', $payload, $this->getHeaders());
    }

    public function getMe($accessToken) {
        return $this->httpRequest("{$this->baseUrl}/v1/users/me", 'GET', null, $this->getHeaders($accessToken));
    }

    public function getPayouts($accessToken) {
        return $this->httpRequest("{$this->baseUrl}/v1/merchants/payouts?page=1&per=50", 'GET', null, $this->getHeaders($accessToken));
    }

    public function getJournals($accessToken, $merchantId, $startTime = null) {
        $dateTo = date('Y-m-d\TH:i:s.000\Z');
        $dateFrom = $startTime ?: date('Y-m-d\TH:i:s.000\Z', strtotime('-30 days'));

        $payload = [
            'from' => 0,
            'size' => 50,
            'sort' => ['time' => ['order' => 'desc']],
            'included_categories' => ['incoming' => ['transaction_share', 'action']],
            'query' => [[
                'clauses' => [
                    ['field' => 'metadata.transaction.status', 'op' => 'in', 'value' => ['settlement', 'capture']],
                    ['field' => 'metadata.transaction.transaction_time', 'op' => 'gte', 'value' => $dateFrom],
                    ['field' => 'metadata.transaction.transaction_time', 'op' => 'lte', 'value' => $dateTo],
                    ['field' => 'metadata.transaction.merchant_id', 'op' => 'equal', 'value' => $merchantId]
                ],
                'op' => 'and'
            ]]
        ];

        $headers = array_merge($this->getHeaders($accessToken), ['accept: application/vnd.journal.v1+json']);
        return $this->httpRequest("{$this->baseUrl}/journals/search", 'POST', $payload, $headers);
    }
}

// ============================================================================
// 2. ROUTER BACKEND API (MENANGGAPI FETCH DARI FRONTEND)
// ============================================================================
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$sdk  = new GoMerchant();

function sendJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// API Routes
if ($path === '/auth/otp') {
    try {
        $phone = $_GET['phone'] ?? null;
        if (!$phone) sendJson(['success' => false, 'error' => 'phone wajib diisi'], 400);
        $data = $sdk->requestOtp($phone);
        sendJson([
            'success' => true,
            'data'    => [
                'otp_token' => $data['data']['otp_token'] ?? null,
                'message'   => 'Kode OTP Berhasil Dikirim Via SMS'
            ]
        ]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/auth/verify') {
    try {
        $otp = $_GET['otp'] ?? null;
        $otpToken = $_GET['otp_token'] ?? null;
        if (!$otp || !$otpToken) sendJson(['success' => false, 'error' => 'otp dan otp_token wajib diisi'], 400);
        $data = $sdk->verifyOtp($otp, $otpToken);
        sendJson(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/auth/refresh/token') {
    try {
        $refreshToken = $_GET['refresh_token'] ?? null;
        if (!$refreshToken) sendJson(['success' => false, 'error' => 'refresh_token wajib diisi'], 400);
        $data = $sdk->refreshToken($refreshToken);
        sendJson(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 401);
    }
}

if ($path === '/api/validate') {
    try {
        $token = $_GET['token'] ?? null;
        if (!$token) sendJson(['success' => false, 'error' => 'token wajib diisi'], 400);
        $data = $sdk->getMe($token);
        sendJson(['success' => true, 'user' => $data['user'] ?? null, 'access_token' => $token]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 401);
    }
}

if ($path === '/api/me') {
    try {
        $token = $_GET['token'] ?? null;
        if (!$token) sendJson(['success' => false, 'error' => 'token wajib diisi'], 400);
        $data = $sdk->getMe($token);
        sendJson(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/api/history') {
    try {
        $token = $_GET['token'] ?? null;
        if (!$token) sendJson(['success' => false, 'error' => 'token wajib diisi'], 400);

        $user = $sdk->getMe($token);
        $defaultStartTime = date('Y-m-d\TH:i:s.000\Z', strtotime('-7 days'));
        $startTime = $_GET['start_time'] ?? $defaultStartTime;

        $merchantId = $user['user']['merchant_id'] ?? null;
        $result = $sdk->getJournals($token, $merchantId, $startTime);

        $data = [];
        $hits = $result['hits'] ?? [];
        foreach ($hits as $item) {
            if (($item['metadata']['transaction']['payment_type'] ?? '') === 'qris') {
                $aspi = $item['metadata']['provider_metadata']['aspi'] ?? [];
                $data[] = [
                    'id'           => $item['id'] ?? null,
                    'reference_id' => $item['reference_id'] ?? null,
                    'status'       => $item['status'] ?? null,
                    'time'         => $item['time'] ?? null,
                    'amount'       => $aspi['data']['amount'] ?? 0,
                    'issuer'       => $aspi['issuer'] ?? null,
                    'acquirer'     => $aspi['acquirer'] ?? null,
                    'merchant_name'=> $aspi['data']['merchant_name'] ?? null,
                    'merchant_id'  => $aspi['data']['merchant_id'] ?? null,
                    'merchant_city'=> $aspi['data']['merchant_city'] ?? null,
                    'terminal_label'=> $aspi['data']['additional_data']['terminal_label'] ?? null
                ];
            }
        }
        sendJson(['success' => true, 'total' => count($data), 'data' => $data]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/api/payouts') {
    try {
        $token = $_GET['token'] ?? null;
        if (!$token) sendJson(['success' => false, 'error' => 'token wajib diisi'], 400);
        $data = $sdk->getPayouts($token);
        sendJson(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/api/qris/create') {
    try {
        $amount   = $_GET['amount'] ?? null;
        $staticQr = $_GET['static_qr'] ?? null;
        if (!$amount || !$staticQr) {
            sendJson(['success' => false, 'error' => 'Parameter amount dan static_qr wajib diisi'], 400);
        }
        $data = $sdk->createDynamicQRIS($amount, $staticQr);
        sendJson([
            'success'   => true,
            'image_url' => $data['image_url'],
            'amount'    => $data['amount'],
            'qr_string' => $data['qr_string'],
            'created_at'=> $data['created_at']
        ]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

if ($path === '/api/qris/status') {
    try {
        $token     = $_GET['token'] ?? null;
        $amount    = $_GET['amount'] ?? null;
        $createdAt = $_GET['created_at'] ?? null;

        if (!$token || !$amount || !$createdAt) {
            sendJson(['success' => false, 'error' => 'token, amount, dan created_at wajib diisi'], 400);
        }

        $user = $sdk->getMe($token);
        $merchantId = $user['user']['merchant_id'] ?? null;
        $logs = $sdk->getJournals($token, $merchantId, $createdAt);
        
        $amountSearch = (int)$amount * 100;
        $found = null;
        $qrisTime = strtotime($createdAt);

        foreach ($logs['hits'] ?? [] as $h) {
            $txTime = strtotime($h['time']);
            if (($h['amount'] ?? 0) === $amountSearch && $txTime >= $qrisTime) {
                $found = $h;
                break;
            }
        }

        sendJson(['success' => true, 'status' => $found ? 'PAID' : 'PENDING', 'data' => $found]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'error' => json_decode($e->getMessage()) ?: $e->getMessage()], 400);
    }
}

// Jika ada request path lain selain API di atas, tampilkan UI HTML (halaman utama)
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GoMerch Pro • API Explorer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f1f5f9;
            --card: #ffffff;
            --card-border: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --accent-emerald: #10b981;
            --accent-indigo: #6366f1;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --accent-cyan: #06b6d4;
            --accent-purple: #8b5cf6;
            --accent-blue: #3b82f6;
            --terminal-bg: #0b1120;
            --terminal-text: #a7f3d0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 12px 32px rgba(0, 0, 0, 0.08), 0 2px 6px rgba(0, 0, 0, 0.03);
            --shadow-glow: 0 0 0 1px rgba(16, 185, 129, 0.08), 0 4px 20px rgba(16, 185, 129, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Poppins', system-ui, -apple-system, sans-serif;
            background: #f0f4f8;
            background-image:
                radial-gradient(ellipse 70% 50% at 50% -10%, rgba(16, 185, 129, 0.06), transparent),
                radial-gradient(ellipse 50% 40% at 90% 80%, rgba(99, 102, 241, 0.05), transparent),
                radial-gradient(ellipse 50% 40% at 10% 70%, rgba(6, 182, 212, 0.04), transparent);
            background-attachment: fixed;
            min-height: 100vh;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .premium-card {
            background: #ffffff;
            border: 1px solid #e8ecf1;
            border-radius: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.35s cubic-bezier(0.22, 0.61, 0.36, 1);
            animation: cardAppear 0.5s cubic-bezier(0.22, 0.61, 0.36, 1) forwards;
            position: relative;
            overflow: hidden;
        }
        .premium-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.3), transparent);
            opacity: 0;
            transition: opacity 0.4s ease;
            pointer-events: none;
        }
        .premium-card:hover {
            box-shadow: var(--shadow-lg);
            border-color: #d1d5db;
            transform: translateY(-2px);
        }
        .premium-card:hover::before { opacity: 1; }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .glass-input {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            transition: all 0.25s ease;
            overflow: hidden;
        }
        .glass-input:focus-within {
            border-color: #10b981;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.06), 0 2px 8px rgba(16, 185, 129, 0.04);
        }
        .glass-input input {
            background: transparent;
            border: none;
            outline: none;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.8125rem;
            color: #1e293b;
        }
        .glass-input input::placeholder {
            color: #94a3b8;
            font-family: 'Inter', sans-serif;
            font-size: 0.75rem;
        }
        .glass-input .param-label {
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.6875rem;
            font-weight: 600;
            color: #64748b;
            white-space: nowrap;
            user-select: none;
            letter-spacing: -0.01em;
        }

        .api-terminal {
            background: #0b1120;
            background-image:
                linear-gradient(rgba(16, 185, 129, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(16, 185, 129, 0.02) 1px, transparent 1px);
            background-size: 20px 20px;
            color: #a7f3d0;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            border: 1px solid #1e293b;
            border-radius: 16px;
            position: relative;
        }
        .api-terminal .dots {
            position: absolute;
            top: 12px;
            left: 14px;
            display: flex;
            gap: 7px;
            z-index: 2;
        }
        .api-terminal .dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: block;
        }
        .api-terminal .dots span:nth-child(1) { background: #f43f5e; }
        .api-terminal .dots span:nth-child(2) { background: #f59e0b; }
        .api-terminal .dots span:nth-child(3) { background: #10b981; }
        .api-terminal pre {
            padding-top: 28px;
            scrollbar-width: thin;
            scrollbar-color: #334155 transparent;
        }

        .endpoint-badge {
            font-size: 0.625rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 5px 10px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #0f172a;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.8125rem;
            border-radius: 14px;
            padding: 12px 20px;
            transition: all 0.3s cubic-bezier(0.22, 0.61, 0.36, 1);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: -0.01em;
            position: relative;
            overflow: hidden;
            width: 100%;
        }
        .btn-primary::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(99, 102, 241, 0.3));
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 14px;
        }
        .btn-primary:hover::after { opacity: 1; }
        .btn-primary span, .btn-primary i { position: relative; z-index: 1; }
        .btn-primary:active { transform: scale(0.97); }

        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border-radius: 10px;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            color: #64748b;
            cursor: pointer;
            transition: all 0.25s ease;
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
        }
        .btn-copy:hover {
            border-color: #10b981;
            color: #059669;
            background: #f0fdf4;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.08);
        }
        .btn-copy.copied {
            border-color: #10b981;
            color: #059669;
            background: #d1fae5;
        }

        .copy-result-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border-radius: 8px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(148,163,184,0.3);
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(8px);
            font-family: 'Inter', sans-serif;
        }
        .copy-result-btn:hover {
            background: rgba(255,255,255,0.12);
            border-color: #10b981;
            color: #10b981;
        }

        .toast-container {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 9999;
            pointer-events: none;
        }
        .toast {
            background: #0f172a;
            color: #ffffff;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 14px 22px;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            transition: all 0.35s cubic-bezier(0.22, 0.61, 0.36, 1);
            pointer-events: auto;
            border: 1px solid #334155;
        }
        .toast.show { opacity: 1; transform: translateY(0) scale(1); }
    </style>
</head>
<body class="antialiased">

    <nav class="sticky top-0 z-50 w-full bg-white/80 backdrop-blur-xl border-b border-slate-200/70 shadow-sm" style="background: rgba(255,255,255,0.82); backdrop-filter: blur(20px);">
        <div class="max-w-5xl mx-auto px-5 md:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl flex items-center justify-center shadow-md shadow-slate-300/50">
                    <i data-lucide="terminal" class="w-5 h-5 text-emerald-400"></i>
                </div>
                <span class="font-black text-lg tracking-tight text-slate-800">
                    GoMerch <span class="bg-gradient-to-r from-emerald-600 to-sky-600 bg-clip-text text-transparent">PHP Native</span>
                </span>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 bg-emerald-50 rounded-full border border-emerald-200/70">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                    </span>
                    <span class="text-[0.625rem] font-black text-emerald-700 uppercase tracking-widest">Live Server</span>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto p-4 sm:p-6 md:p-8 pb-24">
        <div class="space-y-4 sm:space-y-5">
            
            <!-- /auth/otp -->
            <div class="premium-card p-5 sm:p-6" data-endpoint="auth-otp">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="endpoint-badge bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200/60">
                            <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> GET
                        </span>
                        <code class="text-sm font-bold text-slate-700 tracking-tight">/auth/otp</code>
                    </div>
                    <button onclick="copyEndpointWithParams('auth-otp', '/auth/otp', ['phone'])" class="btn-copy" id="copy-btn-auth-otp">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                    Mengirim kode OTP ke nomor WhatsApp merchant. Parameter <code class="bg-slate-100 px-1.5 py-0.5 rounded text-slate-600 font-mono text-[0.6875rem]">phone</code> wajib diisi.
                </p>
                <div class="flex flex-col lg:flex-row gap-4 items-stretch">
                    <div class="w-full lg:w-1/2 space-y-3 flex flex-col">
                        <div class="glass-input flex items-center px-4 flex-shrink-0">
                            <span class="param-label min-w-[52px]">phone=</span>
                            <input type="text" id="param-auth-otp-phone" placeholder="628xxx" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <button onclick="runApiTest('/auth/otp', {phone: getVal('param-auth-otp-phone')}, 'res-otp')" class="btn-primary mt-auto">
                            <i data-lucide="play" class="w-4 h-4"></i> <span>Eksekusi</span>
                        </button>
                    </div>
                    <div class="w-full lg:w-1/2 min-h-[120px]">
                        <div class="api-terminal relative h-full min-h-[120px] rounded-2xl">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <button class="copy-result-btn" onclick="copyResult('res-otp')">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                            <pre id="res-otp" class="text-[0.6875rem] sm:text-[0.75rem] p-4 sm:p-5 h-full overflow-auto custom-scrollbar" style="padding-top: 32px;">// Response JSON</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- /auth/verify -->
            <div class="premium-card p-5 sm:p-6" data-endpoint="auth-verify">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="endpoint-badge bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200/60">
                            <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> GET
                        </span>
                        <code class="text-sm font-bold text-slate-700 tracking-tight">/auth/verify</code>
                    </div>
                    <button onclick="copyEndpointWithParams('auth-verify', '/auth/verify', ['otp', 'otp_token'])" class="btn-copy" id="copy-btn-auth-verify">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                    Verifikasi OTP untuk mendapatkan <code class="bg-slate-100 px-1.5 py-0.5 rounded text-slate-600 font-mono text-[0.6875rem]">access_token</code>.
                </p>
                <div class="flex flex-col lg:flex-row gap-4 items-stretch">
                    <div class="w-full lg:w-1/2 space-y-3 flex flex-col">
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[40px]">otp=</span>
                            <input type="text" id="param-auth-verify-otp" placeholder="1234" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[82px]">otp_token=</span>
                            <input type="text" id="param-auth-verify-otp_token" placeholder="token_dari_request_otp" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <button onclick="runApiTest('/auth/verify', {otp: getVal('param-auth-verify-otp'), otp_token: getVal('param-auth-verify-otp_token')}, 'res-verify')" class="btn-primary mt-auto">
                            <i data-lucide="play" class="w-4 h-4"></i> <span>Eksekusi</span>
                        </button>
                    </div>
                    <div class="w-full lg:w-1/2 min-h-[160px]">
                        <div class="api-terminal relative h-full min-h-[160px] rounded-2xl">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <button class="copy-result-btn" onclick="copyResult('res-verify')">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                            <pre id="res-verify" class="text-[0.6875rem] sm:text-[0.75rem] p-4 sm:p-5 h-full overflow-auto custom-scrollbar" style="padding-top: 32px;">// Response JSON</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- /api/history -->
            <div class="premium-card p-5 sm:p-6 border-amber-300/60 bg-gradient-to-br from-white to-amber-50/30" data-endpoint="api-history">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="endpoint-badge bg-amber-100 text-amber-700 ring-1 ring-amber-200/60">
                            <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> GET
                        </span>
                        <code class="text-sm font-bold text-slate-700 tracking-tight">/api/history</code>
                    </div>
                    <button onclick="copyEndpointWithParams('api-history', '/api/history', ['token', 'start_time'])" class="btn-copy" id="copy-btn-api-history">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                    Mengambil riwayat transaksi (7 hari terakhir). Wajib menyertakan token.
                </p>
                <div class="flex flex-col lg:flex-row gap-4 items-stretch">
                    <div class="w-full lg:w-1/2 space-y-3 flex flex-col">
                        <div class="glass-input flex items-center px-4 border-amber-300/60">
                            <span class="param-label min-w-[52px] text-amber-600">token=</span>
                            <input type="text" id="param-api-history-token" placeholder="access_token_anda" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[82px]">start_time=</span>
                            <input type="text" id="param-api-history-start_time" placeholder="YYYY-MM-DD (opsional)" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <button onclick="runApiTest('/api/history', {token: getVal('param-api-history-token'), start_time: getVal('param-api-history-start_time')}, 'res-hist')" class="btn-primary mt-auto">
                            <i data-lucide="play" class="w-4 h-4"></i> <span>Eksekusi</span>
                        </button>
                    </div>
                    <div class="w-full lg:w-1/2 min-h-[160px]">
                        <div class="api-terminal relative h-full min-h-[160px] rounded-2xl">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <button class="copy-result-btn" onclick="copyResult('res-hist')">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                            <pre id="res-hist" class="text-[0.6875rem] sm:text-[0.75rem] p-4 sm:p-5 h-full overflow-auto custom-scrollbar" style="padding-top: 32px;">// Response JSON</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- /api/qris/create -->
            <div class="premium-card p-5 sm:p-6" data-endpoint="api-qris-create">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="endpoint-badge bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200/60">
                            <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> GET
                        </span>
                        <code class="text-sm font-bold text-slate-700 tracking-tight">/api/qris/create</code>
                    </div>
                    <button onclick="copyEndpointWithParams('api-qris-create', '/api/qris/create', ['amount', 'static_qr'])" class="btn-copy" id="copy-btn-api-qris-create">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                    Membuat QRIS Dinamis berdasarkan static QR. Return URL gambar QR.
                </p>
                <div class="flex flex-col lg:flex-row gap-4 items-stretch">
                    <div class="w-full lg:w-1/2 space-y-3 flex flex-col">
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[64px]">amount=</span>
                            <input type="number" id="param-api-qris-create-amount" placeholder="50000" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[72px]">static_qr=</span>
                            <input type="text" id="param-api-qris-create-static_qr" placeholder="000201010212..." class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <button onclick="runApiTest('/api/qris/create', {amount: getVal('param-api-qris-create-amount'), static_qr: getVal('param-api-qris-create-static_qr')}, 'res-qr')" class="btn-primary mt-auto">
                            <i data-lucide="play" class="w-4 h-4"></i> <span>Eksekusi</span>
                        </button>
                    </div>
                    <div class="w-full lg:w-1/2 min-h-[160px]">
                        <div class="api-terminal relative h-full min-h-[160px] rounded-2xl">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <button class="copy-result-btn" onclick="copyResult('res-qr')">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                            <pre id="res-qr" class="text-[0.6875rem] sm:text-[0.75rem] p-4 sm:p-5 h-full overflow-auto custom-scrollbar" style="padding-top: 32px;">// Response JSON</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- /auth/refresh/token -->
            <div class="premium-card p-5 sm:p-6" data-endpoint="auth-refresh">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="endpoint-badge bg-blue-100 text-blue-700 ring-1 ring-blue-200/60">
                            <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> GET
                        </span>
                        <code class="text-sm font-bold text-slate-700 tracking-tight">/auth/refresh/token</code>
                    </div>
                    <button onclick="copyEndpointWithParams('auth-refresh', '/auth/refresh/token', ['refresh_token'])" class="btn-copy" id="copy-btn-auth-refresh">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mb-5 leading-relaxed">
                    Refresh access token menggunakan refresh token yang valid.
                </p>
                <div class="flex flex-col lg:flex-row gap-4 items-stretch">
                    <div class="w-full lg:w-1/2 space-y-3 flex flex-col">
                        <div class="glass-input flex items-center px-4">
                            <span class="param-label min-w-[108px]">refresh_token=</span>
                            <input type="text" id="param-auth-refresh-refresh_token" placeholder="refresh_token_anda" class="w-full bg-transparent py-3 outline-none">
                        </div>
                        <button onclick="runApiTest('/auth/refresh/token', {refresh_token: getVal('param-auth-refresh-refresh_token')}, 'res-refresh')" class="btn-primary mt-auto">
                            <i data-lucide="play" class="w-4 h-4"></i> <span>Eksekusi</span>
                        </button>
                    </div>
                    <div class="w-full lg:w-1/2 min-h-[120px]">
                        <div class="api-terminal relative h-full min-h-[120px] rounded-2xl">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <button class="copy-result-btn" onclick="copyResult('res-refresh')">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                            <pre id="res-refresh" class="text-[0.6875rem] sm:text-[0.75rem] p-4 sm:p-5 h-full overflow-auto custom-scrollbar" style="padding-top: 32px;">// Response JSON</pre>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div class="toast-container">
        <div id="toast" class="toast">
            <span class="toast-icon">✅</span>
            <span id="toast-msg">Copied!</span>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const BASE_URL = window.location.origin;

        function getVal(id) {
            const el = document.getElementById(id);
            return el ? el.value.trim() : '';
        }

        let toastTimer;
        function showToast(message, icon = '✅') {
            const toast = document.getElementById('toast');
            const msg = document.getElementById('toast-msg');
            const iconEl = toast.querySelector('.toast-icon');
            iconEl.textContent = icon;
            msg.textContent = message;
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, 2200);
        }

        function copyEndpointWithParams(endpointKey, path, paramNames) {
            const params = {};
            let filledCount = 0;
            paramNames.forEach(pName => {
                const inputId = `param-${endpointKey}-${pName}`;
                const val = getVal(inputId);
                params[pName] = val; 
                if (val !== '') filledCount++;
            });

            const query = new URLSearchParams(params).toString();
            const fullUrl = query ? `${BASE_URL}${path}?${query}` : `${BASE_URL}${path}`;

            navigator.clipboard.writeText(fullUrl).then(() => {
                const btn = document.getElementById(`copy-btn-${endpointKey}`);
                if (btn) {
                    btn.classList.add('copied');
                    setTimeout(() => btn.classList.remove('copied'), 1500);
                }
                const suffix = filledCount > 0 ? ` (+ ${filledCount} param terisi)` : ' (param kosong)';
                showToast(`Disalin: ${path}${suffix}`, '📋');
            });
        }

        function copyResult(preId) {
            const pre = document.getElementById(preId);
            if (!pre) return;
            const text = pre.innerText || pre.textContent;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Result copied', '📋');
            });
        }

        async function fetchGET(url, params = {}) {
            Object.keys(params).forEach(k => {
                if (params[k] === '' || params[k] === null || params[k] === undefined) {
                    delete params[k];
                }
            });
            const query = new URLSearchParams(params).toString();
            const fullUrl = query ? `${url}?${query}` : url;
            try {
                const res = await fetch(fullUrl);
                const data = await res.json();
                return data;
            } catch (error) {
                return {
                    success: false,
                    error_type: "Network / Server Error",
                    message: error.message
                };
            }
        }

        async function runApiTest(endpoint, params, resultElementId) {
            const resEl = document.getElementById(resultElementId);
            resEl.innerText = "// Loading...";
            resEl.style.color = '#fbbf24';
            try {
                const res = await fetchGET(endpoint, params);
                resEl.style.color = res.success === false ? '#f87171' : '#a7f3d0';
                resEl.innerText = JSON.stringify(res, null, 2);
            } catch (e) {
                resEl.style.color = '#f87171';
                resEl.innerText = "// Error:\n" + e.message;
            }
        }
    </script>
</body>
</html>
