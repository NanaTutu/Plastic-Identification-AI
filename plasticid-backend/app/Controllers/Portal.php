<?php

namespace App\Controllers;

class Portal extends BaseController
{
    private function allowAction(string $action, int $limit, int $windowSeconds): bool
    {
        $session = \Config\Services::session();
        $key = 'portal_' . $action . '_' . sha1($this->request->getIPAddress());
        $now = time();
        $entries = $session->get($key, []);
        $entries = array_values(array_filter(
            is_array($entries) ? $entries : [],
            static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - $windowSeconds
        ));
        if (count($entries) >= $limit) {
            $session->set($key, $entries);
            return false;
        }

        $entries[] = $now;
        $session->set($key, $entries);
        return true;
    }

    private function startKeyRequest(string $name, string $email): ?int
    {
        try {
            $db = \Config\Database::connect();
            $db->table('api_key_requests')->insert([
                'name' => $name,
                'email' => $email,
                'status' => 'pending',
            ]);

            return (int) $db->insertID();
        } catch (\Throwable $e) {
            log_message('error', 'Unable to record API key request: ' . $e->getMessage());
            return null;
        }
    }

    private function finishKeyRequest(
        ?int $requestId,
        string $status,
        ?int $apiKeyId = null,
        ?string $errorMessage = null
    ): void {
        if ($requestId === null) {
            return;
        }

        try {
            $db = \Config\Database::connect();
            $update = [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($apiKeyId !== null) {
                $update['api_key_id'] = $apiKeyId;
            }
            if ($errorMessage !== null) {
                $update['error_message'] = substr($errorMessage, 0, 255);
            }
            $db->table('api_key_requests')->where('id', $requestId)->update($update);
        } catch (\Throwable $e) {
            log_message('error', 'Unable to update API key request: ' . $e->getMessage());
        }
    }

    public function index()
    {
        return view('portal/landing');
    }

    public function methodology()
    {
        return view('portal/methodology');
    }

    public function docs()
    {
        return view('portal/docs');
    }

    public function playground()
    {
        return view('portal/playground');
    }

    public function request_key()
    {
        return view('portal/request_key');
    }

    public function submit_request()
    {
        $name = trim($this->request->getPost('name') ?? '');
        $email = trim($this->request->getPost('email') ?? '');

        if ($name === '' || $email === '') {
            return view('portal/request_key', [
                'error' => 'Name and email are required.',
                'name'  => $name,
                'email' => $email,
            ]);
        }
        if (strlen($name) > 255) {
            return view('portal/request_key', [
                'error' => 'Name must be 255 characters or fewer.',
                'name'  => $name,
                'email' => $email,
            ]);
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return view('portal/request_key', [
                'error' => 'Please enter a valid email address.',
                'name'  => $name,
                'email' => $email,
            ]);
        }
        if (!$this->allowAction('key_request', 5, 3600)) {
            return view('portal/request_key', [
                'error' => 'Too many key requests. Please try again later.',
                'name'  => $name,
                'email' => $email,
            ]);
        }

        $requestId = $this->startKeyRequest($name, $email);

        try {
            $masterKey = $this->expectedMasterKey();
            if ($masterKey === null) {
                throw new \RuntimeException('Master API key is not configured');
            }

            $client = \Config\Services::curlrequest();
            $response = $client->post($this->fastApiBaseUrl() . '/v1/keys', [
                'headers' => $this->fastApiHeaders(),
                'timeout' => 10,
                'json' => [
                    'owner' => $name,
                    'rate_limit' => 100,
                    'window_seconds' => 3600,
                ],
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new \RuntimeException('Key service returned HTTP ' . $response->getStatusCode());
            }

            $data = json_decode($response->getBody(), true);
            if (!is_array($data) || !isset($data['api_key']) || !is_string($data['api_key'])) {
                throw new \RuntimeException('Invalid response from key service');
            }
            $apiKeyId = isset($data['id']) && is_int($data['id']) ? $data['id'] : null;

            $this->finishKeyRequest($requestId, 'approved', $apiKeyId);

            return view('portal/request_key', [
                'success' => true,
                'api_key' => $data['api_key'],
                'name' => $name,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->finishKeyRequest($requestId, 'failed', null, $e->getMessage());
            log_message('error', 'API key generation failed: ' . $e->getMessage());
            return view('portal/request_key', [
                'error' => 'Failed to generate API key. Please try again later.',
                'name'  => $name,
                'email' => $email,
            ]);
        }
    }

    public function predict()
    {
        if (!$this->allowAction('prediction', (int) (getenv('PORTAL_REQUESTS_PER_MINUTE') ?: 20), 60)) {
            return view('portal/playground', ['error' => 'Too many playground requests. Please try again later.']);
        }

        $file = $this->request->getFile('image');
        if (!$file || !$file->isValid()) {
            return view('portal/playground', ['error' => 'Please upload a valid image file.']);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return view('portal/playground', ['error' => 'File size exceeds 10 MB limit.']);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($file->getMimeType(), $allowedTypes, true)) {
            return view('portal/playground', ['error' => 'Only JPEG and PNG images are allowed.']);
        }

        $portalKey = getenv('PLASTICID_PORTAL_API_KEY') ?: getenv('PORTAL_API_KEY');
        if (!$portalKey) {
            return view('portal/playground', ['error' => 'Playground is not configured.']);
        }

        try {
            $client = \Config\Services::curlrequest();
            $curlFile = new \CURLFile(
                $file->getTempName(),
                $file->getMimeType(),
                $file->getName()
            );
            $response = $client->post($this->fastApiBaseUrl() . '/v1/predict', [
                'headers' => ['X-API-KEY' => $portalKey],
                'timeout' => 30,
                'multipart' => [
                    'image' => $curlFile,
                ],
            ]);
            $result = json_decode($response->getBody(), true) ?? [];
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $message = 'Prediction service error.';
                if (is_array($result) && isset($result['error']) && is_array($result['error'])) {
                    $message = (string) ($result['error']['message'] ?? $message);
                }
                return view('portal/playground', [
                    'filename' => $file->getName(),
                    'error' => $message,
                ]);
            }

            return view('portal/playground', [
                'result' => $result,
                'filename' => $file->getName(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Prediction failed: ' . $e->getMessage());
            return view('portal/playground', ['error' => 'Prediction service error. Please try again.']);
        } finally {
            $tempName = $file->getTempName();
            if ($tempName && file_exists($tempName)) {
                unlink($tempName);
            }
        }
    }
}
