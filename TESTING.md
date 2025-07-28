# GA4 Server-Side Tagging - Testing with Composer

This document explains how to run tests using Composer for the GA4 Server-Side Tagging WordPress plugin.

## Prerequisites

- PHP 7.4 or higher
- Composer
- MySQL/MariaDB
- Git

## Quick Start

```bash
# Clone and set up the project
git clone <repository-url>
cd ga4-server-side-tagging

# Install dependencies
composer install

# Set up WordPress test environment
composer run-script setup-tests

# Run all tests  
composer test
```

## Available Composer Scripts

### Testing Scripts

```bash
# Run all tests (encryption + endpoint functionality)
composer test

# Run simple test suite (encryption functionality only)
composer test:simple

# Run endpoint standalone tests (GA4 API endpoint with user data)
composer test:endpoint

# Run unit tests with native WordPress bootstrap
composer test:unit
```

### Code Quality Scripts

```bash
# Check PHP syntax (✅ Works - shows deprecation warnings only)
composer lint
```

#### Current Status and Solutions:

**✅ PHP Lint (`composer lint`)**: Works perfectly  
- Detects syntax errors across all PHP files
- All files pass with no syntax errors
- All deprecation warnings fixed ✅
- Only remaining deprecations are from Composer dependencies (non-breaking)

#### Working Code Quality Tools:

**✅ Clean Syntax Check**:
```bash  
# Use only the working tool for now
composer lint                    # ✅ Check PHP syntax

# Alternative: Run PHPCS directly on specific files (may work)
./vendor/bin/phpcs --standard=WordPress includes/class-ga4-encryption-util.php

# Alternative: Skip problematic tools and use IDE analysis
# Most IDEs provide better WordPress-aware analysis
```

### Setup Scripts

```bash
# Install WordPress test environment
composer install-wp-tests

# Complete test setup (install WP tests + composer dependencies)
composer setup-tests
```

## Test Structure

### Directory Organization

```
tests/
├── unit/                    # Unit tests (isolated, fast)
│   ├── SimpleEncryptionTest.php     # Encryption functionality tests
│   ├── EndpointStandaloneTest.php   # GA4 endpoint tests with user data
│   ├── EncryptionUtilTest.php       # Legacy encryption tests
│   └── EventLoggerTest.php          # Event logging tests
├── integration/             # Integration tests (with WordPress)
│   └── EndpointTest.php
├── bootstrap-simple.php     # Simple test environment (no WordPress)
├── bootstrap.php           # Full WordPress test environment setup
├── test-utilities.php      # Helper classes and utilities
├── phpunit6-compat.php     # PHPUnit compatibility
└── README.md              # Test documentation
```

### Test Suites

- **Simple Tests**: Fast, isolated encryption tests using `bootstrap-simple.php`
- **Endpoint Tests**: GA4 API endpoint tests with real user data using mocked WordPress functions
- **Unit Tests**: WordPress-integrated unit tests that require full WordPress environment
- **Integration Tests**: Full end-to-end tests with WordPress environment and database

### Expected Test Output

When running `composer test`, you should see output like this:

```
PHPUnit 9.6.23 by Sebastian Bergmann and contributors.

Simple Encryption
 ✔ Encryption class exists
 ✔ Encrypt method exists  
 ✔ Decrypt method exists
 ✔ Basic encryption
 ✔ Encryption decryption cycle
 ✔ Invalid key handling

Endpoint Standalone (GA4ServerSideTagging\Tests\Unit\EndpointStandalone)
 ✔ Successful event processing with provided data
 ✔ Unified batch format processing
 ✔ Empty request data handling
 ✔ Empty events array handling
 ✔ Malformed event handling
 ✔ Consent status extraction
 ✔ Header filtering
 ✔ Event parameter data types
 ✔ Legacy single event format transformation
 ✔ Multiple events batch processing

OK (16 tests, 45 assertions)
```

### New Endpoint Test Features

