<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progress of one one-time bootstrap task (§12), by its stable key. Completed
 * only when the task's work succeeded; `error` holds the last failure.
 */
class BootstrapTask extends Model
{
    protected $fillable = ['key', 'started_at', 'completed_at', 'error'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return 'done'|'failed'|'running'|'pending' */
    public function state(): string
    {
        return match (true) {
            $this->completed_at !== null => 'done',
            $this->error !== null => 'failed',
            $this->started_at !== null => 'running',
            default => 'pending',
        };
    }
}
