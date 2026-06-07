<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

trait ReadsPropertyJson
{
    /**
     * @return array<string, mixed>
     */
    protected function propertyData(): array
    {
        $path = storage_path('property.json');

        if (! is_readable($path)) {
            throw new RuntimeException('Property data file is missing at storage/property.json');
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            throw new RuntimeException('Property data file is invalid JSON.');
        }

        return $data;
    }
}
