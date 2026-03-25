<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ImageModel;
use App\Models\PredictionModel;

class Predictions extends BaseController
{
    public function store()
    {
        // echo "API Predictions endpoint is active.";

       log_message('error', '🔥 POST /api/predictions HIT');

        $payload = $this->request->getJSON(true);

        if (!$payload || empty($payload['job_id'])) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid payload']);
        }

        $imageModel = new ImageModel();
        $predictionModel = new PredictionModel();

        // 1️⃣ Insert image / job
        $imageId = $imageModel->insert([
            'filename' => $payload['job_id'],
            'source'   => $payload['source'] ?? 'api'
        ]);

        if (!$imageId) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'Failed to insert image']);
        }

        // 2️⃣ Insert predictions
        $inserted = 0;

        if (!empty($payload['detections']) && is_array($payload['detections'])) {
            foreach ($payload['detections'] as $d) {
                $predictionModel->insert([
                    'image_id'   => $imageId,
                    'label'      => $d['class'],
                    'confidence' => $d['confidence'],
                    'x1' => $d['bbox'][0],
                    'y1' => $d['bbox'][1],
                    'x2' => $d['bbox'][2],
                    'y2' => $d['bbox'][3],
                ]);
                $inserted++;
            }
        }

        return $this->response->setJSON([
            'status' => 'stored',
            'image_id' => $imageId,
            'predictions_inserted' => $inserted
        ]);
        // log_message('error', '🔥 /api/predictions HIT');

        // $apiKey = $this->request->getHeaderLine('X-API-KEY');
        // if ($apiKey !== getenv('PLASTICID_API_KEY')) {
        //     return $this->response
        //         ->setStatusCode(401)
        //         ->setJSON(['error' => 'Unauthorized']);
        // }

        // $payload = $this->request->getJSON(true);

        // if (!$payload || empty($payload['job_id'])) {
        //     return $this->response
        //         ->setStatusCode(400)
        //         ->setJSON(['error' => 'Invalid payload']);
        // }

        // $imageModel = new ImageModel();
        // $predictionModel = new PredictionModel();

        // // Insert image/job
        // $imageId = $imageModel->insert([
        //     'filename' => $payload['job_id'],
        //     'source'   => $payload['source'] ?? 'api'
        // ]);

        // if (!$imageId) {
        //     return $this->response
        //         ->setStatusCode(500)
        //         ->setJSON(['error' => 'Image insert failed']);
        // }

        // // Insert predictions
        // foreach ($payload['detections'] as $d) {
        //     $predictionModel->insert([
        //         'image_id'   => 100,
        //         'label'      => 'testing',
        //         'confidence' => 0.0444,
        //         'x1' => 0.4,
        //         'y1' => 0.4,
        //         'x2' => 0.4,
        //         'y2' => 0.4,
        //     ]);
        // }

        // return $this->response->setJSON([
        //     'status'   => 'stored',
        //     'image_id'=> $imageId,
        //     'count'   => count($payload['detections'])
        // ]);
    }

    public function index()
    {
        $client = \Config\Services::curlrequest();
        $response = $client->get('http://plasticid-fastapi:8000/v1/list_keys');
        $data = json_decode($response->getBody(), true);
        return $this->response->setJSON($data);
    }

    public function keys()
    {
        $client = \Config\Services::curlrequest();
        $response = $client->get('http://plasticid-fastapi:8000/v1/list_keys');
        $data = json_decode($response->getBody(), true);
        return view('dashboard/keys', ['keys' => $data['api_keys']]);
    }
}
