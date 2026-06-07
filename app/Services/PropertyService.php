<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PropertyService
{
    public function getData(): array
    {
        return Cache::remember('property.data', 3600, function (): array {
            $path = storage_path('property.json');

            if (! is_readable($path)) {
                throw new RuntimeException('Property data file is missing.');
            }

            $data = json_decode(file_get_contents($path), true);

            if (! is_array($data)) {
                throw new RuntimeException('Property data file is invalid.');
            }

            return $data;
        });
    }

    public function clearCache(): void
    {
        Cache::forget('property.data');
    }
}
