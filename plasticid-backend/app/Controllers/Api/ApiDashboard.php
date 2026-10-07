<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class ApiDashboard extends BaseController
{
    public function keys()
    {
        if (!$this->isMasterRequest()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $client = \Config\Services::curlrequest();

        try {
            $response = $client->get($this->fastApiBaseUrl() . '/v1/list_keys', [
                'headers' => $this->fastApiHeaders(),
                'timeout' => 5,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $this->response
                    ->setStatusCode(502)
                    ->setJSON(['error' => 'Upstream service unavailable']);
            }

            $data = json_decode($response->getBody(), true);
            if (!is_array($data) || !isset($data['api_keys']) || !is_array($data['api_keys'])) {
                return $this->response
                    ->setStatusCode(502)
                    ->setJSON(['error' => 'Invalid upstream response']);
            }

            return view('dashboard/api_keys', ['keys' => $data['api_keys']]);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }

    public function stats()
    {
        if (!$this->isMasterRequest()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $client = \Config\Services::curlrequest();

        try {
            $response = $client->get($this->fastApiBaseUrl() . '/v1/stats', [
                'headers' => $this->fastApiHeaders(),
                'timeout' => 5,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $this->response
                    ->setStatusCode(502)
                    ->setJSON(['error' => 'Upstream service unavailable']);
            }

            $data = json_decode($response->getBody(), true);
            if (!is_array($data)) {
                return $this->response
                    ->setStatusCode(502)
                    ->setJSON(['error' => 'Invalid upstream response']);
            }

            return view('dashboard/api_stats', ['stats' => $data]);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }
}
