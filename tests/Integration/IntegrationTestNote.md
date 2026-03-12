# Integration tests

Integration tests are intentionally opt-in and require real MySQL/PostgreSQL instances with extensions enabled.

Suggested setup:

1. Start MySQL and PostgreSQL via Docker.
2. Configure Laravel `database.connections` for both drivers.
3. Run package tests in an application context.

This repository includes unit tests for scheduler/compiler behavior; real-driver integration is expected to run in CI or consuming app pipelines.
