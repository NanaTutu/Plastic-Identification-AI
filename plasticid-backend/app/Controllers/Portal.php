<?php

namespace App\Controllers;

class Portal extends BaseController
{
    private function fastapiHost(): string
    {
        return env('FASTAPI_HOST', 'plasticid-fastapi');
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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return view('portal/request_key', [
                'error' => 'Please enter a valid email address.',
                'name'  => $name,
                'email' => $email,
            ]);
        }

        $client = \Config\Services::curlrequest();

        try {
            $masterKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
            $response = $client->post("http://{$this->fastapiHost()}:8000/v1/keys", [
                'headers' => ['X-API-KEY' => $masterKey],
                'json' => [
                    'owner'          => $name,
                    'rate_limit'     => 100,
                    'window_seconds' => 3600,
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            if (!isset($data['api_key'])) {
                throw new \RuntimeException('Invalid response from key service');
            }

            return view('portal/request_key', [
                'success' => true,
                'api_key' => $data['api_key'],
                'name'    => $name,
            ]);
        } catch (\Exception $e) {
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

            $response = $client->post(
                "http://{$this->fastapiHost()}:8000/v1/predict",
                [
                    'headers' => ['X-API-KEY' => $portalKey],
                    'timeout' => 30,
                    'multipart' => [
                        'image' => $curlFile,
                    ],
                ]
            );

            $result = json_decode($response->getBody(), true) ?? [];

            return view('portal/playground', [
                'result'   => $result,
                'filename' => $file->getName(),
                'error'    => isset($result['error'])
                    ? ($result['error']['message'] ?? 'Prediction service error.')
                    : null,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Prediction failed: ' . $e->getMessage());
            return view('portal/playground', ['error' => 'Prediction service error. Please try again.']);
        } finally {
            if (file_exists($file->getTempName())) {
                unlink($file->getTempName());
            }
        }
    }
}
