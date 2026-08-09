<?php

namespace App\Http\Controllers\Api;

use App\Domains\Contact\ManageContact\Jobs\ImportContactsFromCsvJob;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ImportResource;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\{BodyParam, ResponseFromApiResource};

/**
 * @group Contact Import
 *
 * @subgroup Imports
 */
class ImportController extends ApiController
{
    /**
     * Upload a CSV file and begin background processing.
     */
    #[BodyParam('file', 'file', description: 'The CSV file containing contact records.', required: true)]
    #[ResponseFromApiResource(ImportResource::class, ImportJob::class, status: 201)]
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'vault_id' => 'nullable|uuid|exists:vaults,id',
        ]);

        $user = $request->user();
        $file = $request->file('file');

        // Store file in non-public storage/app/imports directory
        $filePath = $file->store('imports');

        // Create import tracking record in pending status
        $import = ImportJob::create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'vault_id' => $request->input('vault_id') ?? $user->vaults()->first()?->id,
            'filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        // Dispatch background processing job
        ImportContactsFromCsvJob::dispatch($import);

        // Return 201 Created response immediately without synchronous processing
        return (new ImportResource($import))
            ->response()
            ->setStatusCode(201);
    }
}
