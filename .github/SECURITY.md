# Security Policy

## Supported Versions

| Version | Supported          |
| :------ | :----------------- |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability within this project, please **do not** report it through public GitHub issues.

Instead, please use one of the following private channels:

- **GitHub Private Vulnerability Reporting** (Preferred):
  [Report a vulnerability](https://github.com/midsonlajeanty/composer-workspace-plugin/security/advisories/new)
- **Email**: [midsonlajeanty@proton.me](mailto:midsonlajeanty@proton.me)

### What to include

To help us address the issue quickly, please include as much detail as possible:

- **Type of issue**: (e.g., arbitrary code execution, path traversal, etc.)
- **Affected version(s)**
- **Steps to reproduce**: Ideally with a minimal monorepo layout.
- **Potential impact**: How this vulnerability could be exploited.

### Our Response

You will receive an initial response within **72 hours**. Once the issue is confirmed, a fix will be prepared and released as quickly as possible. You will be credited in the release notes unless you prefer to remain anonymous.

## Scope Notes

This plugin executes Composer commands and `composer.json` scripts of the workspace members it discovers. Running it inside a monorepo you do not trust is equivalent to running `composer install` there; always treat untrusted repositories with caution.
