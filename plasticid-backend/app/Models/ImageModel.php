<?php

namespace App\Models;

use CodeIgniter\Model;

class ImageModel extends Model
{
    protected $table = 'images';
    protected $allowedFields = [
        'filename',
        'job_id',
        'source',
        'api_key_id',
        'model',
        'inference_ms',
        'detection_count',
        'detected_object',
        'image_width',
        'image_height',
        'image_sha256',
        'content_type',
    ];
}
