<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ImageModel;
use App\Models\PredictionModel;

class ApiDashboard extends BaseController
{
    public function keys()
    {
        $client = \Config\Services::curlrequest();
        $response = $client->get('http://plasticid-fastapi:8000/v1/list_keys');
        $data = json_decode($response->getBody(), true);
        return view('dashboard/api_keys', ['keys' => $data['api_keys']]);
    }

    public function stats()
    {
        $client = \Config\Services::curlrequest();
        $response = $client->get("http://plasticid-fastapi:8000/v1/stats");
        $data = json_decode($response->getBody(), true);
        return view('dashboard/api_stats', [
            'stats' => $data
        ]);
    }
}
