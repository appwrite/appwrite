import { describe, expect, test } from 'bun:test'
import { unhandledServerErrorFromConsoleArgs } from '@/lib/sentry/unhandled-http-error'

function httpError(cause: Error, unhandled = true) {
  const error = new Error(cause.message, { cause })
  error.name = 'HTTPError'
  Object.assign(error, {
    status: 500,
    statusText: undefined,
    headers: undefined,
    data: undefined,
    unhandled,
  })
  return error
}

describe('unhandledServerErrorFromConsoleArgs', () => {
  test('returns the cause of an h3 unhandled HTTPError', () => {
    const cause = new TypeError(
      "undefined is not an object (evaluating 'routerInstance.serverSsr.dehydrate')",
    )
    const found = unhandledServerErrorFromConsoleArgs([httpError(cause)])

    expect(found?.error).toBe(cause)
    expect(found?.status).toBe(500)
  })

  test('ignores handled HTTP errors and ordinary logs', () => {
    const cause = new Error('not found')
    expect(
      unhandledServerErrorFromConsoleArgs([httpError(cause, false)]),
    ).toBeUndefined()
    expect(
      unhandledServerErrorFromConsoleArgs(['Server Fn Error!', cause]),
    ).toBeUndefined()
    expect(unhandledServerErrorFromConsoleArgs([])).toBeUndefined()
  })
})
