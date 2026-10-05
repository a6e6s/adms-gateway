# ADMS Gateway — Attendance MVP MVC Implementation Plan

**Date:** 5 October 2026  
**Status:** Planned; application implementation has not started  
**Scope:** Company/device administration, live attendance capture, historical attendance requests, and attendance browsing.

## 1. Goal and confirmed device behavior

Replace the standalone Python probe with Laravel endpoints and use the existing Filament admin panel to manage companies, devices, optional employees, attendance, uploads, and read-only attendance queries.

The connected device is `A39N203960051`, reporting PUSH version `2.4.0` and user agent `iClock Proxy/1.09`. Captures establish:

- Initialization uses `GET /iclock/cdata` with `SN`, `options=all`, and firmware metadata.
- Attendance arrives through `POST /iclock/cdata?SN=...&table=ATTLOG&Stamp=9999`.
- Live punches and older records were received. The pasted sample contains 372 historical rows in six batches of 62, plus two recent punches.
- Historical timestamps shown range from 19 August 2024 to 12 September 2024. This does not prove the complete device history was downloaded.
- Historical records arrived during the read-only attendance-query experiment. Retain command-result evidence before treating command completion as certified.
- The upload stamp is constant in the sample and cannot identify a unique punch or provide a reliable chronological checkpoint.

Example captured row:

```text
12\t2026-10-05 11:49:53\t1\t1\t\t0\t0\t\n
```

The displayed escapes represent actual tab/newline bytes. Preserve eight split fields, including the trailing empty field: PIN, local datetime, raw status, raw verification method, empty work-code field, two additional fields, and a trailing empty field. Interpret code meanings only after confirmation for this device.

## 2. Technical baseline and MVP boundaries

- Use installed Laravel 13.34.0, PHP 8.5, Filament 5.9.0, Livewire 4.4.7, and Pest 5.3.0.
- Reuse the existing User model, admin panel, and Filament Shield authorization setup.
- Start the local MVP with the existing SQLite database and Laravel database queue. Do not add Redis, Octane, Horizon, or other dependencies without approval.
- Keep upload normalization asynchronous; the committed upload remains the recovery source if queue dispatch fails.
- Benchmark the local MVP, but do not claim production sub-20ms latency, high availability, or unconditional zero loss from a development server and SQLite.
- Prepare the design for a later PostgreSQL/Redis deployment without implementing that infrastructure in this milestone.
- Initial administration is for authorized gateway operators across companies. Company records organize ownership; company-specific customer logins and self-service tenancy are a later scope requiring explicit user/company membership and access rules.
- Device serial allowlisting is a local-LAN safeguard, not strong authentication. Optionally restrict the expected source IP; do not expose this MVP's device ingress directly to the public internet.

Excluded: biometric templates/photos, user synchronization to terminals, door/reboot/log-clear commands, shifts, leave, payroll, attendance calculations, webhooks, and universal OEM compatibility.

## 3. Models — seven new domain models

### 3.1 Company

**Table:** `companies`

- Fields: `id`, `name`, optional unique `code`, `timezone` (IANA identifier), `is_active`, timestamps.
- Relationships: has many devices and employees.
- Behavior: supplies the default timezone when provisioning a device.
- Retire rather than cascade-delete a company with captured history. Company deactivation prevents new device acceptance and command creation/delivery.

### 3.2 Device

**Table:** `devices`

- Fields: `id`, `company_id`, globally unique `serial_number`, `name`, optional `location`, optional `expected_ip`, explicit `timezone`, `is_enabled`, `protocol_profile`, optional firmware metadata, `last_seen_at`, timestamps.
- Relationships: belongs to company; has many uploads, punches, commands, and DeviceEmployee mappings.
- Initial profile: the observed PUSH 2.4.0 attendance format.
- Keep device/company association immutable once history exists. Moving a device requires a later explicit migration workflow.
- Treat timezone changes as future interpretation changes; each accepted upload snapshots its effective timezone.
- Only approved, enabled devices under active companies may upload or receive commands.

### 3.3 AttendanceUpload

