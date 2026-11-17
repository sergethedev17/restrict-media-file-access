---
name: rmfa-testing
description: Guides writing and running tests for the Restrict Media File Access WordPress plugin using Codeception/wp-browser integration tests and Codeception E2E tests. Use when writing tests, debugging test failures, or adding coverage for new features.
---

# Skill: RMFA Testing

## When to Use

Use this skill when:
- Writing or modifying tests for the RMFA plugin.
- Running the smoke suite or the Codeception suites.
- Debugging test failures.
- Adding test coverage for new features.

## Test Frameworks

| Framework | Location | Purpose |
|-----------|----------|---------|
| WP-CLI smoke suite | `tests/smoke/file-rbac.php` | Behavioural checks against real users, roles, attachments and HTTP requests. This is the suite that covers the plugin today. |
| Codeception + wp-browser | `tests/Integration/` | WordPress integration tests (scaffolded; no test classes yet). |
| Codeception + wp-browser | `tests/EndToEnd/` | Codeception browser E2E tests (scaffolded; no test classes yet). |

### Dependencies

- **Smoke suite:** WP-CLI and a WordPress install with the plugin active.
- **PHP:** `lucatume/wp-browser` ^4.
- **JS:** `@wordpress/env` ^10.8.
- **Infrastructure:** Docker (for wp-env), Selenium with Chromium (for E2E).

## Smoke Suite

`tests/smoke/file-rbac.php` is a plain PHP file run with `wp eval-file`. It creates its own fixtures (users, a `rmfa_role`, attachments) on every run, so re-running is safe and leaves them behind.

- Assert with `rmfa_check( 'label', $condition )`; append new checks before the summary lines.
- The run must end with `N checks, 0 failures`.
- Do not declare `strict_types`: `wp eval-file` prepends `?>` and the declaration becomes a fatal.
- Reuse the fixtures that already exist (`$admin_id`, `$owner_id`, `$allowed_id`, `$unlisted_id`, `$role_user`, `$image_id`, `$public_id`, `$access`) instead of redeclaring them.
- Use `rest_do_request()` for REST and the suite's `rmfa_http()` helper for HTTP. Cookie auth from the CLI does not work.
- A filter added in this process is invisible to the web container, so test hook-dependent behaviour in-process.
- The suite asserts the plugin version — bump it with every release (currently `1.6.0`).

## Running Tests

All commands run from the `restrict-media-file-access/` plugin root, except the smoke suite, which runs from the WordPress root:

```bash
# Smoke suite (no wp-env needed; plugin must be active)
wp eval-file wp-content/plugins/restrict-media-file-access/tests/smoke/file-rbac.php

# Start wp-env (required for the Codeception suites)
npm run wp-env:start

# Integration tests
composer tests:run:integration

# E2E tests (Codeception + Selenium)
composer tests:run:end-to-end

# All Codeception tests (integration + E2E)
npm run tests:run

# Via wp-env (runs inside container)
npm run tests:run:integration
npm run tests:run:end-to-end

# Clean Codeception output
composer tests:clean
```

## Local Setup (macOS)

1. Install Docker, Composer, Node.js.
2. Enable host networking in Docker settings (`Resources > Network`).
3. Start Selenium: `docker run -d --shm-size="2g" --net=host --name="selenium-chromium" selenium/standalone-chromium:latest`
4. Install dependencies: `composer run-script packages-install && npm install`
5. Copy `tests/.dist.env` to `tests/.env`.
6. Create database fixture: `npm run tests:export-db`
7. Run tests: `npm run tests:run`

## Test Configuration

### Codeception Config (`codeception.dist.yml`)

```yaml
namespace: Tests
support_namespace: Support
paths:
    tests: tests
    output: tests/_output
    data: tests/Support/Data
    support: tests/Support
params:
    - tests/.env
```

### Suite Configs

- `tests/Integration.suite.yml` — Integration suite configuration.
- `tests/EndToEnd.suite.yml` — E2E suite configuration.

### Environment Variables (`tests/.env`)

Copied from `tests/.dist.env`. Contains database credentials and WordPress test config for wp-browser.

## Writing Integration Tests

### File and Namespace Convention

