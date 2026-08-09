## Asynchronous CSV Import Architecture

### 1. How the Import Flow Works

- **Initiation (`POST /api/import`)**: An authenticated user uploads a CSV file. The controller validates the file type, saves the file in non-public storage (`storage/app/imports`), creates an `import_jobs` database tracking record with status `pending`, and dispatches the `ImportContactsFromCsvJob` to the background queue.
- **Instant HTTP Response**: The HTTP endpoint immediately returns a `201 Created` JSON payload with the initial import state (`status: "pending"`, `total_rows: 0`, `processed_rows: 0`, `failed_rows: 0`) without processing contacts in the request lifecycle.
- **Background Queue Processing**: The `ImportContactsFromCsvJob` runs in the background. It reads the CSV line-by-line using `fgetcsv()` (minimizing memory overhead), updates progress counters in real time, and executes `CreateContact` for each row.
- **Fault Tolerance**: Each row is evaluated within an isolated `try/catch` block. If a row is malformed or fails validation, error details and row numbers are logged to the `errors` array, `failed_rows` is incremented, and processing continues for remaining rows without failing the batch.

### 2. Components Reused or Modified

- **`App\Domains\Contact\ManageContact\Services\CreateContact`** (Reused): Used directly to validate contact attributes, enforce domain rules, insert `contacts` records, and generate `ContactFeedItem` feed entries.
- **`App\Http\Controllers\ApiController`** (Reused): Extended by `ImportController` to inherit Sanctum authentication middleware, query error handlers, and standard JSON responses.
- **`routes/api.php`** (Modified): Registered `POST /api/import` and `GET /api/import/{id}` under the `auth:sanctum` middleware group.
- **`App\Models\Account`, `User`, `Vault`** (Reused): Referenced for authorization, ownership verification, and default vault resolution.

### 3. Important Assumptions Made

- **Background Queue Worker**: Assumed a queue listener (e.g. `php artisan queue:work` or Sail `laravel.queue` container) is active to execute queued jobs.
- **CSV Structure & Headers**: Assumed CSV files include a header row. Column headers are normalized and case-insensitive (supports `first_name`/`firstname`/`First Name`, `last_name`/`lastname`, `middle_name`, `nickname`, `maiden_name`, `prefix`, `suffix`).
- **Minimum Contact Requirements**: Assumed at least one name identifier (`first_name`, `last_name`, or `nickname`) must be present for a valid row. Rows missing all name fields or breaking domain rules log an error and continue.

### 4. Data Model Design Decisions (`import_jobs` Table)

- **Table & Model Naming (`import_jobs` / `ImportJob`)**: Created `import_jobs` table and `App\Models\ImportJob` model to follow Monica's standard Laravel table naming conventions.
- **Account & User Scoping (`account_id`, `user_id`)**: Includes `account_id` foreign key for strict multi-tenant account isolation, `user_id` to track the initiating user, and `vault_id` for target vault scoping.
- **Dual Error Tracking & Storage Justification (`failure_message` & `errors`)**:
  - **`failure_message`** (text, nullable): Records high-level system failures (e.g. unreadable file, empty file stream, zero successful imports).
  - **`errors`** (JSON, nullable): Stores line-by-line validation/execution failures (`[ { "row": 3, "errors": ["Invalid email format"] } ]`).
  - **Justification**: Storing row-level errors directly as a structured JSON column on `import_jobs` rather than a separate table avoids N+1 insert operations during background processing and eliminates multi-table JOIN overhead when clients poll `/api/import` progress.
- **Completion Status Rules**:
  - If **at least one** contact is created successfully (`successful_rows > 0`), the import completes with status `completed` (even if some rows failed).
  - If **no contact** is imported successfully (`successful_rows === 0` and `total_rows > 0`), the overall status is set to `failed` with a descriptive `failure_message`.

### 5. Retry Safety & Idempotency Strategy

#### What Happens During a Mid-Execution Worker Crash?

If a worker process crashes (e.g. timeout, container restart, worker crash) right after inserting a contact for row $N$, but before the `import_jobs` counter update is written, the database contains the created contact while `processed_rows` remains at $N-1$. When Laravel retries the queued job, re-processing row $N$ without idempotency protections would invoke `CreateContact` again, resulting in **duplicate contacts**.

#### How Our Solution Prevents Duplicates:

1. **Atomic DB Transactions per Chunk**: Each ~50-row chunk execution and its corresponding `import_jobs` progress counter update are wrapped inside a single atomic database transaction (`DB::transaction`). If a worker crashes mid-chunk, all uncommitted contact creations roll back along with the progress counter, ensuring no partial or out-of-sync contacts remain.
2. **Row-Level Idempotency Check**: Before calling `(new CreateContact)->execute()`, the job queries the target vault (`Contact::where('vault_id', $vaultId)->where(...)`) to verify if a contact matching the exact row identity (`first_name`, `last_name`, `nickname`) already exists. If found (e.g. from a prior committed attempt), creation is safely skipped and counted as successful, making job retries completely idempotent.

---

### 6. Automated Testing Instructions

All automated tests covering Import Initiation, Progress Tracking, Per-Row Error Isolation, and Retry Safety can be executed via a single command:

#### Using Laravel Sail (Docker/WSL):

```bash
./vendor/bin/sail test --filter=Import
```

#### Using Native PHP / Artisan:

```bash
php artisan test --filter=Import
```

#### Test Suite Overview:

1. **Import Initiation (`tests/Feature/Api/ImportControllerTest.php`)**
   - `test_import_endpoint_requires_authentication`: Verifies authentication requirement (`401`).
   - `test_import_endpoint_validates_file_upload`: Verifies file validation (`422`).
   - `test_import_initiation_creates_pending_record_and_dispatches_job`: Verifies `import_jobs` record created with status `pending`, job dispatched, and `201 Created` returned immediately without synchronous contact creation.

2. **Progress Tracking (`tests/Feature/Api/ImportControllerTest.php`)**
   - `test_show_endpoint_returns_import_progress`: Verifies `progress_pct`, status, and counters returned without querying `contacts` table.
   - `test_show_endpoint_enforces_account_ownership`: Verifies account isolation (`404` for cross-account access).

3. **Background Processing & Per-Row Error Isolation (`tests/Unit/Jobs/ImportContactsFromCsvJobTest.php`)**
   - `test_job_processes_csv_in_chunks_and_updates_status_to_completed`: Verifies 50-row chunk updates, streaming, and `completed` status.
   - `test_row_error_isolation_keeps_status_completed_on_partial_success`: Verifies invalid rows are recorded in `errors` JSON array while valid rows continue importing.
   - `test_job_sets_status_to_failed_if_no_contact_imported_successfully`: Verifies status set to `failed` if 0 contacts succeed.

4. **Retry & Duplicate Protection (`tests/Unit/Jobs/ImportContactsFromCsvJobTest.php`)**
   - `test_job_retry_is_idempotent_and_does_not_create_duplicate_contacts`: Simulates queue job retry after partial execution and verifies zero duplicate contacts are created.

## Asynchronous CSV Import Architecture

For full implementation details, see [TASK_DOCUMENTATION.md](file:///d:/pswrk/monica/TASK_DOCUMENTATION.md).

### Automated Testing Instructions

All automated tests covering Import Initiation, Progress Tracking, Per-Row Error Isolation, and Retry Safety can be executed via a single command:

```bash
# Using Sail (Docker / WSL):
./vendor/bin/sail test --filter=Import

# Or using native PHP / Artisan:
php artisan test --filter=Import
```
