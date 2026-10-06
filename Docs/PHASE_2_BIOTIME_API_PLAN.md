# Phase 2 — BioTime-compatible attendance API

Date: 6 October 2026. Status: implementation started; exact 8.5/9.0 parity is not yet certified.

## Goal and scope

External HR/ERP connectors should consume gateway attendance using their existing BioTime paths, credentials payloads, authorization headers, filters, pagination, and JSON field types. This is the user's new Phase 2 milestone; it is distinct from the ingestion phase numbering in `ADMS_GATEWAY_ARCHITECTURE.md`. The two existing documents provide design context, not new instructions or permission to expand this request into payroll, scheduling, or terminal mutation.

Implement an adapter over the existing immutable attendance ledger. Do not expose Laravel model JSON or introduce an `/api/v1` prefix where BioTime uses an existing path. Device PUSH ingestion and the Filament operator login remain separate interfaces.

## Contract evidence

The supplied `/home/a6e6s/Code/jit-hr/Doc/BioTimeIntegration.md` and its actual `BioTimeService`/`SyncBioTimeAttendanceJob` establish the first connector acceptance target. The connector requires JWT login at `/jwt-api-token-auth/`, lowercase `jwt` authorization, and `data`/`count`/`next` pagination. JWT remains outstanding.

On 6 October 2026, inspected every row of the user-authorized `jit-hr.biotime_logs` through a read-only database transaction. The gateway's Boost MySQL connection could not authenticate, so used the HR application's existing connection settings without printing credentials. Results: 754 successful transaction responses containing 35,766 transaction entries (including repeated responses), and 89 successful JWT login responses. No failure responses occur in this table. Every transaction has the same 20 keys; every list has the same six envelope keys. Sanitized representative records for all 20 observed code/mask/work-code combinations are committed in `tests/Fixtures/BioTime/transactions.json`. IDs, employee codes, device/site labels, and timestamps are replaced; no JWT tokens or personal data are copied.

Observed state mappings: 0 = Check In, 1 = Check Out, 4 = Overtime In, 5 = Overtime Out, 255 = Unknown. Observed verification mappings: 1 = Fingerprint, 3 = Password, 4 = Card, 15 = Face. This installation differs from the generic online example that labels 0 as Password. Use the captured installation as the authority. Unobserved codes are displayed as Unknown rather than assigning an unverified label.

Pagination evidence: every logged request explicitly uses page size 100. The 501 responses containing multiple rows use ascending IDs. Links retain the trailing slash, alphabetically sort query parameters, and omit `page=1` when returning to the first page; the gateway now reproduces these link conventions. Defaults for omitted page size remain unverified.

Observed values: `emp`, `first_name`, `last_name`, `department`, `position`, and `gps_location` are null; `work_code` is a string (`"0"` or `""`); `temperature` is numeric zero; `is_mask` is `"No"` or `"-"`; `area_alias` is a string. `msg` is `""`, and `code` is numeric zero. Remove older-manual-only fields from this connector profile. JWT headers use HS256 and JWT, with claims `token_type`, `exp`, `iat`, `jti`, `user_id` and an 86,400-second lifetime; token bytes and user identifiers were not retained.

