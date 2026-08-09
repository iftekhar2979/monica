<?php

namespace App\Http\Resources;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progressPct = 0;
        if ($this->total_rows > 0) {
            $progressPct = (int) min(100, floor(($this->processed_rows / $this->total_rows) * 100));
        } elseif ($this->status === ImportJob::STATUS_COMPLETED) {
            $progressPct = 100;
        }

        return [
            'id' => (int) $this->id,
            'filename' => $this->filename,
            'total_rows' => (int) $this->total_rows,
            'processed_rows' => (int) $this->processed_rows,
            'failed_rows' => (int) $this->failed_rows,
            'status' => $this->status,
            'progress_pct' => $progressPct,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
