# Asynchronous CSV Contact Import Architecture & Setup

This document provides complete technical documentation for the redesigned CSV contact import pipeline in Monica.

---

## 1. Setup Instructions

### Environment Configuration (Docker Sail / WSL 2)
1. **Configure Environment Variables**:
   ```bash
   cp .env.example .env
   ```
2. **Configure Host Ports in `.env`**:
   ```ini
   APP_PORT=8080
   VITE_PORT=5174
   FORWARD_REDIS_PORT=6380
   FORWARD_DB_PORT=3307
   ```
3. **Start Docker Sail Containers**:
   ```bash
   ./vendor/bin/sail up -d
   ```
4. **Run Database Migrations**:
   ```bash
   ./vendor/bin/sail artisan migrate
   ```
5. **Start Queue Worker**:
   ```bash
   ./vendor/bin/sail artisan queue:work
   ```

---

## 2. Existing-Flow Analysis

### Original Synchronous Flow & Pain Points
Previously, Monica processed CSV imports synchronously inside the HTTP request lifecycle:
- **Execution Timeouts & OOM Crashes**: Large files (e.g. 5,000+ rows) exceeded PHP script execution limits (`max_execution_time`) and memory limits (`memory_limit`), crashing worker processes.
- **Single Point of Failure**: A single invalid row or validation throw aborted the entire upload, leaving zero contacts imported and giving no diagnostic feedback to the user.
- **Lack of Visibility**: Users had no progress indicator, status polling mechanism, or line-by-line failure breakdown.

### Reused Components
- **`CreateContact` Service** (`App\Domains\Contact\ManageContact\Services\CreateContact`): Reused directly to enforce validation, generate activity feed items, and create contact records in the database.
- **`ApiController`** (`App\Http\Controllers\ApiController`): Extended by `ImportController` for Sanctum authentication and standardized API response formats.
- **`Account`, `User`, `Vault` Models**: Reused for account ownership, multi-tenant isolation, and vault authorization.

---

## 3. Implementation Approach

### Key Architectural Improvements
1. **Asynchronous HTTP API (`POST /api/import`)**: Validates the upload, saves the file to `storage/app/imports`, creates an `import_jobs` record in `pending` status, dispatches `ImportContactsFromCsvJob`, and returns an instant `201 Created` HTTP response.
2. **Memory-Safe Incremental Streaming**: Reads CSV files line-by-line via `fopen()` / `fgetcsv()` stream handles instead of loading the entire file into array memory.
3. **Chunked Processing (50 Rows)**: Buffers rows into 50-row chunks (`CHUNK_SIZE = 50`) and commits database progress counters after each chunk.
4. **Real-Time Progress Tracking (`GET /api/import/{id}`)**: Computes `progress_pct` dynamically using `processed_rows` and `total_rows` stored on `import_jobs` without querying `contacts`.
5. **Per-Row Error Isolation**: Evaluates each row inside a `try/catch` block. Invalid rows increment `failed_rows` and record error details (`{"row": N, "errors": [...]}`) in the `errors` JSON column, allowing valid rows to continue.
6. **In-Flight Cancellation (`POST /api/import/{id}/cancel` & `DELETE /api/import/{id}`)**: Transitions status to `cancelled` and signals the background job to abort remaining rows immediately.
7. **Downloadable Rejected Rows CSV (`GET /api/import/{id}/failed-rows`)**: Streams a generated CSV file containing all rejected rows prepended with `row_number` and `error_reason` columns.

---

## 4. Assumptions and Limitations

- **Queue Listener Required**: Assumes a queue worker (`php artisan queue:work` or Sail container) is running in production.
- **Header Requirement**: Assumes the CSV file contains a header row. Column headers are normalized and case-insensitive (`first_name`, `last_name`, `email`, `nickname`, etc.).
- **Minimum Identifier**: Requires at least one name field (`first_name`, `last_name`, or `nickname`) for a valid row.
- **File Storage**: Uploaded files are stored in non-public `storage/app/imports`.

---

## 5. Retry-Safety & Idempotency Strategy

### Mid-Execution Crash Analysis
If a queue worker process crashes (e.g. timeout, container restart, OOM) right after inserting a contact for row $N$, but before writing the `import_jobs` progress update, the database contains the created contact while `processed_rows` remains at $N-1$. When the queue manager retries the job, re-processing row $N$ without idempotency protections would invoke `CreateContact` again, creating **duplicate contacts**.

### Prevention Strategy
1. **Atomic DB Transactions per Chunk**: Each ~50-row chunk execution and its corresponding `import_jobs` progress counter update are wrapped inside a single atomic database transaction (`DB::transaction`). If a worker crashes mid-chunk, uncommitted contact creations roll back along with progress counters, keeping database state perfectly in sync.
2. **Row-Level Idempotency Check**: Before calling `(new CreateContact)->execute()`, the job queries the target vault (`Contact::where('vault_id', $vaultId)->where(...)`) to verify if a contact matching the row identity (`first_name`, `last_name`, `nickname`) already exists. If found, creation is safely skipped and counted as successful, making job retries completely idempotent.

---

## 6. Technical Questions & Answers

### Q1: What could happen if the job crashes after creating a contact but before updating the progress counter?
> **Answer**: Without idempotency protections, the created contact would exist in the database while `processed_rows` would reflect the previous state. When Laravel retries the job, re-evaluating the row would create duplicate contact records.

### Q2: How does your solution reduce or prevent duplicate processing?
> **Answer**: We combine **Atomic DB Transactions per Chunk** (ensuring contact creation and counter updates commit together) with **Row-Level Idempotency Lookups** (checking the target vault for pre-existing contacts matching row attributes before executing `CreateContact`).

### Q3: Why store row-level errors in a JSON column vs an `import_errors` table?
> **Answer**: Storing row-level errors directly as a structured JSON column (`errors`) on the `import_jobs` table avoids N+1 database INSERT operations during batch execution and eliminates multi-table `JOIN` overhead when clients poll `/api/import` progress, allowing the API to fetch status, progress metrics, and error details in a single fast query.

---

## 7. Automated Testing Instructions

All automated tests covering Import Initiation, Progress Tracking, Per-Row Error Isolation, Retry Safety, Import Cancellation, and Downloadable Failed Rows can be executed via a single command:

### Run All Import Tests (Docker Sail / WSL):
```bash
./vendor/bin/sail test --filter=Import
```

### Run Specific Test Classes:
- **API Feature Tests**:
  ```bash
  ./vendor/bin/sail test tests/Feature/Api/ImportControllerTest.php
  ```
- **Background Job Unit Tests**:
  ```bash
  ./vendor/bin/sail test tests/Unit/Jobs/ImportContactsFromCsvJobTest.php
  ```

### Native PHP / Artisan Alternative:
```bash
php artisan test --filter=Import
```
