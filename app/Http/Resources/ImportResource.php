<?php

namespace App\Http\Resources;

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
        return [
            'id' => (int) $this->id,
            'account_id' => $this->account_id,
            'user_id' => $this->user_id,
            'filename' => $this->filename,
            'file_path' => $this->file_path,
            'total_rows' => (int) $this->total_rows,
            'processed_rows' => (int) $this->processed_rows,
            'failed_rows' => (int) $this->failed_rows,
            'successful_rows' => (int) $this->successful_rows,
            'status' => $this->status,
            'failure_message' => $this->failure_message,
            'errors' => $this->errors,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
