# ADMS Gateway

ADMS Gateway captures attendance from ZKTeco devices that use the ADMS/iClock PUSH protocol, preserves the original uploads, and exposes normalized punches through an administration panel and an attendance API.

The application separates device communication from HR business rules. It collects and serves attendance evidence; an external HR or ERP system can calculate shifts, worked hours, overtime, leave, and payroll from those records.

**Current status:** device ingestion and the operator panel are implemented. The BioTime API provides general-token authentication and transaction reads matching a captured BioTime installation's successful transaction payloads. JWT authentication and complete BioTime 8.5/9.0 compatibility remain in progress.

## Contents

- [Features](#features)
- [Architecture and data model](#architecture-and-data-model)
- [Technology stack](#technology-stack)
- [Local installation](#local-installation)
- [Running the application](#running-the-application)
- [Registering a device](#registering-a-device)
- [Device protocol endpoints](#device-protocol-endpoints)
- [Historical attendance requests](#historical-attendance-requests)
- [BioTime attendance API](#biotime-attendance-api)
- [API documentation](#api-documentation)
- [Operations and recovery](#operations-and-recovery)
- [Security and deployment](#security-and-deployment)
- [Testing and code quality](#testing-and-code-quality)
- [Project structure and design documents](#project-structure-and-design-documents)
- [Limitations and remaining work](#limitations-and-remaining-work)

## Features

### Device ingestion

- Register devices by serial number and associate them with a company.
- Accept supported `ATTLOG` uploads through the device's normal PUSH protocol.
- Store the original payload, checksum, source IP, receipt time, device timezone, and parser profile before acknowledging an attendance upload.
- Retain separate receipts for repeated uploads while deduplicating normalized punches.
- Process uploads through Laravel queue jobs with processing leases, checkpoints, and bounded database batches.
- Track inserted, duplicate, and rejected rows, plus processing errors.
- Track device liveness, command polling, and the last reported attendance stamp.
- Optionally restrict each device to an expected source IP.

### Operator administration

The Filament panel includes these resources:

| Resource | Purpose |
| --- | --- |
| Companies | Manage company identity, timezone, and active state |
| Devices | Manage serial numbers, names, location, timezone, availability, and expected IP; request attendance from selected devices |
| Employees | Manage company-specific employee numbers and names |
| Device Employees | Associate a device PIN with an employee in the same company |
| Attendance Punches | Browse captured punches, employee/device associations, timestamps, and raw codes |
| Attendance Uploads | Inspect receipt status and processing counts; retry failed or partially rejected uploads |
| Device Commands | Inspect attendance-query requests, delivery/result state, and wake-up results |

The panel supports English and Arabic through its language switcher. Filament Shield is installed for role administration. Review domain permissions and custom action authorization before treating the panel as a production tenancy boundary.

### Attendance and integration

- Preserve employee numbers and PINs as strings, including leading zeros.
- Preserve device-local punch timestamps alongside resolved UTC timestamps and timezone information.
- Automatically create placeholder employees and device mappings when processing a previously unseen PIN.
- Expose company-scoped transaction list and detail endpoints.
- Filter by employee code, terminal serial/name, and local datetime range.
- Return BioTime-style pagination and transaction field names instead of raw Eloquent attributes.
- Keep API service-client credentials separate from operator login credentials.
- Generate interactive API documentation with Scramble.

## Architecture and data model

An attendance upload follows this sequence:

1. The device calls an `/iclock/*` endpoint.
2. The controller checks the device registration, enabled state, company state, and optional source-IP restriction.
3. The application commits an attendance upload receipt containing the original bytes.
4. It dispatches `ProcessAttendanceUpload` and returns a plain-text acknowledgement.
5. A queue worker parses the retained payload and saves normalized punches in bounded batches.
6. Operators browse the results through Filament, and external systems retrieve them through `/iclock/api/transactions/`.

The queue worker is essential when `QUEUE_CONNECTION=database`. An accepted upload may remain pending until a worker processes it. Dispatch failures leave the committed receipt available for recovery.

| Model | Stored responsibility |
| --- | --- |
| `Company` | Ownership and default organizational timezone |
| `Device` | Registered terminal, protocol profile, IP restrictions, liveness, and stamps |
| `AttendanceUpload` | Durable receipt, original bytes, processing state, counters, and checkpoints |
| `AttendancePunch` | Normalized source punch, raw fields/codes, timestamps, identity hash, and optional BioTime metadata |
| `Employee` | Company-specific employee identity and display name |
| `DeviceEmployee` | Device PIN-to-employee mapping |
| `DeviceCommand` | Requested attendance query, wire ID/payload, delivery state, and result evidence |
| `BioTimeClient` | Company-scoped API account, hashed password, encrypted general token, and indexed token digest |

Punch identity is protected by a database uniqueness constraint on device and deduplication hash. Replayed uploads can be retained without creating duplicate punches. Identical physical punches with identical transmitted fields and no source event ID cannot be distinguished by the gateway.

Supplemental `biotime_metadata` stores available GPS, area, temperature, and mask values separately from the original device fields. The current ATTLOG parser does not infer those measurements from undocumented extra columns.

## Technology stack

The installed baseline at the time of writing is:

| Component | Version / role |
| --- | --- |
| PHP | Project environment: 8.5; Composer declares `^8.3` |
| Laravel | 13.34.0 |
| Filament | 5.9.0 |
| Livewire | 4.4.7 |
| Filament Shield | 4.3.1 |
| Scramble | 0.13.47 |
| Pest | 5.3.0 |
| SQLite | Default local database |
| Laravel database queue | Default asynchronous processing backend |
| Tailwind CSS / Vite | Admin theme and frontend assets |
| Pint / Larastan | Formatting and static analysis |

Use the committed lockfiles when installing dependencies. The installed Vite package requires Node.js `^20.19.0` or `>=22.12.0`. PHP needs the extensions required by the installed Composer packages and the selected database driver.

## Local installation

### 1. Install dependencies and initialize configuration

Run these commands from the repository root on a fresh local checkout:

```bash
composer install
php -r 'if (!file_exists(".env")) { copy(".env.example", ".env"); }'
php artisan key:generate --no-interaction
php -r 'if (!file_exists("database/database.sqlite")) { touch("database/database.sqlite"); }'
php artisan migrate --no-interaction
npm ci
npm run build
```

Generate the application key only for a fresh installation. Preserve the existing `APP_KEY` when upgrading an installation: encrypted API tokens depend on that key.

The repository also provides `composer run setup`, which installs dependencies, creates `.env` if absent, generates a key, runs migrations, and builds assets. It uses forced migrations and should be reviewed before running against an existing environment.

### 2. Configure `.env`

The local defaults use SQLite and database-backed queues, sessions, and cache:

```dotenv
APP_NAME="ADMS Gateway"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
ADMS_DEVICE_ONLINE_WINDOW_MINUTES=5
```

The application timezone is currently configured as UTC in `config/app.php`. Company and device timezones are configured independently in the panel. Device-local attendance interpretation uses the timezone saved with the upload.

The optional `ADMS_DEVICE_ONLINE_WINDOW_MINUTES` setting controls the device's online indicator. It is a recent-contact threshold, not proof that a terminal can currently receive a command.

### 3. Create an operator account

Use Filament's interactive account command so the password is entered through its prompt:

```bash
php artisan make:filament-user --panel=admin
```

If the operator needs the Shield super-admin role, assign it to the actual user ID:

```bash
php artisan shield:super-admin --user=<user-id> --panel=admin --no-interaction
```

These commands are setup actions; replace placeholders with your own account details. The interactive commands in this README intentionally require user input.

**Development seeder:** `DatabaseSeeder` creates a test company, a particular test device, and an operator with a predictable development password. Seeding is optional and is not part of the recommended installation sequence. Do not use this seeder as production provisioning; review its code before running it.

## Running the application

The repository provides a combined development workflow:

```bash
composer run dev
```

For explicit control, run the application server, queue worker, scheduler, and frontend watcher in separate terminals:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

```bash
php artisan queue:work --sleep=1 --tries=3 --no-interaction
```

```bash
php artisan schedule:work --no-interaction
```

```bash
npm run dev
```

Binding the development server to `0.0.0.0` allows terminals on the local network to reach it. Configure the terminal with a network-reachable gateway address; a terminal's own `localhost` is not the computer running Laravel.

The Laravel development server is for local development. A deployed installation needs an appropriate web server, continuously supervised queue workers, and a running scheduler.

Local pages:

- [Operator panel](http://localhost:8000/admin)
- [Interactive API documentation](http://localhost:8000/docs/api)
- [OpenAPI JSON](http://localhost:8000/docs/api.json)

Adjust these addresses to the host and port configured for your installation.

## Registering a device

1. Create or select an active company in the panel.
2. Register the terminal's exact serial number under that company.
3. Set the device timezone to the timezone used by its local clock.
4. Select the supported protocol profile; the implemented baseline is `push-2.4-attlog-v1`.
5. Enable the device and, if appropriate, configure its expected IP address.
6. Configure the terminal's ADMS server address and port to reach this gateway.
7. Confirm initialization or polling updates the device's last-seen information.
8. Generate a test attendance punch and inspect both the upload receipt and normalized punch.

Do not rely on the historical test serial as a universal device identifier. Every terminal needs its own registration. Receiving attendance does not require pre-creating every employee: the worker can create placeholder employees for new PINs.

## Device protocol endpoints

These endpoints serve terminal traffic and return protocol-oriented plain text.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/iclock/cdata` | Device initialization and gateway options |
| POST | `/iclock/cdata` | Attendance upload with `table=ATTLOG`; bounded `OPTIONS` uploads are acknowledged |
| GET | `/iclock/getrequest` | Device command polling |
| POST | `/iclock/devicecmd` | Attendance-query command results |
| GET | `/iclock/ping` | Device liveness |

Devices normally identify themselves with `SN`. The initialization response includes the device's retained `ATTLOGStamp`, or `None` when no stamp has been retained. Attendance stamps are opaque device values, not a guaranteed chronological cursor.

The current controller limits attendance bodies to 1 MiB and command-result bodies to 16 KiB. Unsupported tables and encoded/chunked uploads are rejected by the implemented boundary. `OPTIONS` acknowledgement does not mean the options body is retained as an attendance receipt.

Successful attendance acceptance returns `OK` after the receipt transaction commits. It does not mean every row has already been parsed or that all rows are valid.

When a command result omits `SN`, the current resolver accepts a source IP only if it corresponds to exactly one device with that last-recorded address. Shared-address ambiguity is rejected; an optional expected-IP restriction still applies.

## Historical attendance requests

The Devices resource provides bulk actions for requesting stored attendance and force-resending attendance over a selected datetime range.

The request is stored as a `DeviceCommand`, and the terminal receives it on command polling. Both current actions emit a bounded attendance query of the form:

```text
DATA QUERY ATTLOG StartTime=<device-local-start>\tEndTime=<device-local-end>
```

The displayed `\t` represents a tab on the wire. The force-resend action records a distinct request type; it does not currently introduce a different command serializer, clear terminal logs, or bypass the active-request restriction.

The gateway attempts an `R-CMD` UDP wake-up datagram to the terminal's last-seen address on port 4374. A failed wake-up is recorded without discarding the queued attendance query. Delivery still depends on terminal polling and network reachability.

Only enabled devices in active companies can receive these requests. A device with an unexpired active attendance request cannot receive another one. Command acknowledgements, received upload batches, and proof that the terminal returned its entire history are separate facts.

## BioTime attendance API

### Compatibility status

The transaction profile is based on the supplied HR connector and a read-only review of its `biotime_logs` table. The reviewed evidence contained 754 successful transaction responses, 35,766 transaction entries, and 89 successful JWT login responses.

All captured transaction entries used the same 20 keys. Sanitized fixtures cover all 20 observed state/verification/mask/work-code combinations. Gateway tests compare responses against those fixtures, including field types, nulls, and display labels.

This verifies the captured successful transaction contract. It does not establish universal compatibility with every BioTime release, error response, terminal model, or business feature.

**The existing HR connector uses `/jwt-api-token-auth/` and `Authorization: jwt ...`. Those are not implemented yet.** The current gateway API uses general tokens, so that connector cannot yet be switched over unchanged.

### Provision an API account

Create a client for an existing active company:

```bash
php artisan biotime:client-create erp-client <company-id>
```

The command prompts for a hidden password, requires at least 12 characters, and refuses noninteractive creation. It creates no default credentials. The client is scoped to one company; URL parameters cannot change its ownership scope.

Passwords are hashed. General tokens are generated randomly, encrypted at rest, and looked up through a digest. Passwords, tokens, and token digests are hidden from normal model serialization.

### Authenticate

| Method | Path | Response |
| --- | --- | --- |
| POST | `/api-token-auth/` | JSON object containing `token` |

Request body:

```json
{
  "username": "erp-client",
  "password": "<client-password>"
}
```

Response:

```json
{
  "token": "<general-token>"
}
```

Use this header on transaction requests:

```http
Authorization: Token <general-token>
```

The token is stable for the client and currently has no time-based expiry. Disabling the API client or its company prevents both login and token access. A token-rotation management workflow is not implemented yet.

### Retrieve transactions

| Method | Path | Response |
| --- | --- | --- |
| GET | `/iclock/api/transactions/` | Paginated list |
| GET | `/iclock/api/transactions/{id}/` | Direct transaction object |

Supported filters:

| Parameter | Behavior |
| --- | --- |
| `page` | Positive page number, or `last` |
| `page_size` | 1–1000 rows; current default is 10 |
| `limit` | Legacy page-size alias; `page_size` takes precedence |
| `emp_code` | Exact mapped employee number, falling back to a PIN for an unmapped punch |
| `terminal_sn` | Exact terminal serial number |
| `terminal_alias` | Exact registered terminal name |
| `start_time` | Inclusive lower bound in `YYYY-MM-DD HH:MM:SS` format |
| `end_time` | Inclusive upper bound in the same format |

The date filters compare original device-local timestamps. Results use ascending gateway punch IDs. The observed HR connector explicitly requests `page_size=100`; the gateway's default for an omitted page size is a provisional policy.

Pagination links preserve filters, retain the endpoint's trailing slash, and sort query parameters. A link to the first page omits `page=1`. A company-external transaction ID returns 404.

### Transaction response example

This illustrative record uses synthetic identity, device, area, and timestamp values. Its nonpersonal labels and field structure follow the captured contract; mask and area values assume corresponding metadata is available.

```json
{
  "count": 1,
  "next": null,
  "previous": null,
  "msg": "",
  "code": 0,
  "data": [
    {
      "id": 1,
      "emp": null,
      "emp_code": "00012",
      "first_name": null,
      "last_name": null,
      "department": null,
      "position": null,
      "punch_time": "2026-10-05 08:00:00",
      "punch_state": "1",
      "punch_state_display": "Check Out",
      "verify_type": 4,
      "verify_type_display": "Card",
      "work_code": "0",
      "gps_location": null,
      "area_alias": "Test Area",
      "terminal_sn": "TEST-TERMINAL",
      "temperature": 0,
      "is_mask": "No",
      "terminal_alias": "Test terminal",
      "upload_time": "2026-10-05 08:05:00"
    }
  ]
}
```

The detail endpoint returns the inner transaction object directly, without the pagination envelope.

| Field | Representation |
| --- | --- |
| `id` | Integer gateway punch ID; not an imported BioTime transaction ID |
| `emp` | Null in this captured response profile; not the gateway employee foreign key |
| `emp_code` | String employee number or fallback PIN |
| `first_name`, `last_name`, `department`, `position` | Null in the captured profile |
| `punch_time` | Original device-local datetime string |
| `punch_state` | Raw state string; can be null if missing in the source |
| `punch_state_display` | English label or `Unknown` |
| `verify_type` | Integer when the source verification code is numeric; otherwise null |
| `verify_type_display` | English label or `Unknown` |
| `work_code` | String; missing source values become an empty string |
| `gps_location` | Stored metadata value or null |
| `area_alias` | Stored metadata value, registered device location, or an empty string |
| `terminal_sn`, `terminal_alias` | Registered device serial number and name |
| `temperature` | Stored numeric metadata value, or 0 as the captured no-reading representation |
| `is_mask` | Stored metadata value; absent metadata uses `-` for unknown |
| `upload_time` | Receipt time formatted in the punch's saved timezone |

Captured display mappings:

| State code | Display | Verification code | Display |
| --- | --- | --- | --- |
| `0` | Check In | `1` | Fingerprint |
| `1` | Check Out | `3` | Password |
| `4` | Overtime In | `4` | Card |
| `5` | Overtime Out | `15` | Face |
| `255` | Unknown | Other/unconfirmed | Unknown |

The API's labels are independent of the operator panel language. Missing mask metadata is not inferred from work code, verification method, or attendance state. The current ingestion path does not automatically populate all BioTime metadata; reproducing a particular source record requires that metadata to be retained or supplied explicitly.

### Errors and limits

| Status | Current behavior |
| --- | --- |
| 400 | Invalid login credentials or field validation errors |
| 401 | Missing/invalid general token, disabled client, or inactive company |
| 404 | Missing/company-external record or nonexistent page |
| 405 | Unsupported method, including transaction deletion |
| 429 | Login or read rate limit reached |

Login is limited to 10 requests per minute per source IP. Transaction reads are limited to 120 requests per minute per API client. Authentication failures include a `WWW-Authenticate: Token` challenge.

The logs reviewed contained successful responses only. Exact BioTime error wording, JWT expiry errors, malformed JSON behavior, and version-specific validation behavior still need separate reference evidence.

## API documentation

Scramble serves the interactive reference at `/docs/api` and its OpenAPI document at `/docs/api.json`. Its configured route selection includes `api-token-auth` and `iclock/api`, because the BioTime API does not use the default `/api/*` prefix.

If a documentation change appears stale:

```bash
php artisan config:clear --no-interaction
php artisan scramble:clear --no-interaction
```

Export the specification when needed:

```bash
php artisan scramble:export --path=/tmp/adms-openapi.json --no-interaction
```

Documentation is restricted by Scramble's access middleware. It is available in the local environment; access in other environments requires the `viewApiDocs` authorization gate. A generated OpenAPI document is a useful reference, but the captured-response fixtures remain the verification source for wire compatibility.

## Operations and recovery

### Upload processing

Upload states include `pending`, `processing`, `processed`, `processed_with_errors`, and `failed`. A processed receipt can contain both valid punches and rejected rows. Keep original receipt bytes available for investigation.

The scheduled recovery command runs every minute:

```bash
php artisan app:recover-attendance-uploads --no-interaction
```

It redispatches eligible pending uploads and processing uploads with expired leases. It does not automatically retry every `failed` receipt. Use the Attendance Uploads retry action for failed or partially rejected uploads. Reprocessing resets counters/checkpoints and relies on punch deduplication.

For a deployed scheduler, the normal Laravel cron entry is:

```cron
* * * * * cd /path/to/adms-gateway && php artisan schedule:run --no-interaction >> /dev/null 2>&1
```

### Troubleshooting

| Symptom | Checks |
| --- | --- |
| Device is rejected | Exact serial registration, device enabled state, active company, expected IP, and network routing |
| Uploads remain pending | Queue worker running, correct queue connection, recovery scheduler, and worker exceptions |
| Duplicate counts increase | Device history replay; punches should remain deduplicated while receipts accumulate |
| Historical request remains pending | Terminal polling, last-seen address, UDP reachability, active-request restrictions, and command expiry |
| Wake-up reports an error | Last-seen IP, expected public IP where applicable, network permissions, and port 4374 |
| API returns 401 | `Token` scheme, client token, client active state, and company active state |
| HR connector cannot log in | It may require the still-unimplemented JWT endpoint |
| Documentation is empty or forbidden | Scramble route filters/cache and environment/access gate |
| Vite manifest is missing | Run `npm run build`, or start the frontend watcher for development |

Check application and worker logs for details. Do not put raw attendance payloads or authentication tokens into general diagnostic output.

## Security and deployment

- Device serial allowlisting and optional IP matching are local-network safeguards, not cryptographic terminal authentication.
- Keep device ingress on a trusted network or an explicitly protected network boundary.
- Use HTTPS for operator/API traffic in deployment and disable debug output.
- Retain the application encryption key and database backups together through a secure recovery process.
- Supervise queue workers and run the scheduler continuously.
- Review operator policies and custom Filament actions before offering access to company-specific customers. Company grouping does not by itself provide an operator tenancy boundary.
- Provision production identities explicitly rather than running the development seeder.
- Monitor pending/failed uploads, queue failures, stale devices, command outcomes, and database/storage capacity.

SQLite and the database queue are the current local baseline. The architecture documents discuss later infrastructure choices, but PostgreSQL, Redis, Octane, Horizon, failover, and production throughput guarantees are not implemented merely by this README.

## Testing and code quality

Run the targeted API and device tests:

```bash
php artisan test --compact tests/Feature/BioTimeApiTest.php
php artisan test --compact tests/Feature/Http/Controllers/Iclock/DeviceControllerTest.php
```

Run the complete PHP test suite:

```bash
php artisan test --compact
```

Additional project commands:

```bash
composer run lint
composer run types:check
composer run test
npm run build
```

`composer run test` performs formatting checks and static analysis before running tests. Full-project static analysis has existing findings outside the recently validated API files, so passing targeted tests does not imply that the combined quality command passes.

The response fixtures in `tests/Fixtures/BioTime/transactions.json` contain sanitized source records. Preserve exact keys, labels, nulls, scalar types, and pagination conventions when modifying the adapter. Test missing authentication, company isolation, leading zeros, filters, read-only behavior, and captured response variants.

## Project structure and design documents

| Path | Contents |
| --- | --- |
| `app/Http/Controllers/Iclock` | Device protocol controller |
| `app/Http/Controllers/BioTime` | API authentication and transaction controllers |
| `app/Http/Resources/BioTime` | Captured-contract transaction serialization |
| `app/Http/Middleware` | API authentication, API locale, and panel locale middleware |
| `app/Services/Adms` | Upload acceptance, parsing, identity, commands, and UDP wake-up |
| `app/Jobs/ProcessAttendanceUpload.php` | Queued normalization and checkpointing |
| `app/Models` | Company/device/attendance/employee/API-client models |
| `app/Filament/Resources` | Operator administration |
| `app/Console/Commands` | Upload recovery and API-client provisioning |
| `routes/web.php` | Operator-related and device routes |
| `routes/biotime.php` | Exact API route paths |
| `routes/console.php` | Recovery scheduling |
| `database/migrations` | Domain and framework schema |
| `tests/Feature` | HTTP, API, device, and administration coverage |
| `tests/Fixtures/BioTime` | Sanitized captured response variants |

Detailed plans:

- [Attendance MVP implementation plan](Docs/MVP_MVC_PLAN.md)
- [Gateway architecture](Docs/ADMS_GATEWAY_ARCHITECTURE.md)
- [Phase 2 BioTime API plan](Docs/PHASE_2_BIOTIME_API_PLAN.md)

These documents include design targets and future work. Consult the implementation and the explicit status notes here before assuming a planned feature is available.

## Limitations and remaining work

- Implement JWT login and acceptance of lowercase `jwt` authorization, matching the observed 24-hour access-token claims and expiry behavior.
- Verify error contracts and version differences against the specific BioTime builds being replaced.
- Add personnel, department, position, area, terminal discovery, export, and other endpoints only when required and independently verified.
- Implement API-client lifecycle management, including token rotation.
- Establish ID correspondence when migrating a connector that already deduplicates by original BioTime IDs; gateway IDs are not automatically those IDs.
- Validate wider terminal/firmware compatibility with real device captures.
- Complete production authorization, deployment, load, and recovery validation.

The application does not currently provide BioTime's payroll engine, shift calculations, employee self-service, biometric template management, arbitrary terminal commands, or a complete replica of its REST API.
