# Contributing

Thank you for considering contributing to PHP SimpleQueue! This document provides guidelines and instructions for contributing.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for everyone.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. When creating a bug report, include:

- **Clear title** describing the issue
- **Steps to reproduce** the behavior
- **Expected behavior** vs actual behavior
- **PHP version** and environment details
- **Code samples** if applicable

### Suggesting Enhancements

Enhancement suggestions are welcome! Please include:

- **Use case** explaining why this enhancement would be useful
- **Proposed solution** with as much detail as possible
- **Alternatives** you've considered

### Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests (`composer test`)
5. Run static analysis (`composer phpstan`)
6. Run code style check (`composer cs-check`)
7. Commit your changes (`git commit -m 'Add amazing feature'`)
8. Push to the branch (`git push origin feature/amazing-feature`)
9. Open a Pull Request

## Development Setup

```bash
# Clone your fork
git clone https://github.com/your-username/php-simplequeue.git
cd php-simplequeue

# Install dependencies
composer install

# Run tests
composer test

# Run with coverage
composer test-coverage

# Check code style
composer cs-check

# Fix code style
composer cs-fix

# Run static analysis
composer phpstan
```

## Coding Standards

- Follow PSR-12 coding standards
- Use strict types (`declare(strict_types=1);`)
- Write descriptive commit messages
- Add PHPDoc blocks for public methods
- Include unit tests for new features

### Code Style

```php
<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

/**
 * Brief description of the class.
 */
final class Example
{
    private string $property;

    /**
     * Brief description of the method.
     *
     * @param string $value Description
     * @return void
     */
    public function method(string $value): void
    {
        $this->property = $value;
    }
}
```

## Testing

- Write tests for all new features
- Maintain existing test coverage
- Use meaningful test method names: `testMethodNameWithScenarioExpectsBehavior`

```php
public function testDispatchCreatesJobInStorage(): void
{
    // Arrange
    $storage = new InMemoryJobStorage();
    // ...

    // Act
    $jobId = $dispatcher->dispatch('test.job', ['key' => 'value']);

    // Assert
    $this->assertNotEmpty($jobId);
}
```

## Commit Messages

Follow conventional commits format:

- `feat: add new feature`
- `fix: resolve bug`
- `docs: update documentation`
- `test: add tests`
- `refactor: code refactoring`
- `chore: maintenance tasks`

## Questions?

Feel free to open an issue for any questions or concerns.
