# Security policy

## Supported versions

Security fixes are made on the latest release.

## Reporting a vulnerability

Please don't open a public GitHub issue for a security problem. Use GitHub's private advisory flow instead:

1. Go to https://github.com/mage-os-lab/module-aeo/security/advisories/new
2. Fill in a concise title and a clear description with reproduction steps.
3. Submit. The maintainers get notified privately.

## What to expect

- Initial acknowledgement within five working days.
- Triage + severity assessment within ten working days.
- A fix plan or a published advisory within thirty days of the report, depending on severity.
- Coordinated disclosure. We'll credit the reporter in the release notes unless you prefer anonymity.

## Scope

In scope:
- SQL injection, XSS, CSRF, privilege escalation, or path traversal in any module code under this repository.
- Path traversal or arbitrary file writes through the feed storage directory, past the restrictions described in `docs/feeds.md`.
- Information leaking into `/llms.txt`, `/llms-full.txt` or `/llms.jsonl` that the storefront does not show, such as a disabled product or an inactive category.
- Injection of forged directives into `robots.txt` through the AI crawler settings.

Out of scope (not a MageOS_Aeo vulnerability):
- Issues in Magento / Mage-OS core, MageOS_Seo, or unrelated third-party modules.
- Social engineering, physical attacks, denial-of-service by volume.
- Findings that require admin-role access already granted by the merchant, other than the feed storage restrictions above, which exist to hold against an administrator.

## Hardening

- Keep Magento / Mage-OS on a supported security patch level.
- Run `composer audit` regularly and apply dependency updates.
- Enable GitHub Dependabot alerts for your own fork (it's on by default for public repos).
