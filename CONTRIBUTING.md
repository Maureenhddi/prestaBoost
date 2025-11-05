# Contributing to PrestaBoost

Thank you for your interest in contributing to PrestaBoost! This document provides guidelines for contributing to the project.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR_USERNAME/PrestaBoost.git`
3. Create a branch: `git checkout -b feature/your-feature-name`
4. Make your changes
5. Test your changes
6. Commit with descriptive messages
7. Push to your fork
8. Create a Pull Request

## Development Setup

```bash
# Install and start
make install

# Create test user
make console CMD='app:create-user \
  --email=dev@test.com \
  --password=test123 \
  --first-name=Dev \
  --last-name=User \
  --super-admin'

# Run the application
make up
```

## Code Standards

### PHP

- Follow PSR-12 coding standards
- Use type hints for all parameters and return types
- Document public methods with PHPDoc
- Keep methods focused and small (Single Responsibility)

### Git Commits

Use conventional commit messages:
```
feat: add user registration endpoint
fix: resolve JWT token expiration issue
docs: update installation instructions
refactor: simplify stock collection logic
test: add unit tests for BoutiqueService
```

### Branch Naming

- `feature/` - New features
- `fix/` - Bug fixes
- `refactor/` - Code refactoring
- `docs/` - Documentation updates
- `test/` - Test additions/updates

## Testing

Before submitting a PR:

```bash
# Run tests
make console CMD='bin/phpunit'

# Check code style (if configured)
make console CMD='vendor/bin/php-cs-fixer fix --dry-run'

# Clear cache
make cache-clear
```

## Pull Request Process

1. Ensure your code follows the project's coding standards
2. Update documentation if needed
3. Add tests for new features
4. Ensure all tests pass
5. Update CHANGELOG.md with your changes
6. Reference any related issues in your PR description

### PR Title Format

```
[TYPE] Brief description

Examples:
[FEATURE] Add user registration endpoint
[FIX] Resolve stock collection timeout issue
[DOCS] Update API documentation
```

## What to Contribute

### High Priority
- [ ] User registration API endpoint
- [ ] Email notifications for invitations
- [ ] Unit and integration tests
- [ ] API documentation (Swagger/OpenAPI)
- [ ] Docker image optimization

### Features
- [ ] Real-time stock alerts
- [ ] Product analytics dashboard
- [ ] Multi-language support
- [ ] Advanced search and filtering
- [ ] Export functionality (CSV, Excel)

### Documentation
- [ ] API endpoint examples
- [ ] Deployment guides (AWS, DigitalOcean, etc.)
- [ ] Video tutorials
- [ ] Troubleshooting guide expansion

### Bug Fixes
Check the [Issues](https://github.com/yourrepo/PrestaBoost/issues) page for open bugs.

## Code Review Process

1. Maintainers will review your PR within 3-5 business days
2. Address any requested changes
3. Once approved, your PR will be merged
4. Your contribution will be credited in the CHANGELOG

## Questions?

- Open an issue for questions
- Join our discussions
- Check existing documentation

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## Recognition

Contributors will be recognized in:
- CHANGELOG.md
- README.md (Contributors section)
- GitHub Contributors page

Thank you for contributing to PrestaBoost!