```
tests/Integration/<Name>Test.php
→ Tests\Integration\<Name>Test
```

Or with subdirectories:

```
tests/Integration/<SubDir>/<Name>Test.php
→ Tests\Integration\<SubDir>\<Name>Test
```

### Test Class Structure

```php
<?php

namespace Tests\Integration;

use WP_UnitTestCase;
use A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsFileManager;

class AttachmentsFileManagerTest extends WP_UnitTestCase {

    private AttachmentsFileManager $file_manager;

    public function setUp(): void {
        parent::setUp();
        $this->file_manager = new AttachmentsFileManager();
    }

    public function tearDown(): void {
        parent::tearDown();
    }

    public function test_set_file_as_protected_returns_true_for_new_restriction(): void {
        $attachment_id = self::factory()->attachment->create_upload_object( ... );
        $result = $this->file_manager->set_file_as_protected( $attachment_id );
        $this->assertTrue( $result );
    }

    public function test_set_file_as_protected_returns_false_when_already_protected(): void {
        $attachment_id = self::factory()->attachment->create_upload_object( ... );
        $this->file_manager->set_file_as_protected( $attachment_id );
        $result = $this->file_manager->set_file_as_protected( $attachment_id );
        $this->assertFalse( $result );
    }
}
```

### Key Testing Patterns

**Reset singleton between tests:**

`Plugin` is a singleton. Reset via reflection when needed:

```php
$reflection = new \ReflectionClass( Plugin::class );
$property = $reflection->getProperty( 'instance' );
$property->setAccessible( true );
$property->setValue( null, null );
```

**Test file operations with the WordPress attachment factory:**

```php
$attachment_id = self::factory()->attachment->create_upload_object(
    '/path/to/test/image.jpg',
    0 // parent post ID
);
```

**Verify post meta:**

```php
$this->assertSame( '1', get_post_meta( $attachment_id, '_restricted_file', true ) );
$this->assertNotEmpty( get_post_meta( $attachment_id, '_protected_file_hash', true ) );
```

**Test URL modifications:**

```php
$url = wp_get_attachment_url( $attachment_id );
$this->assertStringContains( 'protected-files/', $url );
```

**Save/restore globals:**

Session and file-serving code may read `$_GET`, `$_SERVER`. Save and restore in setUp/tearDown:

```php
private array $original_server;

public function setUp(): void {
    parent::setUp();
    $this->original_server = $_SERVER;
}

public function tearDown(): void {
    $_SERVER = $this->original_server;
    parent::tearDown();
}
```

**No placeholder assertions:**

Every test method must have a meaningful assertion. Do not use `$this->assertTrue( true )` as a placeholder.

### Test Naming Convention

```
test_<action>_<condition>_<expected_result>
```

Examples:
- `test_set_file_as_protected_returns_true_for_new_file`
- `test_set_file_as_unprotected_returns_false_when_not_restricted`
- `test_modify_attachment_url_returns_hash_url_for_protected_file`
- `test_handle_protected_file_returns_gif_for_logged_out_user`
- `test_restrict_file_endpoint_returns_403_when_disabled`

## Procedure: Adding Tests for a New Feature

1. **Smoke first:** append `rmfa_check()` cases to `tests/smoke/file-rbac.php` and prove they fail before writing the fix. This is the suite the project actually runs.
2. **Integration:** when the case needs isolated DB/hook setup, create `tests/Integration/<Name>Test.php` and test the PHP logic directly. The suite is scaffolded but has no test classes yet.
3. **E2E (Codeception):** when the case is browser-only, create `tests/EndToEnd/<Name>Cest.php` and test user-facing behaviour (media library, restriction toggle, file access).
4. Follow the naming and namespace conventions above.
5. Run the smoke suite and the relevant Codeception suite to verify no regressions.

## Verification

- Smoke suite ends `N checks, 0 failures`.
- No placeholder assertions (`assertTrue( true )`).
- New code has corresponding smoke coverage; add Codeception tests when the case needs DB/hook isolation or a browser.
- Tests are isolated — no test depends on another test's side effects.
- Global state (`$_GET`, `$_SERVER`) is saved/restored.
- Singletons are properly reset between tests.
