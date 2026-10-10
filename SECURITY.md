# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Report vulnerabilities through GitHub private vulnerability reporting: open the [Security tab](https://github.com/lucasp1337/laravel-loom/security) and choose "Report a vulnerability". Please do not open a public issue for a security problem.

## Scope notes

Loom is a development tool. Install it with `composer require lucasp1337/laravel-loom --dev`. The index it writes describes your application's structure and is meant to be committed alongside your source; it holds no secrets or runtime data.

- The UI is mounted only in `local` by default and is guarded by the `viewLoom` gate.
- The MCP server reads only the generated index.