**Table:** `attendance_uploads`

- Fields: `id`, `company_id`, `device_id`, raw payload, payload checksum, byte count, raw stamp, received timestamp, source IP, effective timezone, parser version, status, processing attempt count, processing lease token/expiry, last dispatch time, processing checkpoint, total/inserted/duplicate/rejected row counts, bounded error details, processed timestamp, timestamps.
- Statuses: `Pending`, `Processing`, `Processed`, `ProcessedWithErrors`, `Failed`.
- Relationships: belongs to company/device; has many punches whose first acceptance came from this upload.
- The raw payload is immutable and private. Checksum is for integrity, not a unique request key.
- Retain repeated uploads as separate receipts; normalize repeated punches idempotently.
- Store malformed-row offsets/reasons in structured bounded error metadata for this MVP. Keep all original bytes available for investigation/reprocessing.
- No deletion of pending/failed uploads through normal administration.

### 3.4 AttendancePunch

**Table:** `attendance_punches`

- Fields: `id`, `company_id`, `device_id`, originating `attendance_upload_id`, optional `device_employee_id`, `pin` as string, original local timestamp, nullable resolved UTC timestamp, timezone snapshot, time-quality flag, raw status code, raw verification code, nullable raw work code, additional raw fields, deduplication hash/identity version, received timestamp, timestamps.
- Relationships: belongs to company/device/upload; optionally belongs to DeviceEmployee.
- Unique constraint: `(device_id, deduplication_hash)`.
- Indexes: company/time, device/time, device/PIN/time, and originating upload.
- Immutable source values; employee associations can be added later without changing punch identity.
- Read-only in Filament: no manual create/edit/delete of source punches.
- If local time is ambiguous/invalid, retain the row and time-quality information; do not invent a UTC instant or reject recoverable attendance evidence.

### 3.5 Employee

**Table:** `employees`

- Fields: `id`, `company_id`, `employee_number` as string, `name`, `is_active`, timestamps.
- Unique constraint: `(company_id, employee_number)`.
- Relationships: belongs to company; has many DeviceEmployee mappings.
- Optional enrichment: receiving attendance never requires a pre-created employee.
- Retire employees instead of removing their history.

### 3.6 DeviceEmployee

**Table:** `device_employees`

- Fields: `id`, `device_id`, `employee_id`, device `pin` as string, timestamps.
- Unique constraint: `(device_id, pin)`.
- Relationships: belongs to device and employee; has many associated punches.
- Validate that the device and employee belong to the same company.
- One employee may have different PINs on different devices.
- PIN-to-employee assignment is immutable once linked punches exist. Historical PIN reassignment needs effective-dated mapping in a later milestone; do not silently relabel existing history.

### 3.7 DeviceCommand

**Table:** `device_commands`

- Fields: `id`, `device_id`, `requested_by` User reference, allowlisted type, unique wire command ID, immutable wire payload, status, requested/offered/result timestamps, expiry, source IP for result, raw result, bounded result metadata, timestamps.
- Initial type: `RequestAttendance` only, serialized as the experimentally used `DATA QUERY ATTLOG`.
- Statuses: `Pending`, `Offered`, `Acknowledged`, `Failed`, `Unknown`, `Expired`, `Cancelled`.
- Relationships: belongs to device and requesting User.
- Persist offer state before returning the command. A lost response/result becomes `Unknown`; no automatic replay.
- Device acknowledgement does not prove every attendance batch has finished uploading. Keep acknowledgement and upload progress separate.
- A new operator request creates a new command after resolving/reviewing any active request. Cap active requests to one per device using a transactional device lock and a tested SQLite-compatible concurrency strategy.
- Persist result evidence before replying success. Duplicate/conflicting/late results must not overwrite prior evidence; use bounded structured result history on this model initially, rather than adding an eighth domain model.

Existing framework tables such as `users`, `jobs`, and `failed_jobs` remain infrastructure and do not count as new MVP domain models.

## 4. Model relationships and ownership

