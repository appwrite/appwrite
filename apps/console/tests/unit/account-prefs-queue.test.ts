import { afterEach, describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { queueAccountPrefsWrite } from '../../src/lib/account-prefs-queue'
import {
  clearConsoleAccountCache,
  setConsoleAccountCache,
} from '../../src/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '../../src/lib/console-impersonation'

function deferred() {
  let resolve!: () => void
  const promise = new Promise<void>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function signIn(accountId: string) {
  setConsoleAccountCache(
    { $id: accountId } as Models.User,
    getConsoleAccountQueryRevision(),
  )
}

/** Queues a write that waits for `release`, so the next one queues behind it. */
function queueHeldWrite() {
  const held = deferred()
  const done = queueAccountPrefsWrite(() => held.promise)
  return { release: held.resolve, done }
}

afterEach(() => {
  clearConsoleAccountCache()
})

describe('queueAccountPrefsWrite', () => {
  test('starts a write only after the one before it settles', async () => {
    const events: string[] = []
    const first = deferred()

    const a = queueAccountPrefsWrite(async () => {
      events.push('a start')
      await first.promise
      events.push('a end')
      return 'a'
    })
    const b = queueAccountPrefsWrite(async () => {
      events.push('b start')
      return 'b'
    })

    await Promise.resolve()
    expect(events).toEqual(['a start'])

    first.resolve()
    expect(await a).toBe('a')
    expect(await b).toBe('b')
    expect(events).toEqual(['a start', 'a end', 'b start'])
  })

  test('a failed write reaches its caller and does not stop the next', async () => {
    const failed = queueAccountPrefsWrite(async () => {
      throw new Error('prefs rejected')
    })
    const next = queueAccountPrefsWrite(async () => 'saved')

    await expect(failed).rejects.toThrow('prefs rejected')
    expect(await next).toBe('saved')
  })

  test('runs a queued write for the account that is still signed in', async () => {
    signIn('accountA')
    const first = queueHeldWrite()
    const second = queueAccountPrefsWrite(async () => 'saved')

    first.release()
    await first.done
    expect(await second).toBe('saved')
  })

  test('drops a queued write when another account signs in', async () => {
    signIn('accountA')
    const first = queueHeldWrite()
    let ran = false
    const second = queueAccountPrefsWrite(async () => {
      ran = true
      return 'saved'
    })

    signIn('accountB')
    first.release()
    await first.done
    expect(await second).toBeUndefined()
    expect(ran).toBe(false)
  })

  test('drops a queued write when the account signs out', async () => {
    signIn('accountA')
    const first = queueHeldWrite()
    let ran = false
    const second = queueAccountPrefsWrite(async () => {
      ran = true
      return 'saved'
    })

    clearConsoleAccountCache()
    first.release()
    await first.done
    expect(await second).toBeUndefined()
    expect(ran).toBe(false)
  })

  test('drops a write queued with no account once an account signs in', async () => {
    const first = queueHeldWrite()
    let ran = false
    const second = queueAccountPrefsWrite(async () => {
      ran = true
      return 'saved'
    })

    signIn('accountB')
    first.release()
    await first.done
    expect(await second).toBeUndefined()
    expect(ran).toBe(false)
  })
})
