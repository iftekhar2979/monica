<?php

namespace Tests\Unit\Jobs;

use App\Domains\Contact\ManageContact\Jobs\ImportContactsFromCsvJob;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportContactsFromCsvJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_row_error_isolation_keeps_status_completed_on_partial_success(): void
    {
        Storage::fake('local');

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $vault = Vault::factory()->create(['account_id' => $account->id]);
        $user->vaults()->attach($vault->id, ['permission' => 1]);

        $csvLines = [
            "first_name,last_name,email",
            "Alice,Smith,alice@example.com",
            "Bob,Jones,invalid-email-address", // Invalid email
            ",,",                              // Missing name
            "Charlie,Brown,charlie@example.com",
        ];

        $filePath = 'imports/test_isolation.csv';
        Storage::put($filePath, implode("\n", $csvLines));

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'filename' => 'test_isolation.csv',
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        $job = new ImportContactsFromCsvJob($import);
        $job->handle();

        $import->refresh();

        $this->assertEquals(ImportJob::STATUS_COMPLETED, $import->status);
        $this->assertEquals(4, $import->total_rows);
        $this->assertEquals(4, $import->processed_rows);
        $this->assertEquals(2, $import->successful_rows);
        $this->assertEquals(2, $import->failed_rows);
        $this->assertCount(2, $import->errors);

        $this->assertDatabaseHas('contacts', [
            'vault_id' => $vault->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);

        $this->assertDatabaseHas('contacts', [
            'vault_id' => $vault->id,
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
        ]);
    }

    public function test_job_sets_status_to_failed_if_no_contact_imported_successfully(): void
    {
        Storage::fake('local');

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $vault = Vault::factory()->create(['account_id' => $account->id]);

        $csvLines = [
            "first_name,last_name,email",
            ",,invalid-email-1",
            ",,invalid-email-2",
        ];

        $filePath = 'imports/all_invalid.csv';
        Storage::put($filePath, implode("\n", $csvLines));

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'filename' => 'all_invalid.csv',
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        $job = new ImportContactsFromCsvJob($import);
        $job->handle();

        $import->refresh();

        $this->assertEquals(ImportJob::STATUS_FAILED, $import->status);
        $this->assertEquals(2, $import->total_rows);
        $this->assertEquals(2, $import->processed_rows);
        $this->assertEquals(0, $import->successful_rows);
        $this->assertEquals(2, $import->failed_rows);
        $this->assertStringContainsString('No contacts were imported successfully', $import->failure_message);
    }

    public function test_job_retry_is_idempotent_and_does_not_create_duplicate_contacts(): void
    {
        Storage::fake('local');

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $vault = Vault::factory()->create(['account_id' => $account->id]);
        $user->vaults()->attach($vault->id, ['permission' => 1]);

        $csvLines = [
            "first_name,last_name",
            "David,Miller",
            "Eva,Green",
        ];

        $filePath = 'imports/retry_test.csv';
        Storage::put($filePath, implode("\n", $csvLines));

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'filename' => 'retry_test.csv',
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        // First attempt / execution of job
        $job1 = new ImportContactsFromCsvJob($import);
        $job1->handle();

        $initialContactCount = Contact::where('vault_id', $vault->id)->count();
        $this->assertEquals(2, $initialContactCount);

        // Simulate Laravel queue retry after timeout/worker restart
        $job2 = new ImportContactsFromCsvJob($import);
        $job2->handle();

        // Assert that retry did NOT create duplicate contacts
        $retryContactCount = Contact::where('vault_id', $vault->id)->count();
        $this->assertEquals(2, $retryContactCount);
    }
}
