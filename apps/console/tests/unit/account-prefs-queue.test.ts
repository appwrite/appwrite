import { describe, expect, test } from 'bun:test'
import { queueAccountPrefsWrite } from '../../src/lib/account-prefs-queue'

function deferred() {
  let resolve!: () => void
  const promise = new Promise<void>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

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
})
