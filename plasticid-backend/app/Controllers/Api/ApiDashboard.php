<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class ApiDashboard extends BaseController
{
    private function auth(): bool
    {
        $apiKey = $this->request->getHeaderLine('X-API-KEY');
        $expectedKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
        return $expectedKey && $apiKey === $expectedKey;
    }

    public function keys()
    {
        if (!$this->auth()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $fastapiHost = env('FASTAPI_HOST', 'plasticid-fastapi');
        $masterKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
        $client = \Config\Services::curlrequest();

        try {
            $response = $client->get("http://{$fastapiHost}:8000/v1/list_keys", [
                'headers' => ['X-API-KEY' => $masterKey],
            ]);
            $data = json_decode($response->getBody(), true);
            return view('dashboard/api_keys', ['keys' => $data['api_keys']]);
        } catch (\Exception $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }

    public function stats()
    {
        if (!$this->auth()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $fastapiHost = env('FASTAPI_HOST', 'plasticid-fastapi');
        $masterKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
        $client = \Config\Services::curlrequest();

        try {
            $response = $client->get("http://{$fastapiHost}:8000/v1/stats", [
                'headers' => ['X-API-KEY' => $masterKey],
            ]);
            $data = json_decode($response->getBody(), true);
            return view('dashboard/api_stats', ['stats' => $data]);
        } catch (\Exception $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }
}
