<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    'api_path' => [
        'include' => ['jwt-api-token-auth', 'iclock/api'],
    ],

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', '0.0.1'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<'MARKDOWN'
# Getting started

Use this API to read attendance transactions for the company assigned to your API client. Every request uses the installation's base URL (for example, `https://gateway.example.com`). API client credentials are separate from admin-panel accounts.

## Step 1: Create an API client

Ask an administrator to create a client for your active company. Run this command on the gateway server and replace the placeholders:

```bash
php artisan biotime:client-create <username> <company-id>
```

Enter a unique username and a password of at least 12 characters at the hidden prompt. The command requires an existing active company. Keep the password private.

## Step 2: Exchange the credentials for a token

Send the username and password as JSON:

```bash
curl -X POST 'https://gateway.example.com/jwt-api-token-auth/' \
  -H 'Content-Type: application/json' \
  -d '{"username":"<username>","password":"<password>"}'
```

A successful response contains the client's token:

```json
{"token":"<token>"}
```

Store the token securely. The API accepts it in the `Authorization` header using either `Token <token>` or `jwt <token>`.

## Step 3: List attendance transactions

Send a GET request with the token. For example, request up to 100 records for an employee during a date range:

```bash
curl -G 'https://gateway.example.com/iclock/api/transactions/' \
  -H 'Authorization: Token <token>' \
  --data-urlencode 'page_size=100' \
  --data-urlencode 'emp_code=00012' \
  --data-urlencode 'start_time=2026-10-01 00:00:00' \
  --data-urlencode 'end_time=2026-10-31 23:59:59'
```

The response contains `count`, `next`, `previous`, and a `data` array. Pagination links retain the selected filters. If `page_size` is omitted, the default is 10; the maximum is 1000. The `limit` parameter is also accepted as a legacy page-size alias.

## Step 4: Read one transaction

Use the transaction `id` from the list response:

```bash
curl 'https://gateway.example.com/iclock/api/transactions/<id>/' \
  -H 'Authorization: Token <token>'
```

The detail endpoint returns the transaction object directly. Both endpoints are restricted to the company assigned to the API client; a client cannot change its scope with query parameters.

## Available list filters

- `emp_code`: exact employee number, or the PIN when the punch has no employee mapping.
- `terminal_sn`: exact device serial number.
- `terminal_alias`: exact registered device name.
- `start_time` and `end_time`: inclusive bounds in `YYYY-MM-DD HH:MM:SS` format, compared with the device-local punch time.
- `page`: positive page number or `last`.
- `page_size`: 1–1000 records per page.

Login is limited to 10 requests per minute per source IP, and transaction reads to 120 requests per minute per API client. Exact compatibility with all BioTime 8.5/9.0 installations is still being validated.
MARKDOWN,
    ],

    'ui' => [
        'title' => 'ADMS Gateway API',
    ],

    /*
     * Load Scramble's development tools on documentation pages. An explicit
     * SCRAMBLE_DEV_TOOLS value takes precedence over APP_DEBUG.
     */
    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'docs_enabled' => env('SCRAMBLE_DOCS_ENABLED', true),

    'extensions' => [],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    // 'security_strategy' => \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
