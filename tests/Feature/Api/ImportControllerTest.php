<?php

namespace Tests\Feature\Api;

use App\Domains\Contact\ManageContact\Jobs\ImportContactsFromCsvJob;
use App\Models\Account;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_import_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/import');

        $response->assertStatus(401);
    }

    public function test_import_endpoint_validates_file_upload(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/import', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_import_initiation_creates_pending_record_and_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $vault = Vault::factory()->create(['account_id' => $account->id]);
        $userContact = Contact::factory()->create(['vault_id' => $vault->id]);
        $user->vaults()->attach($vault->id, [
            'permission' => 1,
            'contact_id' => $userContact->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $csvContent = "first_name,last_name\nJohn,Doe\nJane,Smith";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

        $response = $this->postJson('/api/import', [
            'file' => $file,
            'vault_id' => $vault->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'filename',
                    'total_rows',
                    'processed_rows',
                    'failed_rows',
                    'status',
                    'created_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'filename' => 'contacts.csv',
                    'total_rows' => 0,
                    'processed_rows' => 0,
                    'failed_rows' => 0,
                    'status' => 'pending',
                ],
            ]);

        $importId = $response->json('data.id');

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importId,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'filename' => 'contacts.csv',
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
        ]);

        Queue::assertPushed(ImportContactsFromCsvJob::class, function ($job) use ($importId) {
            return $job->import->id === $importId;
        });
    }

    public function test_show_endpoint_returns_import_progress(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $vault = Vault::factory()->create(['account_id' => $account->id]);

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'filename' => 'contacts.csv',
            'file_path' => 'imports/contacts.csv',
            'status' => ImportJob::STATUS_PROCESSING,
            'total_rows' => 500,
            'processed_rows' => 320,
            'successful_rows' => 318,
            'failed_rows' => 2,
            'started_at' => Carbon::now()->subMinutes(2),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/import/{$import->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $import->id,
                    'filename' => 'contacts.csv',
                    'total_rows' => 500,
                    'processed_rows' => 320,
                    'failed_rows' => 2,
                    'status' => 'processing',
                    'progress_pct' => 64,
                    'completed_at' => null,
                ],
            ]);
    }

    public function test_show_endpoint_enforces_account_ownership(): void
    {
        $account1 = Account::factory()->create();
        $user1 = User::factory()->create(['account_id' => $account1->id]);

        $account2 = Account::factory()->create();
        $user2 = User::factory()->create(['account_id' => $account2->id]);

        $import = ImportJob::create([
            'account_id' => $account1->id,
            'user_id' => $user1->id,
            'filename' => 'contacts.csv',
            'file_path' => 'imports/contacts.csv',
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 100,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        // Acting as user from account 2 trying to access account 1's import
        Sanctum::actingAs($user2, ['*']);

        $response = $this->getJson("/api/import/{$import->id}");

        $response->assertStatus(404);
    }

    public function test_cancel_endpoint_cancels_active_import(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'filename' => 'contacts.csv',
            'file_path' => 'imports/contacts.csv',
            'status' => ImportJob::STATUS_PROCESSING,
            'total_rows' => 100,
            'processed_rows' => 20,
            'successful_rows' => 20,
            'failed_rows' => 0,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/import/{$import->id}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $import->id,
                    'status' => 'cancelled',
                ],
            ]);

        $this->assertDatabaseHas('import_jobs', [
            'id' => $import->id,
            'status' => ImportJob::STATUS_CANCELLED,
            'failure_message' => 'Import was cancelled by user.',
        ]);
    }

    public function test_cannot_cancel_already_completed_import(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'filename' => 'contacts.csv',
            'file_path' => 'imports/contacts.csv',
            'status' => ImportJob::STATUS_COMPLETED,
            'total_rows' => 10,
            'processed_rows' => 10,
            'successful_rows' => 10,
            'failed_rows' => 0,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/import/{$import->id}/cancel");

        $response->assertStatus(422)
            ->assertJson([
                'message' => "Cannot cancel an import that has already reached state 'completed'.",
            ]);
    }

    public function test_download_failed_rows_returns_csv_of_rejected_rows(): void
    {
        Storage::fake('local');

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $csvContent = "first_name,last_name,email\nValid,User,valid@example.com\n,,invalid-email";
        $filePath = 'imports/test_failed_download.csv';
        Storage::put($filePath, $csvContent);

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'filename' => 'test_failed_download.csv',
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_COMPLETED,
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 1,
            'failed_rows' => 1,
            'errors' => [
                [
                    'row' => 3,
                    'errors' => ['Missing required name: At least one of first_name, last_name, or nickname is required.'],
                ],
            ],
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/import/{$import->id}/failed-rows");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('row_number,error_reason,first_name,last_name,email', $content);
        $this->assertStringContainsString('3,Missing required name', $content);
        $this->assertStringContainsString('invalid-email', $content);
    }

    public function test_download_failed_rows_returns_404_when_no_failed_rows_exist(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $import = ImportJob::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'filename' => 'successful_import.csv',
            'file_path' => 'imports/successful.csv',
            'status' => ImportJob::STATUS_COMPLETED,
            'total_rows' => 5,
            'processed_rows' => 5,
            'successful_rows' => 5,
            'failed_rows' => 0,
            'errors' => [],
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/import/{$import->id}/failed-rows");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'No failed rows exist for this import job.',
            ]);
    }
}
