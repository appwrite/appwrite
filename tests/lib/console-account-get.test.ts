import { describe, it, expect, beforeEach } from 'vitest'
import {
  fetchConsoleAccount,
  registerConsoleAccountGet,
} from '@/lib/console-account-get'
import { clearConsoleAccountCache } from '@/lib/console-account-cache'
import type { Models } from '@appwrite.io/console'

// Regression test for the "missing scopes account" sign-in bug.
//
// The _auth route loader fires a guest account.get on page load, whose 401 gets
// cached. The post-sign-in probe used to call account.get WITHOUT force, so it
// replayed that cached guest 401 instead of fetching fresh with the new session.
// Password managers hit this by autofilling and submitting fast; the fix forces
// a fresh fetch. These tests lock that contract.
describe('fetchConsoleAccount guest 401 replay', () => {
  const user = { $id: 'u1' } as Models.User

  beforeEach(() => {
    clearConsoleAccountCache()
  })

  it('replays a cached guest 401 on a non-forced call (the bug)', async () => {
    let calls = 0
    registerConsoleAccountGet(async () => {
      calls++
      if (calls === 1) throw { code: 401, message: 'missing scopes account' }
      return user
    })

    // Loader-style guest fetch: caches the 401.
    await expect(fetchConsoleAccount()).rejects.toMatchObject({ code: 401 })

    // Post-sign-in probe without force replays the cached guest error and never
    // issues a fresh request — this is what surfaced as "missing scopes account".
    await expect(fetchConsoleAccount()).rejects.toMatchObject({ code: 401 })
    expect(calls).toBe(1)
  })

  it('bypasses the cached guest 401 on a forced call (the fix)', async () => {
    let calls = 0
    registerConsoleAccountGet(async () => {
      calls++
      if (calls === 1) throw { code: 401, message: 'missing scopes account' }
      return user
    })

    await expect(fetchConsoleAccount()).rejects.toMatchObject({ code: 401 })

    // Forcing clears the cached 401 and makes a fresh request with the new session.
    await expect(fetchConsoleAccount({ force: true })).resolves.toEqual(user)
    expect(calls).toBe(2)
  })
})