```text
Company -> Devices -> AttendanceUploads -> AttendancePunches
Company -> Employees -> DeviceEmployees <- Devices
DeviceEmployee -> AttendancePunches (optional association)
Device -> DeviceCommands <- User (requesting operator)
```

Store company ownership on uploads/punches for stable history and efficient queries. Services enforce agreement with the registered device's company; clients cannot submit ownership fields. Validate same-company employee mappings server-side. Do not enable cascade deletion of captured attendance when retiring any parent record.

## 5. Controllers — device HTTP boundary

Use a dedicated exact `/iclock/*` route group registered through the installed Laravel routing configuration. It must bypass browser sessions and browser CSRF requirements while leaving the Filament panel protections intact. It must not redirect devices to login or apply generic JSON error envelopes. Read raw body bytes without trimming or transforming fields.

| Controller | Endpoint | Responsibility |
| --- | --- | --- |
| DeviceInitializationController | GET `/iclock/cdata` | Resolve approved device, update liveness, return profile-specific options |
| AttendanceUploadController | POST `/iclock/cdata` | Validate bounds/table/identity, save upload, enqueue after commit, return exact success |
| DevicePollController | GET `/iclock/getrequest` | Update liveness, atomically offer eligible attendance query, otherwise return `OK` |
| DeviceCommandResultController | POST `/iclock/devicecmd` | Persist and correlate command result, handle duplicates/unknown IDs, return certified response |

Allow `table=options` separately for bounded device capability metadata if needed for the observed handshake. Reject unsupported biometric/user tables without success. Implement auxiliary endpoints or alternate methods only if actual device traffic requires them.

Some devices omit SN on `/iclock/devicecmd`. First inspect the probe capture for this device. If necessary, resolve results through a provisioned unique expected IP plus a matching issued wire ID, reject ambiguous NAT/shared-IP cases, and document that this remains a local-network compatibility safeguard. Do not select a device from an arbitrary result ID alone.

Controllers delegate persistence/processing/commands to services. User/device input must not determine arbitrary command text or table names for queries.

## 6. Services, queue job, and recovery command

| Component | Responsibility |
| --- | --- |
| ResolveDevice service | Exact serial match, active company/device checks, optional expected-IP restriction |
| DeviceProtocol service | Observed initialization options, attendance parser version, empty/success responses, allowlisted query serialization |
| AcceptAttendanceUpload service | Enforce initial 1MiB limit, retain exact payload/timezone/profile, commit before `OK` |
| ParseAttendance service | Incremental tab/newline parsing, preserve empty fields/leading zeros, validate datetime and raw fields |
| AttendanceIdentity service | Versioned deterministic hash of device + raw identity-relevant fields |
| ProcessAttendanceUpload job | Claim lease, process bounded chunks, count outcomes, persist checkpoint/status |
| RecoverAttendanceUploads command | Redispatch pending/failed-retryable uploads and expired processing leases with bounded batches/grace periods |
| OfferAttendanceCommand service | Expiry/cancellation/capability checks, concurrent-poll protection, durable offered state |
| RecordCommandResult service | Device/wire-ID correlation, raw result retention, conditional lifecycle transition |

### 6.1 Durable capture and async workflow

1. Resolve registered device and snapshot its company, timezone, and parser version.
2. Reject unsupported/oversized/incomplete input without a success-shaped response.
3. Commit the complete raw attendance upload in a transaction.
4. Attempt queue dispatch after commit, using only upload ID in the job payload.
5. If dispatch fails, leave the committed upload pending for recovery and record the failure; do not lose accepted bytes.
6. Return the observed `200` / plain-text `OK` response after commit, without parsing the batch inline.
7. Worker claims a fenced processing lease; concurrent/repeated jobs cannot perform overlapping unfenced processing.
8. Commit each bounded chunk's punches, counters, and checkpoint together.
9. Mark complete or complete-with-errors; retain failed evidence and expose retry/replay in administration.

The uploads table is the MVP's durable processing ledger. Recovery scans unfinished uploads even if queue dispatch was previously recorded as successful. No separate outbox model is necessary at this scale, but receipt recovery must close both commit-before-enqueue and queue-loss-after-enqueue gaps. Laravel `after_commit` alone does not provide that recovery.

