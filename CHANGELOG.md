# Changelog

## 3.1.2

### Breaking changes

- Provisioning ownership fencing is removed. The Appwrite destination is back on the 2.x status-only database
  lifecycle.
  - `Destinations\Appwrite\ProvisioningOwner` is removed.
  - `Destinations\Appwrite::__construct()` no longer takes `owner`, `getRecoverableOwner` or `provisioningLease`, and
    `Destinations\Appwrite::DEFAULT_PROVISIONING_LEASE` is removed. `onDuplicate` and the arguments after it move up
    two places for positional callers.
  - The destination no longer reads or writes the `migrationId` and `migrationAttemptId` database attributes.
  - A `provisioning` or `failed` database is recovered by the next run at once, without owner attestation or a lease
    wait.
- The standalone CLI no longer accepts `--migration-id`, `--migration-attempt-id`, `--recover-migration-id`,
  `--recover-migration-attempt-id` or `-h`/`--help`.
- `Destinations\Appwrite::run()` finalizes again, as in 2.x: once the transfer returns it flips the databases it
  provisioned to `ready`, then runs the overwrite orphan sweep.
  - `success()`, `error()` and `cleanUp()` are no-op lifecycle hooks again. Callers no longer call `success()` to
    finalize, and `cleanUp()` no longer reports a skipped `success()`.
  - A failed `ready` flip is recorded with `addError()`; a failed orphan sweep throws from `run()`.
  - `Utopia\Migration\Exception\Finalization` is removed.
- The abort mechanism is removed.
  - `Utopia\Migration\Exception\Aborted` and `Destination::markAborted()` are removed.
  - `Transfer::run()` no longer latches an exception thrown from its progress callback, and the library sources no
    longer rethrow one: each source handles it as in 2.x.

## 3.0.0 (unreleased)

3.0 is a major release. The breaking changes below all concern the Appwrite destination's lifecycle and the
database library it builds on; read the upgrade notes before you upgrade from 2.x.

### Breaking changes

- Requires `utopia-php/database` 8 (`main` requires `^7.0.0`). The PHP floor is unchanged: `>=8.5`.
- The Appwrite destination records which migration attempt provisions each database.
  - `Destinations\Appwrite::__construct()` takes a `ProvisioningOwner $owner`, made from a stable logical migration
    id and a fresh attempt id for every execution.
  - It also takes a `getRecoverableOwner` callback that attests the terminal owner of an incomplete database.
  - It takes a trailing `int $provisioningLease`, 86400 seconds by default. A negative value is rejected with an
    `InvalidArgumentException`.
  - Owners are written and enforced only where the destination's `databases` metadata declares both owner
    attributes.
- Recovering an incomplete database is gated.
  - A `provisioning` or `failed` database that names an owner is recovered only when `getRecoverableOwner` attests
    that owner.
  - A `provisioning` or `failed` database that names no owner is recovered only once it has not been updated for
    the destination's provisioning lease. A fresher one is refused: `createDatabase()` fails closed with an error
    naming the database, because another migration may still be provisioning it. `provisioningLease: 0` recovers it
    at once, which is how 2.x behaved.
  - The standalone CLI requires `--migration-id` and a fresh `--migration-attempt-id`. Recovering a database that
    names an owner also requires `--recover-migration-id` and `--recover-migration-attempt-id`.
- `Destinations\Appwrite::run()` no longer finalizes.
  - Call `success()` once `run()` has returned, after persisting any claim that must come first, or `error()` for a
    run you will not finalize.
  - `success()` throws `Utopia\Migration\Exception\Finalization` when a step fails. Every step is attempted and
    each failure is also recorded with `addError()`.
  - `cleanUp()` records an error for each database a returned run left unfinalized when neither `success()` nor
    `error()` was called.

### Added

- `Utopia\Migration\Exception\Aborted`. Throw it from a transfer's progress callback to stop the transfer. Every
  library source rethrows it, and `Transfer` ends the run with it.
- `Destination::markAborted()`, a no-op by default. `Transfer` calls it before it rethrows a latched abort. The
  Appwrite destination drops its pending finalization there, so a later `success()` finalizes nothing.

### Fixed

- Imports into a destination whose database metadata has `status` but no owner attributes.
- Recovery of databases left `provisioning` or `failed` without an owner.
- An ownerless database that another migration may still be provisioning is no longer taken over.
- The provisioning fence no longer relies on source timestamps.
- A relationship overwrite keeps the destination's `onDelete` when the source has none.
- One failed ready flip no longer skips the overwrite orphan sweep.
- `success()` after a transfer that ended with an abort finalizes nothing, whatever the source did with the abort.

### Upgrade notes

- **Custom sources.** A `Source` subclass that catches `\Throwable` around its exports should rethrow
  `Utopia\Migration\Exception\Aborted` before recording other errors:
  `} catch (Aborted $abort) { throw $abort; } catch (\Throwable $error) { ... }`. `Transfer::run()` still ends with
  the abort if it does not, but the source keeps exporting until it returns.
- **Callers of `Destinations\Appwrite`:**
  1. After `run()` returns, persist any claim that must come first, then call `success()`.
  2. Catch `Exception\Finalization` from `success()`. Each failure is also in `getErrors()`.
  3. For a run you will not finalize, call `error()` instead.
  4. After catching `Aborted` from `Transfer::run()`, call `error()` (or nothing), never `success()`. Since
     `Transfer` reports the abort to the destination, a mistaken `success()` is harmless.
- **`getRecoverableOwner` is unchanged.** It is now called only for rows that name an owner, so existing callbacks
  need no change.
- **Custom destinations** inherit `markAborted()` as a no-op. Override it only to drop state a later `success()`
  would otherwise commit; `Transfer` calls it with the abort still in flight. It runs only for an abort a source
  recorded and `Transfer` latched — an abort that propagates straight out of `run()` never reaches it.
