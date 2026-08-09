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
use Throwable;

class ImportContactsFromCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_SIZE = 50;

    public function __construct(
        public ImportJob $import
    ) {}

    public function handle(): void
    {
        $import = $this->import->fresh();
        if (! $import) {
            return;
        }

        // 1. Transition status from pending to processing
        $import->update([
            'status' => ImportJob::STATUS_PROCESSING,
            'started_at' => Carbon::now(),
        ]);

        try {
            $filePath = Storage::path($import->file_path);
            if (! file_exists($filePath)) {
                $import->update([
                    'status' => ImportJob::STATUS_FAILED,
                    'failure_message' => 'System error: File not found in storage location (' . $import->file_path . ').',
                    'completed_at' => Carbon::now(),
                ]);

                return;
            }

            // 2. Read CSV incrementally using stream handle
            $handle = fopen($filePath, 'r');
            if (! $handle) {
                $import->update([
                    'status' => ImportJob::STATUS_FAILED,
                    'failure_message' => 'System error: Unable to open CSV file stream.',
                    'completed_at' => Carbon::now(),
                ]);

                return;
            }

            // Read header row
            $headers = fgetcsv($handle);
            if (! $headers) {
                fclose($handle);
                $import->update([
                    'status' => ImportJob::STATUS_FAILED,
                    'failure_message' => 'System error: CSV file is empty or missing headers.',
                    'completed_at' => Carbon::now(),
                ]);

                return;
            }

            // Clean & normalize headers
            $normalizedHeaders = array_map(function ($header) {
                return strtolower(trim(preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $header)));
            }, $headers);

            // Count total data rows incrementally
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

            // Resolve target vault
            $vaultId = $import->vault_id;
            if (! $vaultId) {
                $defaultVault = $import->user->vaults()->first();
                $vaultId = $defaultVault?->id;
            }

            $rowNumber = 1; // Row 1 is header
            $chunkBuffer = [];

            // 3. Process rows in chunks of ~50
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $chunkBuffer[] = [
                    'row_number' => $rowNumber,
                    'data' => $row,
                ];

                if (count($chunkBuffer) >= self::CHUNK_SIZE) {
                    $this->processChunk(
                        $chunkBuffer,
                        $normalizedHeaders,
                        $import,
                        $vaultId,
                        $processedRows,
                        $successfulRows,
                        $failedRows,
                        $errors
                    );
                    $chunkBuffer = []; // Reset buffer for next chunk
                }
            }

            // Process any remaining rows in final chunk
            if (! empty($chunkBuffer)) {
                $this->processChunk(
                    $chunkBuffer,
                    $normalizedHeaders,
                    $import,
                    $vaultId,
                    $processedRows,
                    $successfulRows,
                    $failedRows,
                    $errors
                );
                $chunkBuffer = [];
            }

            fclose($handle);

            // Determine final completion status:
            // - Set status to 'completed' if at least one contact imported successfully.
            // - Set overall status to 'failed' if NO contact was imported successfully.
            $finalStatus = ImportJob::STATUS_COMPLETED;
            $failureMessage = null;

            if ($successfulRows === 0 && $totalRows > 0) {
                $finalStatus = ImportJob::STATUS_FAILED;
                $failureMessage = "Import failed: No contacts were imported successfully. All {$processedRows} rows had validation or contact creation errors.";
            }

            $import->update([
                'status' => $finalStatus,
                'failure_message' => $failureMessage,
                'processed_rows' => $processedRows,
                'successful_rows' => $successfulRows,
                'failed_rows' => $failedRows,
                'errors' => $errors,
                'completed_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // System-level error handling
            $import->update([
                'status' => ImportJob::STATUS_FAILED,
                'failure_message' => 'System-level error during processing: ' . $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Process a chunk of ~50 rows and update database counters after each chunk.
     */
    private function processChunk(
        array $chunkBuffer,
        array $normalizedHeaders,
        ImportJob $import,
        ?string $vaultId,
        int &$processedRows,
        int &$successfulRows,
        int &$failedRows,
        array &$errors
    ): void {
        foreach ($chunkBuffer as $item) {
            $rowNumber = $item['row_number'];
            $row = $item['data'];
            $processedRows++;

            // Skip completely empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            $rowLength = count($row);
            $headerLength = count($normalizedHeaders);

            if ($rowLength > $headerLength) {
                $row = array_slice($row, 0, $headerLength);
            } elseif ($rowLength < $headerLength) {
                $row = array_pad($row, $headerLength, null);
            }

            $rowData = array_combine($normalizedHeaders, $row);

            $firstName = trim($rowData['first_name'] ?? $rowData['firstname'] ?? $rowData['first name'] ?? '');
            $lastName = trim($rowData['last_name'] ?? $rowData['lastname'] ?? $rowData['last name'] ?? '');
            $middleName = trim($rowData['middle_name'] ?? $rowData['middlename'] ?? '');
            $nickname = trim($rowData['nickname'] ?? '');
            $maidenName = trim($rowData['maiden_name'] ?? $rowData['maidenname'] ?? '');
            $prefix = trim($rowData['prefix'] ?? '');
            $suffix = trim($rowData['suffix'] ?? '');
            $email = trim($rowData['email'] ?? $rowData['email_address'] ?? $rowData['email address'] ?? '');

            // Validation Rule 1: Missing required name (at least first_name, last_name, or nickname)
            if (empty($firstName) && empty($lastName) && empty($nickname)) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => ['Missing required name: At least one of first_name, last_name, or nickname is required.'],
                ];

                continue;
            }

            // Validation Rule 2: Invalid email format
            if (! empty($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => ["Invalid email format: '{$email}' is not a valid email address."],
                ];

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

                // Create contact through Monica's existing CreateContact service
                (new CreateContact)->execute($serviceData);
                $successfulRows++;
            } catch (ValidationException $e) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => array_merge(...array_values($e->errors())),
                ];
            } catch (Exception $e) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        // Update database progress after each chunk
        $import->update([
            'processed_rows' => $processedRows,
            'successful_rows' => $successfulRows,
            'failed_rows' => $failedRows,
            'errors' => $errors,
        ]);
    }
}