- [ZKTeco BioTime 8.5 API manual, September 2019](https://zkteco.jo/assets/uploads/products/pdf/6ac1f2ec60f38b037e422e5ddeb9d262.pdf), authentication and transaction sections.
- [Vendor transaction reference](https://zkbiotime.xmzkteco.com/docs/api-docs/transaction_api.html).
- [Vendor token reference](https://zkbiotime.xmzkteco.com/docs/api-docs/get_auth_token.html).

The references show different transaction fields. The online reference is not enough to prove a specific 9.0 build. Establish distinct version profiles from real installations, rather than returning a union of every observed field. Documentation supplies a starting contract; build-specific request/response captures are the release authority. Exact compatibility includes status, headers, nulls, scalar types, errors, authentication lifetime, ordering, and pagination, not only successful JSON examples.

## Implementation sequence

| Milestone | Work | Acceptance |
| --- | --- | --- |
| 1: authenticated attendance reads | General token login, dedicated company-scoped service clients, list/detail transactions, pagination and filters | Feature tests cover wire shape, authentication, tenant isolation, leading zeros, dates, bounds, read-only behavior |
| 2: verified version profiles | Capture 8.5 and 9.0 build contracts; serializer and error profiles; confirm slash behavior, defaults, ordering, inclusive bounds, aliases | Golden responses match both selected builds; no unverified parity claim |
| 3: JWT and lifecycle | JWT login/verify/refresh as actually used by connectors; expiry, rotation, revocation, account disablement | Tampering, wrong algorithm, expiry, revoked identity, and scope tests; select a maintained JWT package with approval before changing dependencies |
| 4: reference APIs | Read-only terminals, employees, departments, positions, areas; add only required metadata to the schema | Connector discovery succeeds, stable identities and company isolation; unknown metadata is represented according to captured contracts |
| 5: connector acceptance | Replay real HR/ERP requests, exports if required, deployment and performance checks | Existing connectors operate after changing only server address and provisioned credentials; reconcile attendance counts and timestamps |

Staff tokens, write APIs, reports based on shifts/payroll, terminal commands, attendance deletion, and employee synchronization need separate contract and scope decisions. Do not implement successful-looking empty endpoints for unimplemented business features.

## First implementation slice

- `POST /api-token-auth/`: accepts `username` and `password`, returns a stable `token`. Use `Authorization: Token <token>` on attendance reads. Service clients have independent hashed passwords, encrypted random tokens, indexed token digests, one explicit company, and an active flag. Existing operator passwords do not become API credentials.
- `GET /iclock/api/transactions/`: returns `count`, `next`, `previous`, `msg`, `code`, `data`; no Laravel pagination metadata. Support `page`, `page_size`, legacy `limit`, `emp_code`, `terminal_sn`, `terminal_alias`, `start_time`, and `end_time`.
- `GET /iclock/api/transactions/{id}/`: direct object; a record outside the service client's company returns 404.
- Preserve local punch timestamps and raw status; preserve string employee numbers/PINs. Resolve mapped employee numbers before falling back to an unmapped PIN. Filter dates against original local time, independently of the device's resolved UTC time.
- Use the captured installation's exact 20-field transaction profile. Supplemental `biotime_metadata` stores explicitly available GPS, area, temperature, and mask values separately from raw device fields. Without metadata, GPS is null, temperature is 0 (the observed no-reading representation), mask is `"-"` (unknown, not a claim that the employee wore no mask), and area comes from registered device location or an empty string. To reproduce a specific source record's `"No"`/`"-"` and area value, preserve its metadata; do not infer mask values from work code or attendance state. These fallbacks are explicit policies, not evidence that the source would use them for every missing-data case. Gateway IDs identify gateway records, not original BioTime IDs; importing ID correspondence is a later migration requirement if connectors persist original IDs.
- Initial policy: ascending ID order, inclusive time bounds, default page size 10, maximum 1000, `page_size` wins over `limit`, `page=last` supported, invalid query values return field errors with HTTP 400, nonexistent pages return 404. These are explicit provisional policies to verify against the target installations.
- Authentication, not a query parameter, supplies company ownership. Deactivated clients or companies cannot log in or read. Rate-limit token login and reads, with DRF-style error bodies. Keep receipts and raw payloads private.

## Verification and remaining evidence

Run `php artisan test --compact tests/Feature/BioTimeApiTest.php`, the existing device-controller feature tests, Pint, and static analysis. Request a complete suite run after targeted tests pass. Do not contact a production BioTime installation or external connector without its connection details and authorization.

Collect sanitized successful and failing exchanges from the exact 8.5 and 9.0 builds and the connector endpoint inventory. Include missing/invalid credentials, token scheme, expired JWT, pagination edge cases, Unicode, leading zeros, unknown codes, timezone/DST behavior, malformed filters, method errors, and empty results. Record which metadata must be imported rather than derived. Matching self-authored feature tests is not evidence of exact vendor parity.

## Local setup

Apply the additive service-client migration with `php artisan migrate --no-interaction`. Provision an existing active company with `php artisan biotime:client-create <username> <company-id>`; this command asks for a hidden password and creates no default accounts. The command requires interactive secret entry so passwords do not appear in command arguments or shell history. Obtain the stable token from the login endpoint. Setting `is_active` to false revokes both login and token access. Token rotation and a client-management UI remain follow-up work.

## Progress

- [x] Review architecture, current schema, installed framework and existing conventions.
- [x] Implement the first milestone's general token authentication, client provisioning command, company-scoped transaction list/detail, filters, bounded pagination, and contract-focused feature tests.
- [x] Verify first-slice checks: 52 targeted tests and 210 assertions passed (31 new API tests plus 21 existing device tests); the final resource edit was rechecked with all 31 API tests. Pint passed and static analysis passed for every changed PHP file. Full-project static analysis reports 46 existing errors outside these files.
- [x] Review migration SQL and apply the additive `bio_time_clients` table locally. No service clients or default credentials were created.
- [x] Collect sanitized golden transaction fixtures from every observed combination in the HR logs and correct the transaction serializer to that installation's exact key set, labels, nulls, and scalar types.
- [x] Validate captured responses and device regression coverage: 58 tests and 286 assertions passed. The pagination change was rechecked with 37 API tests and 185 assertions. Pint and focused static analysis passed for the serializer, controller, model, and metadata migration. The nullable metadata migration was applied locally and Scramble documentation regenerated.
- [ ] Complete JWT authentication, error/ordering/version evidence, and remaining milestones 2–5. The logs do not establish universal 8.5/9.0 compatibility or document failure responses.

Validation wording currently comes from Laravel's English validation catalog inside a DRF-style field-error body; exact DRF wording, malformed JSON handling, authentication failure variants, and version-specific metadata defaults must be resolved during milestone 2. Unsupported raw verification codes produce null instead of inventing a verification type. API errors use English independently of the operator panel's configured locale.