### 6.2 Punch deduplication

Canonicalize device ID, PIN string, original local datetime, raw status, raw verification, raw work code, and relevant extra fields with unambiguous field boundaries. Store a SHA-256 identity and enforce uniqueness in the database. Handle only the intended identity conflict; do not suppress unrelated insert errors.

Do not include upload stamp, receipt time, upload ID, or queue ID. Separate receipt IDs preserve re-upload evidence. Two physically distinct punches with identical transmitted fields and no device event ID remain indistinguishable; document that source-data limitation.

### 6.3 Initial cursor behavior

Use the experimentally exercised initialization defaults as a compatibility starting point; capture Laravel responses on the physical device before freezing them. `ATTLOGStamp=None` may trigger historical replay. Never substitute current time or a numeric maximum for the constant `9999` stamp. MVP deduplication makes replay safe for storage; a smarter resume cursor is deferred until the device exposes reliable semantics.

## 7. Views — Filament administration

Reuse the existing `admin` panel and generate Filament 5 resources using installed-version generators.

| Resource | Views and actions |
| --- | --- |
| CompanyResource | List/create/edit companies; timezone and active status; related devices/employees |
| DeviceResource | List/create/edit devices; company, serial, name/location, timezone, expected IP, enabled status, last-seen; Request stored attendance action |
| AttendanceUploadResource | Read-only receipt list/details; device/company/status/date filters; payload/error inspection for authorized operators; explicit retry processing action |
| AttendancePunchResource | Read-only punch list/details; company/device/PIN/employee/date/status filters; display original local time, resolved UTC/time quality, raw codes |
| EmployeeResource | List/create/edit/retire employees; device-PIN mappings through a relation manager |
| DeviceCommandResource | Read-only history/details; requester, wire ID, state, result, age/expiry; cancellation only before offer |

DeviceEmployee is managed through a relation manager rather than a separate top-level resource. Add a small dashboard only after the core flow works: enabled devices, stale last-seen, today's punches, pending/failed uploads, and unknown commands. Show upload time separately from punch time so historical downloads are not mistaken for current attendance.

Do not label raw codes as check-in/check-out or fingerprint until verified. Do not expose raw payload editing or unrestricted command text.

## 8. Authorization and validation

- Reuse User, Shield, and policies; restrict panel access to authorized operators.
- Define policy abilities for company/device/employee administration, attendance viewing, raw upload viewing, upload retry, attendance request, and command cancellation.
- Explicitly authorize custom Filament actions; standard CRUD policy handling does not automatically protect custom actions.
- All requests derive company/device context from approved registration.
- Validate same-company mappings, timezone identifiers, serial uniqueness, IP format, employee number uniqueness, and command state/expiry.
- Keep raw attendance private and out of general application/access logs; display it only to permitted operators.
- Cap body size and upload/concurrent-request limits. Preserve bytes for accepted uploads; return short failures when storage is unavailable.

## 9. Step-by-step build sequence

### Step 1 — Schema and domain setup

- [ ] Read applicable project rules and inspect database schema through Boost.
- [ ] Generate seven models, useful factories, migrations, and safe development seeders through Artisan.
- [ ] Add relationships, casts, validation boundaries, enums with TitleCase cases, constraints, and indexes.
- [ ] Reuse existing User/authorization conventions; no new dependencies.
- [ ] Review migrations before applying them; preserve existing users/probe captures.

### Step 2 — Company and device administration

- [ ] Generate CompanyResource and DeviceResource.
- [ ] Add policies/permissions and validation.
- [ ] Register the existing test device under a company selected by the operator; do not invent its company or silently seed a production identity.
- [ ] Verify enabled/disabled device and inactive-company behavior.

### Step 3 — Initialization and durable upload acceptance

- [ ] Register exact iClock routes and dedicated middleware/error behavior.
- [ ] Implement initialization/options and liveness handling.
- [ ] Implement raw attendance persistence before success.
- [ ] Add upload inspection and pending/error status visibility.
- [ ] Test storage failure and malformed/oversized inputs.

