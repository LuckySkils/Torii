<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * A multi-select query param, as `key[]=a&key[]=b` or `key=a,b`: trimmed,
     * without empties or duplicates.
     *
     * @return array<int, string>
     */
    protected function listParam(Request $request, string $key): array
    {
        $value = $request->query($key);
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(
            array_map(fn ($item) => trim((string) $item), $values),
            fn (string $item) => $item !== '',
        )));
    }
}
