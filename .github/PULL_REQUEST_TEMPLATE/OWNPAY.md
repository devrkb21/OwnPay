# Pull Request Template

> [!IMPORTANT]
> **Branch Rule**:
> Make sure this pull request targets the **`dev` branch**. Pull requests opened against `main` will be automatically closed.

## Description
Summarize the change, the related issue, and any relevant background or motivation.

Fixes #

## Type of Change
Select all that apply.
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## 🎨 Design / UI Changes
If applicable, include screenshots or screen recordings showing the UI changes.

## 🧪 How Has This Been Tested?
Describe the tests you ran and how to reproduce them.
- [ ] Automated unit tests (`vendor/bin/phpunit`)
- [ ] Static analysis (`vendor/bin/phpstan analyse`)
- [ ] Manual verification in local environment

**Test Configuration**
- OwnPay Version:
- PHP Version:
- Database:
- Environment:

## Checklist
- [ ] My PR targets the **`dev` branch** and not `main`.
- [ ] My code follows the [PSR-12 coding standards](https://github.com/devrkb21/OwnPay/blob/main/CONTRIBUTING.md).
- [ ] I have declared `declare(strict_types=1);` at the top of all new PHP files.
- [ ] I have resolved dependencies via the DI container where applicable.
- [ ] I have scoped brand-specific reads via `TenantScope` and writes via `BrandContext::getWriteMerchantId()`.
- [ ] All new database tables/columns use the `op_` prefix and follow the column naming conventions.
- [ ] My forms use the canonical `_csrf_token` retrieved via `\OwnPay\Security\SecurityHelpers::csrfToken()`.
- [ ] I have performed a self-review and run lint checks (`php -l`).
- [ ] I have made corresponding documentation updates, if needed.
- [ ] New and existing unit tests pass locally.
- [ ] I have checked my code for misspellings.

## ⚖️ License
- [ ] I agree that my contributions will be licensed under the **AGPL-3.0 License**.
