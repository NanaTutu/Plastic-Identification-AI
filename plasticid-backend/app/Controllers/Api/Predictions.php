<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ImageModel;
use App\Models\PredictionModel;

class Predictions extends BaseController
{
    private function stringField(array $payload, string $key, int $maxLength, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid ' . $key);
        }
        $value = trim($value);
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Invalid ' . $key);
        }

        return $value;
    }

    private function integerField(array $payload, string $key, bool $allowZero = false): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        if (!is_int($payload[$key]) && !(is_string($payload[$key]) && ctype_digit($payload[$key]))) {
            throw new \InvalidArgumentException('Invalid ' . $key);
        }
        $value = (int) $payload[$key];
        if ($value < 0 || (!$allowZero && $value < 1)) {
            throw new \InvalidArgumentException('Invalid ' . $key);
        }

        return $value;
    }

    private function safeFilename(array $payload, string $jobId): string
    {
        $filename = $payload['filename'] ?? $jobId;
        if (!is_string($filename)) {
            return $jobId;
        }
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        if ($filename === '' || strlen($filename) > 255) {
            return $jobId;
        }

        return $filename;
    }

    private function duplicateResponse(?int $imageId): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setJSON([
            'status' => 'duplicate',
            'image_id' => $imageId,
            'predictions_inserted' => 0,
        ]);
    }

    public function store()
    {
        if (!$this->isMasterRequest()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid payload']);
        }

        $jobId = trim((string) ($payload['job_id'] ?? ''));
        if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $jobId)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid payload']);
        }

        $source = $payload['source'] ?? 'api';
        if (!is_string($source) || !in_array($source, ['api', 'portal', 'web'], true)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid source']);
        }
        $source = $source === 'web' ? 'portal' : $source;

        $detections = $payload['detections'] ?? [];
        if (!is_array($detections) || count($detections) > 1000) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Invalid detections']);
        }

        try {
            $model = $this->stringField($payload, 'model', 64);
            $detectedObject = $this->stringField($payload, 'detected_object', 64);
            $contentType = $this->stringField($payload, 'content_type', 100);
            $imageSha256 = $this->stringField($payload, 'image_sha256', 64);
            if ($imageSha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $imageSha256)) {
                throw new \InvalidArgumentException('Invalid image_sha256');
            }
            $apiKeyId = $this->integerField($payload, 'api_key_id');
            $inferenceMs = $this->integerField($payload, 'inference_ms', true) ?? 0;
            $imageWidth = $this->integerField($payload, 'image_width');
            $imageHeight = $this->integerField($payload, 'image_height');
        } catch (\InvalidArgumentException $e) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => $e->getMessage()]);
        }

        $imageModel = new ImageModel();
        $predictionModel = new PredictionModel();
        $db = \Config\Database::connect();

        $db->transBegin();
        $existing = $db->table('images')->select('id')->where('job_id', $jobId)->get()->getRowArray();
        if ($existing !== null) {
            $db->transCommit();

            return $this->duplicateResponse((int) $existing['id']);
        }

        try {
            $imageId = $imageModel->insert([
                'filename'         => $this->safeFilename($payload, $jobId),
                'job_id'           => $jobId,
                'source'           => $source,
                'api_key_id'       => $apiKeyId,
                'model'            => $model !== '' ? $model : null,
                'inference_ms'     => $inferenceMs,
                'detection_count'  => count($detections),
                'detected_object'  => $detectedObject !== '' ? $detectedObject : null,
                'image_width'      => $imageWidth,
                'image_height'     => $imageHeight,
                'image_sha256'     => $imageSha256 !== '' ? $imageSha256 : null,
                'content_type'     => $contentType !== '' ? $contentType : null,
            ]);

            if (!$imageId) {
                $db->transRollback();

                return $this->response
                    ->setStatusCode(500)
                    ->setJSON(['error' => 'Failed to store predictions']);
            }

            $inserted = 0;
            foreach ($detections as $detection) {
                if (!is_array($detection)) {
                    continue;
                }
                if (!isset($detection['class'], $detection['confidence'], $detection['bbox'])) {
                    continue;
                }
                if (!is_string($detection['class']) || $detection['class'] === '' || strlen($detection['class']) > 100) {
                    continue;
                }
                if (!is_numeric($detection['confidence']) || $detection['confidence'] < 0 || $detection['confidence'] > 1) {
                    continue;
                }

                $bbox = $detection['bbox'];
                if (!is_array($bbox) || count($bbox) !== 4) {
                    continue;
                }
                if (!is_numeric($bbox[0]) || !is_numeric($bbox[1]) || !is_numeric($bbox[2]) || !is_numeric($bbox[3])) {
                    continue;
                }

                $x1 = (float) $bbox[0];
                $y1 = (float) $bbox[1];
                $x2 = (float) $bbox[2];
                $y2 = (float) $bbox[3];
                if ($x2 < $x1 || $y2 < $y1) {
                    continue;
                }

                $predictionId = $predictionModel->insert([
                    'image_id'   => (int) $imageId,
                    'label'      => $detection['class'],
                    'confidence' => (float) $detection['confidence'],
                    'x1'         => $x1,
                    'y1'         => $y1,
                    'x2'         => $x2,
                    'y2'         => $y2,
                ]);
                if (!$predictionId) {
                    $db->transRollback();

                    return $this->response
                        ->setStatusCode(500)
                        ->setJSON(['error' => 'Failed to store predictions']);
                }
                $inserted++;
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $existing = $db->table('images')->select('id')->where('job_id', $jobId)->get()->getRowArray();
                if ($existing !== null) {
                    return $this->duplicateResponse((int) $existing['id']);
                }
            }
            log_message('error', 'Prediction storage failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'Failed to store predictions']);
        }

        return $this->response->setJSON([
            'status' => 'stored',
            'image_id' => (int) $imageId,
            'predictions_inserted' => $inserted,
        ]);
    }

    public function index()
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
            if (!is_array($data)) {
                return $this->response
                    ->setStatusCode(502)
                    ->setJSON(['error' => 'Invalid upstream response']);
            }

            return $this->response->setJSON($data);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'Upstream service unavailable']);
        }
    }
}
