# PHPUnit suite checks

`phpunit.xml` is the test membership list. Use recursive directories and add a
named `e2e-*` suite for a new service. CI derives ordinary service jobs from those
names; environment overrides do not act as an allowlist.

```sh
php tests/tools/suites.php phpunit.xml
vendor/bin/phpunit --testsuite unit
vendor/bin/paratest --testsuite e2e-account
node --test tests/tools/matrix.test.cjs
```

`suites.php` checks concrete test-class naming and XML membership without booting
the app or running data providers. Deliberately non-runnable fixtures live under
`Fixtures/`. Tests outside that boundary cannot disappear through a wrong filename
or an omitted directory. PHPUnit remains responsible for method discovery.

Run listing and execution with the same configuration, suite and group arguments:

```sh
vendor/bin/phpunit --testsuite e2e-account --list-tests-xml expected.xml
vendor/bin/paratest --testsuite e2e-account --log-junit junit.xml
php tests/tools/results.php expected.xml junit.xml
```

`results.php` checks identities, including data-set names, rather than just counts.
It rejects missing/duplicate results and test failures. Keep the runner exit code:
a crashed runner is a failed job even if it left a report. Skips are printed as
not covered; `--fail-on-skipped` rejects them on strict lanes. Existing credential
and engine-specific skips remain visible on legacy service lanes while their
preconditions migrate to explicit group selection.

The XML and scripts ship with CE so Cloud can consume the same configuration and
checks. New shared test suites must not require a copied list in Cloud. Unit
regression tests in `tests/unit/CI/SuiteCoverageTest.php` drive the commands and
real PHPUnit output, including interrupted execution and inherited tests.

Make `Tests / Complete` required in repository protection to prevent failed or
missing test jobs being treated as verified. Weekly/manual runs cover all engine
and table-mode combinations; ordinary runs retain the existing smaller matrix.
