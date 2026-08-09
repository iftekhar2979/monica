<?php

namespace App\Http\Controllers\Api;

use App\Domains\Contact\ManageContact\Jobs\ImportContactsFromCsvJob;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ImportResource;
use App\Models\ImportJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Return the current import status and progress.
     */
    #[ResponseFromApiResource(ImportResource::class, ImportJob::class, status: 200)]
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        // Enforce authentication & account ownership
        $importJob = ImportJob::where('account_id', $user->account_id)
            ->findOrFail($id);

        return new ImportResource($importJob);
    }

    /**
     * Cancel an active or pending CSV import job.
     */
    #[ResponseFromApiResource(ImportResource::class, ImportJob::class, status: 200)]
    public function cancel(Request $request, string $id)
    {
        $user = $request->user();

        // Enforce account ownership
        $importJob = ImportJob::where('account_id', $user->account_id)
            ->findOrFail($id);

        if (in_array($importJob->status, [ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED, ImportJob::STATUS_CANCELLED])) {
            return response()->json([
                'message' => "Cannot cancel an import that has already reached state '{$importJob->status}'.",
            ], 422);
        }

        $importJob->update([
            'status' => ImportJob::STATUS_CANCELLED,
            'failure_message' => 'Import was cancelled by user.',
            'completed_at' => Carbon::now(),
        ]);

        return new ImportResource($importJob);
    }

    /**
     * Download a CSV file containing rejected rows and their error details.
     */
    public function downloadFailedRows(Request $request, string $id)
    {
        $user = $request->user();

        // Enforce account ownership
        $importJob = ImportJob::where('account_id', $user->account_id)
            ->findOrFail($id);

        if (empty($importJob->errors) || $importJob->failed_rows === 0) {
            return response()->json([
                'message' => 'No failed rows exist for this import job.',
            ], 404);
        }

        $filePath = Storage::path($importJob->file_path);
        if (! file_exists($filePath)) {
            return response()->json([
                'message' => 'Original uploaded file is no longer available.',
            ], 404);
        }

        // Map row number -> error message string
        $errorMap = [];
        foreach ($importJob->errors as $errorItem) {
            $rowNum = $errorItem['row'] ?? null;
            $errList = $errorItem['errors'] ?? [];
            if ($rowNum) {
                $errorMap[$rowNum] = is_array($errList) ? implode('; ', $errList) : (string) $errList;
            }
        }

        $downloadFilename = 'failed_rows_import_' . $importJob->id . '.csv';

        return response()->streamDownload(function () use ($filePath, $errorMap) {
            $handle = fopen($filePath, 'r');
            if (! $handle) {
                return;
            }

            $output = fopen('php://output', 'w');

            // Header row
            $originalHeaders = fgetcsv($handle);
            if ($originalHeaders) {
                $newHeaders = array_merge(['row_number', 'error_reason'], $originalHeaders);
                fputcsv($output, $newHeaders);
            }

            $currentRowNumber = 1; // Header is row 1
            while (($row = fgetcsv($handle)) !== false) {
                $currentRowNumber++;
                if (isset($errorMap[$currentRowNumber])) {
                    $failedRowData = array_merge(
                        [$currentRowNumber, $errorMap[$currentRowNumber]],
                        $row
                    );
                    fputcsv($output, $failedRowData);
                }
            }

            fclose($handle);
            fclose($output);
        }, $downloadFilename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$downloadFilename}\"",
        ]);
    }
}
