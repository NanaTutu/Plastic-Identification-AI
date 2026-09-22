<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ImageModel;
use App\Models\PredictionModel;

class Predictions extends BaseController
{
    public function store()
    {
        $apiKey = $this->request->getHeaderLine('X-API-KEY');
        $expectedKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
        if (!$expectedKey || $apiKey !== $expectedKey) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $payload = $this->request->getJSON(true);

        if (!$payload || empty($payload['job_id'])) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid payload']);
        }

        $imageModel = new ImageModel();
        $predictionModel = new PredictionModel();
        $db = \Config\Database::connect();

        $db->transStart();

        $imageId = $imageModel->insert([
            'filename' => $payload['job_id'],
            'source'   => $payload['source'] ?? 'api'
        ]);

        $inserted = 0;

        if ($imageId && !empty($payload['detections']) && is_array($payload['detections'])) {
            foreach ($payload['detections'] as $d) {
                if (!isset($d['class'], $d['confidence'], $d['bbox']) || !is_array($d['bbox'])) {
                    continue;
                }
                if (!is_string($d['class'])) {
                    continue;
                }
                if (!is_numeric($d['confidence']) || $d['confidence'] < 0 || $d['confidence'] > 1) {
                    continue;
                }
                $bbox = $d['bbox'];
                if (!isset($bbox[0], $bbox[1], $bbox[2], $bbox[3])) {
                    continue;
                }
                if (!is_numeric($bbox[0]) || !is_numeric($bbox[1]) || !is_numeric($bbox[2]) || !is_numeric($bbox[3])) {
                    continue;
                }

                $predictionModel->insert([
                    'image_id'   => $imageId,
                    'label'      => $d['class'],
                    'confidence' => (float) $d['confidence'],
                    'x1'         => (float) $bbox[0],
                    'y1'         => (float) $bbox[1],
                    'x2'         => (float) $bbox[2],
                    'y2'         => (float) $bbox[3],
                ]);
                $inserted++;
            }
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'Failed to store predictions']);
        }

        return $this->response->setJSON([
            'status' => 'stored',
            'image_id' => $imageId,
            'predictions_inserted' => $inserted
        ]);
    }

    public function index()
    {
        $apiKey = $this->request->getHeaderLine('X-API-KEY');
        $expectedKey = getenv('PLASTICID_API_KEY') ?: getenv('API_KEY');
        if (!$expectedKey || $apiKey !== $expectedKey) {
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
            return $this->response->setJSON($data);
        } catch (\Exception $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }
}