The `EndpointStandaloneTest.php` uses the exact user-provided request data structure and validates:

- **Real User Data**: Event processing with actual scroll tracking data from user requests
- **Consent Handling**: Tests both GRANTED and DENIED consent scenarios
- **Header Security**: Validates header filtering and security measures
- **Error Handling**: Tests malformed requests and missing data scenarios
- **Format Support**: Multiple event formats (unified batch, legacy single event)
- **Bot Detection**: Integration with bot detection systems
- **Data Types**: Various parameter data types (strings, integers, arrays, booleans)
- **Mock-based**: Uses PHPUnit mocks instead of database dependencies for fast execution

## Configuration Files

### composer.json
Contains all dependencies and scripts for testing:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^9.5",
    "wp-phpunit/wp-phpunit": "^6.0", 
    "yoast/phpunit-polyfills": "^1.0",
    "brain/monkey": "^2.6",
    "squizlabs/php_codesniffer": "^3.6",
    "phpstan/phpstan": "^1.4"
  }
}
```

### phpunit.xml
PHPUnit configuration with separate test suites:

```xml
<testsuites>
    <testsuite name="unit">
        <directory suffix="Test.php">./tests/unit/</directory>
    </testsuite>
    <testsuite name="integration">
        <directory suffix="Test.php">./tests/integration/</directory>
    </testsuite>
</testsuites>
```

## Running Specific Tests

### By Test Suite
```bash
# Unit tests only (fast)
composer test:unit

# Integration tests only (slower, requires WordPress)  
composer test:integration
```

### By Test File
```bash
# Specific test file
./vendor/bin/phpunit tests/unit/EncryptionUtilTest.php

# Specific test method
./vendor/bin/phpunit --filter test_encrypt_decrypt_data tests/unit/EncryptionUtilTest.php
```

### By Test Pattern
```bash
# All encryption-related tests
./vendor/bin/phpunit --filter Encryption

# All endpoint tests
./vendor/bin/phpunit --filter Endpoint
```

## Test Environment Setup

### Automatic Setup
```bash
# One command setup
composer setup-tests
```

### Manual Setup
```bash
# 1. Install WordPress test suite
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# 2. Set environment variables
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_CORE_DIR=/tmp/wordpress/

# 3. Install Composer dependencies
composer install
```

### Environment Variables

Set these in your shell or CI environment:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_CORE_DIR=/tmp/wordpress/
export WP_TESTS_DOMAIN=example.org
export WP_TESTS_EMAIL=admin@example.org
export WP_TESTS_TITLE="Test Blog"
```

## Test Data and Fixtures

### Sample Payloads

The tests use realistic payloads based on actual plugin usage:

```php
// JavaScript client payload (single event)
$single_event = array(
    'event' => array(
        'name' => 'custom_user_engagement',
        'params' => array(
            'engagement_time_msec' => 60000,
            'client_id' => '2046349794.1753702447'
        )
    ),
    'consent' => array(
        'ad_user_data' => 'GRANTED',
        'ad_personalization' => 'GRANTED'
    )
);

// Cloudflare Worker batch payload
$batch_payload = array(
    'events' => array(/* multiple events */),
    'batch' => true
);
```

### Mock HTTP Responses

Tests use mocked HTTP responses to avoid external dependencies:

```php
// Mock successful Cloudflare response
add_filter('pre_http_request', function($response, $args, $url) {
    if (strpos($url, 'workers.dev') !== false) {
        return array(
            'response' => array('code' => 200),
            'body' => wp_json_encode(array('success' => true))
        );
    }
    return $response;
}, 10, 3);
```

## Code Coverage

### Generate Coverage Report
```bash
# HTML coverage report
composer test:coverage

# View coverage report
open tests/coverage/index.html
```

### Coverage Requirements

- **Minimum**: 70% line coverage
- **Target**: 85% line coverage
- **Critical paths**: 95% coverage (encryption, event processing)

## Continuous Integration

### GitHub Actions

