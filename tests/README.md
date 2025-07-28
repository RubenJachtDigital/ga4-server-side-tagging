# GA4 Server-Side Tagging Plugin Tests

This directory contains PHPUnit tests for the GA4 Server-Side Tagging WordPress plugin, specifically testing the `/send-events` endpoint with different transmission methods.

## Test Coverage

The test suite covers the following functionality:

### Core Endpoint Tests
- **Single Event Processing**: Tests handling of individual events from JavaScript clients
- **Batch Event Processing**: Tests handling of multiple events batched together
- **Cloudflare Worker Transmission**: Tests events sent via Cloudflare Worker proxy
- **Direct GA4 Transmission**: Tests events sent directly to Google Analytics API

### Payload Transformation Tests
- **Event Structure Conversion**: Tests conversion from single event to unified batch structure
- **Legacy Format Support**: Tests backward compatibility with older payload formats
- **Header Processing**: Tests filtering and storage of essential request headers

### Security & Validation Tests
- **Bot Detection**: Tests multi-factor bot detection system
- **Rate Limiting**: Tests 100 requests/minute rate limiting
- **Origin Validation**: Tests request origin validation
- **Payload Validation**: Tests handling of malformed or invalid payloads

### Privacy & Consent Tests
- **GDPR Compliance**: Tests consent handling for ad_user_data and ad_personalization
- **Data Anonymization**: Tests data anonymization for denied consent
- **Encryption Support**: Tests JWT encryption for sensitive data

### Database Integration Tests
- **Event Storage**: Tests unified table storage with monitor_status and queue_status
- **Queue Processing**: Tests background cron job processing
- **Status Tracking**: Tests event lifecycle from pending to completed

## Test Structure

### Main Test File
- `test-ga4-endpoint.php` - Comprehensive endpoint tests with realistic payloads

### Supporting Files
- `bootstrap.php` - PHPUnit bootstrap configuration
- `phpunit6-compat.php` - Compatibility layer for PHPUnit 6+
- `test-utilities.php` - Helper classes and utilities
- `README.md` - This documentation file

### Configuration
- `phpunit.xml` - PHPUnit configuration in project root

## Sample Payloads

The tests use realistic payloads based on actual plugin usage:

### JavaScript Client Payload (Single Event)
```json
{
  "event": {
    "name": "custom_user_engagement",
    "params": {
      "engagement_time_msec": 60000,
      "event_timestamp": 1753702507,
      "user_agent": "Mozilla/5.0...",
      "timezone": "Europe/Brussels",
      "client_id": "2046349794.1753702447",
      "session_id": "1753702447180"
    }
  },
  "consent": {
    "ad_user_data": "GRANTED",
    "ad_personalization": "GRANTED"
  }
}
```

### Cloudflare Worker Payload (Batch)
```json
{
  "events": [
    {
      "name": "custom_session_start",
      "params": {...},
      "headers": {
        "x_forwarded_for": "...",
        "user_agent": "...",
        "referer": "..."
      }
    },
    {
      "name": "custom_user_engagement", 
      "params": {...}
    }
  ],
  "batch": true
}
```

### Direct GA4 Payload
```json
{
  "client_id": "1406931247.1753690598",
  "events": [
    {
      "name": "page_view",
      "params": {
        "session_id": "1753690598129",
        "page_location": "https://example.com/"
      }
    }
  ],
  "consent": {
    "ad_user_data": "GRANTED",
    "ad_personalization": "GRANTED"
  },
  "user_agent": "Mozilla/5.0..."
}
```

## Running Tests

### Prerequisites
1. WordPress test environment set up
2. PHPUnit installed
3. Plugin activated in test environment

### Installation
1. Install WordPress test suite:
```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

2. Set environment variables:
```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_CORE_DIR=/tmp/wordpress/
```

### Running Tests
```bash
# Run all tests
phpunit

# Run specific test file
phpunit tests/test-ga4-endpoint.php

# Run with coverage
phpunit --coverage-html tests/coverage

# Run specific test method
phpunit --filter test_single_event_cloudflare_worker_transmission

# Run tests with verbose output
phpunit --testdox --verbose
```

### Test Database
Tests use a separate database to avoid conflicts with development data. The test database is automatically created and cleaned up.

## Test Scenarios

### Transmission Method Testing

**Cloudflare Worker Method (`ga4_disable_cf_proxy = false`)**:
1. Events received via REST API
2. Stored in database with `queue_status = 'pending'`
3. Background cron processes queue every 5 minutes
4. Events sent to Cloudflare Worker in batches
5. Status updated to `completed` or `failed`

**Direct GA4 Method (`ga4_disable_cf_proxy = true`)**:
1. Events received via REST API
2. Stored in database with `queue_status = 'pending'`
3. Background cron processes queue
4. Events sent directly to Google Analytics API
5. Payload transformed to GA4 format

### Error Handling Testing
- HTTP timeouts
- Invalid API credentials
- Malformed payloads
- Bot traffic
- Rate limit exceeded
- Database errors

### Performance Testing
- Batch processing efficiency
- Memory usage with large payloads
- Database query optimization
- Concurrent request handling

## Debugging Tests

### Enable Debug Mode
```php
// In wp-config.php or test bootstrap
define('WP_DEBUG', true);
define('GA4_TEST_MODE', true);
define('GA4_DISABLE_BOT_DETECTION', true);
```

### View Test Logs
```bash
# WordPress debug log
tail -f wp-content/debug.log

# Plugin-specific logs
tail -f wp-content/uploads/ga4-server-side-tagging/logs/
```

### Database Inspection
```sql
-- View test events
SELECT * FROM wp_ga4_event_logs WHERE event_name LIKE 'test_%';

-- Check event statuses
SELECT monitor_status, queue_status, COUNT(*) 
FROM wp_ga4_event_logs 
WHERE event_name LIKE 'test_%' 
GROUP BY monitor_status, queue_status;
```

## Continuous Integration

Tests are designed to run in CI environments like GitHub Actions:

```yaml
name: PHPUnit Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
      - name: Install WordPress Test Suite
        run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
      - name: Run PHPUnit
        run: phpunit
```

## Contributing

When adding new tests:

1. Follow existing naming conventions (`test_feature_description`)
2. Use helper methods from `test-utilities.php`
3. Clean up test data in `tearDown()`
4. Mock external HTTP requests
5. Test both success and failure scenarios
6. Include realistic payload examples

## Troubleshooting

### Common Issues

**Database Connection Errors**:
- Verify WP_TESTS_DIR environment variable
- Check database credentials in wp-tests-config.php

**Class Not Found Errors**:
- Ensure all required files are included in bootstrap.php
- Check autoloading configuration

**HTTP Mock Issues**:
- Verify filter hooks are added before making requests
- Check URL matching in mock functions

**Memory Issues**:
- Increase PHP memory limit for tests
- Use data providers for large datasets

For additional support, refer to the main plugin documentation or submit issues to the GitHub repository.