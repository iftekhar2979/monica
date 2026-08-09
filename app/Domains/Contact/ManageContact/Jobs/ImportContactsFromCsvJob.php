<?php

namespace App\Domains\Contact\ManageContact\Jobs;

use App\Domains\Contact\ManageContact\Services\CreateContact;
use App\Models\ImportJob;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ImportContactsFromCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ImportJob $import
    ) {}

    public function handle(): void
    {
        $import = $this->import->fresh();
        if (! $import) {
            return;
        }

        $import->update([
            'status' => ImportJob::STATUS_PROCESSING,
            'started_at' => Carbon::now(),
        ]);

        $filePath = Storage::path($import->file_path);
        if (! file_exists($filePath)) {
            $import->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_message' => 'File not found in storage location: ' . $import->file_path,
                'errors' => [['row' => 0, 'errors' => ['File not found in storage location.']]],
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $import->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_message' => 'Unable to open CSV file at ' . $filePath,
                'errors' => [['row' => 0, 'errors' => ['Unable to open CSV file.']]],
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        // Read headers
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            $import->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_message' => 'CSV file is empty or missing headers.',
                'errors' => [['row' => 0, 'errors' => ['CSV file is empty or missing headers.']]],
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        // Clean headers (trim whitespace, lowercase)
        $normalizedHeaders = array_map(function ($header) {
            return strtolower(trim(preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $header)));
        }, $headers);

        // Count total rows
        $totalRows = 0;
        while (fgets($handle) !== false) {
            $totalRows++;
        }
        rewind($handle);
        fgetcsv($handle); // Skip header row again

        $import->update(['total_rows' => $totalRows]);

        $processedRows = 0;
        $successfulRows = 0;
        $failedRows = 0;
        $errors = [];

        // Ensure default vault if vault_id is null
        $vaultId = $import->vault_id;
        if (! $vaultId) {
            $defaultVault = $import->user->vaults()->first();
            $vaultId = $defaultVault?->id;
        }

        $rowNumber = 1; // Row 1 was header
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $processedRows++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Combine headers with row values
            $rowLength = count($row);
            $headerLength = count($normalizedHeaders);

            if ($rowLength > $headerLength) {
                $row = array_slice($row, 0, $headerLength);
            } elseif ($rowLength < $headerLength) {
                $row = array_pad($row, $headerLength, null);
            }

            $rowData = array_combine($normalizedHeaders, $row);

            // Extract contact fields
            $firstName = trim($rowData['first_name'] ?? $rowData['firstname'] ?? $rowData['first name'] ?? '');
            $lastName = trim($rowData['last_name'] ?? $rowData['lastname'] ?? $rowData['last name'] ?? '');
            $middleName = trim($rowData['middle_name'] ?? $rowData['middlename'] ?? '');
            $nickname = trim($rowData['nickname'] ?? '');
            $maidenName = trim($rowData['maiden_name'] ?? $rowData['maidenname'] ?? '');
            $prefix = trim($rowData['prefix'] ?? '');
            $suffix = trim($rowData['suffix'] ?? '');

            // Basic row validation check: at least first_name, last_name, or nickname must be present
            if (empty($firstName) && empty($lastName) && empty($nickname)) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => ['At least one of first_name, last_name, or nickname is required.'],
                ];

                $import->update([
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                    'errors' => $errors,
                ]);

                continue;
            }

            try {
                $serviceData = [
                    'account_id' => $import->account_id,
                    'author_id' => $import->user_id,
                    'vault_id' => $vaultId,
                    'first_name' => $firstName ?: null,
                    'last_name' => $lastName ?: null,
                    'middle_name' => $middleName ?: null,
                    'nickname' => $nickname ?: null,
                    'maiden_name' => $maidenName ?: null,
                    'prefix' => $prefix ?: null,
                    'suffix' => $suffix ?: null,
                    'listed' => true,
                ];

                (new CreateContact)->execute($serviceData);
                $successfulRows++;
            } catch (ValidationException $e) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => array_values($e->errors()),
                ];
            } catch (Exception $e) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                ];
            }

            // Periodically update progress
            $import->update([
                'processed_rows' => $processedRows,
                'successful_rows' => $successfulRows,
                'failed_rows' => $failedRows,
                'errors' => $errors,
            ]);
        }

        fclose($handle);

        $import->update([
            'status' => ImportJob::STATUS_COMPLETED,
            'processed_rows' => $processedRows,
            'successful_rows' => $successfulRows,
            'failed_rows' => $failedRows,
            'errors' => $errors,
            'completed_at' => Carbon::now(),
        ]);
    }
}
