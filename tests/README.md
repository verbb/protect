# Tests

Install the Composer development dependencies, then run `composer test`. The suite creates a temporary Craft application with no database. Set `PROTECT_TEST_VENDOR_PATH` to run against another installed Craft dependency tree.

Run `npm ci`, `npx playwright install chromium` and `npm test` for the browser package build, lifecycle and package tests and headless driver checks. Provider SDKs are deterministic doubles, and no live credentials are used.

Run `composer fixtures` to regenerate the browser round-trip fixture after changing the manifest or the fixture plan, then `UPDATE_ROUND_TRIP_FIXTURES=1 npm test` to regenerate the evidence fixture.