### Step 4 — Async parsing and punch browsing

- [ ] Add parser/identity services, worker job, checkpoint/lease logic, and recovery command.
- [ ] Add unique punch persistence and row outcome counters.
- [ ] Add AttendancePunchResource and Upload retry action.
- [ ] Verify the captured single-row and 62-row formats, repeated upload, partial failure, and concurrent replay.

### Step 5 — Read-only attendance query

- [ ] Add command serialization, atomic offering, expiry, and result recording.
- [ ] Add authorized Request stored attendance action and command history.
- [ ] Preserve unknown outcomes rather than repeatedly issuing a query automatically.
- [ ] Test on the physical device; separately verify acknowledged state and historical records received.

### Step 6 — Optional employee enrichment

- [ ] Add EmployeeResource and DeviceEmployee relation managers.
- [ ] Keep unmapped PINs visible and valid.
- [ ] Allow explicit association/backfill of matching punches after validating mapping; never change punch identity.
- [ ] Prevent cross-company mappings and silent historical reassignment.

### Step 7 — Verification and local handover

- [ ] Run narrow Pest feature/unit tests and applicable static analysis.
- [ ] Format changed PHP with `vendor/bin/pint --dirty --format agent`.
- [ ] Ask the user to run the full suite with `php artisan test --compact` after feature tests pass.
- [ ] Provide exact server/worker/scheduler commands and device configuration based on the completed implementation.
- [ ] Stop the Python listener before Laravel uses port 8000.
- [ ] Confirm a live punch and historical query are visible in Filament and retained after worker restart.

## 10. Test plan and completion criteria

Use existing Pest conventions and model factories. Unit tests cover pure parser/identity logic; feature tests cover actual HTTP acceptance, authorization, persistence, command actions, and administration. Use installed Livewire testing capabilities or Laravel tests without adding a test plugin unless approved.

Required behavior:

- The observed initialization request receives a compatible plain-text response.
- Unknown/disabled devices and inactive companies cannot upload or receive commands.
- A completed `OK` response follows committed raw storage, observable through an independent reader.
- Valid eight-field rows, empty work code, trailing tab, leading-zero PIN, LF/CRLF, and 62-row batches are handled.
- Replayed rows produce no duplicate punches, while their separate raw upload receipts remain available.
- Unknown employee PINs and unresolved time interpretation do not cause silent loss.
- Mixed-validity uploads retain every byte and report rejected row positions/counts.
- Dispatch failure, lost jobs, worker interruption, and expired leases remain recoverable without overlapping unfenced work.
- Queue timeout is below reservation expiry; retry/worker settings follow installed-version documentation.
- Concurrent polls offer one command once; expired/cancelled requests are not offered.
- Wrong-device, unknown, duplicate, conflicting, and late results cannot incorrectly mark a command successful.
- Company/PIN mapping validation and custom-action permissions are enforced server-side.
- Source attendance/raw uploads are not editable or casually deletable through Filament.

SQLite tests establish local MVP behavior only. Repeat concurrency, lease, conflict, and durability tests against the production database/queue when that deployment is introduced.

**MVP completion:** an operator can register a company/device, capture live and historical attendance, inspect retained uploads/errors, browse deduplicated punches, optionally associate employees, and inspect read-only attendance command outcomes through Filament. No dependency changes, destructive device commands, or full BioTime feature parity are part of this milestone.

## 11. Decisions deferred to implementation or later milestones

- Operator-selected company and effective device timezone.
- Physical validation of initialization options and command-result format for this exact firmware.
- Production PostgreSQL/Redis topology, stronger device authentication, HA, retention, exports, and measurable latency/memory certification.
- Additional firmware profiles and reliable source event IDs/cursors.
- Company-specific customer login/access membership and effective-dated employee PIN reassignment.
- Optional explicit import of Python probe captures into Laravel; preserve original receipts and do not mutate the probe database.

This plan creates documentation only. Implementation starts in a subsequent authorized build step.