The repository includes a comprehensive CI workflow (`.github/workflows/ci.yml`):

- **Multi-PHP testing**: PHP 7.4, 8.0, 8.1, 8.2
- **Multi-WordPress testing**: Latest, 6.0, 5.9
- **Code quality checks**: PHPStan, PHPCS, PHPMD
- **Security audits**: Composer audit
- **Coverage reporting**: Codecov integration

### Local CI Simulation

```bash
# Run the same checks as CI
composer lint      # PHP syntax
composer cs:check   # Code standards  
composer stan       # Static analysis
composer test       # All tests
composer md         # Mess detection
```

## Debugging Tests

### Enable Debug Output
```bash
# Verbose test output
./vendor/bin/phpunit --testdox --verbose

# Debug specific test
./vendor/bin/phpunit --debug tests/unit/EncryptionUtilTest.php
```

### Test Database Inspection

```sql
-- Connect to test database
mysql -u wp_user -p wordpress_test

-- View test events
SELECT * FROM wp_ga4_event_logs WHERE event_name LIKE 'test_%';

-- Check event statuses
SELECT monitor_status, queue_status, COUNT(*) 
FROM wp_ga4_event_logs 
WHERE event_name LIKE 'test_%' 
GROUP BY monitor_status, queue_status;
```

### WordPress Debug Logs

```php
// Enable debug logging in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// View debug log
tail -f wp-content/debug.log
```

## Testing Best Practices

### Writing Tests

1. **Use descriptive test names**: `test_single_event_cloudflare_worker_transmission`
2. **Follow arrange-act-assert pattern**:
   ```php
   public function test_event_encryption() {
       // Arrange
       $event_data = $this->create_test_event();
       
       // Act  
       $encrypted = $this->encrypt_event($event_data);
       
       // Assert
       $this->assertNotEquals($event_data, $encrypted);
   }
   ```
3. **Clean up test data**: Use `tearDown()` method
4. **Mock external dependencies**: HTTP requests, WordPress functions
5. **Test both success and failure scenarios**

### Test Organization

- **Unit tests**: One test class per source class
- **Integration tests**: Organized by feature/workflow
- **Shared utilities**: Use test helper classes
- **Data providers**: For parameterized tests

### Performance

- **Fast unit tests**: < 100ms each
- **Reasonable integration tests**: < 5s each  
- **Parallel execution**: Use `--parallel` flag
- **Database optimization**: Clean up after tests

## Troubleshooting

### Common Issues

**Composer autoload issues**:
```bash
composer dump-autoload -o
```

**WordPress test environment problems**:
```bash
# Reinstall test environment
rm -rf /tmp/wordpress-tests-lib /tmp/wordpress
composer setup-tests
```

**Database connection errors**:
```bash
# Check MySQL service
sudo service mysql start

# Verify credentials
mysql -u wp_user -p wordpress_test
```

**Memory issues during tests**:
```bash
# Increase PHP memory limit
php -d memory_limit=512M vendor/bin/phpunit
```

### Getting Help

1. **Check test logs**: `tests/logs/` directory
2. **Verify environment**: `composer diagnose`
3. **Update dependencies**: `composer update`
4. **Reset environment**: `composer setup-tests`

## Advanced Usage

### Custom Test Configuration

Create `phpunit.local.xml` for local overrides:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <const name="WP_TESTS_DOMAIN" value="localhost"/>
        <const name="GA4_TEST_DEBUG" value="true"/>
    </php>
</phpunit>
```

### Profiling Tests

```bash
# Profile slow tests
./vendor/bin/phpunit --log-junit results.xml

# Generate performance report  
./vendor/bin/phpunit --coverage-html tests/coverage --log-junit results.xml
```

### Testing in Docker

```bash
# Run tests in Docker container
docker run --rm -v $(pwd):/app -w /app php:8.1-cli composer test
```

This comprehensive testing setup ensures the GA4 Server-Side Tagging plugin is reliable, maintainable, and ready for production use.