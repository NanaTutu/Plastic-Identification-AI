<?php

namespace App\Models;

use CodeIgniter\Model;

class PredictionModel extends Model
{
    protected $table = 'predictions';
    protected $allowedFields = [
        'image_id',
        'label',
        'confidence',
        'x1', 'y1', 'x2', 'y2'
    ];
}
