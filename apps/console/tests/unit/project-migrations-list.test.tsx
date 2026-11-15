import { afterAll, afterEach, describe, expect, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { createElement, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'

// The runtime's own Response, before the DOM's replaces it: the SDK reads these
// the way it reads the network's
const NativeResponse = globalThis.Response

GlobalRegistrator.register({ url: 'http://localhost/' })
const { act, cleanup, renderHook } = await import('@testing-library/react')
const { getContext } = await import(
  '@/integrations/tanstack-query/root-provider'
)
const { useProjectMigrations } = await import(
  '@/lib/react-query/hooks/migrations'
)

/**
 * The Migrations tab shows the list the hook last read. A read that fails after
 * one that succeeded leaves TanStack's `isSuccess` false while the data stays,
 * so a list keyed on `isSuccess` dropped what it had shown, and hid the failure
 * behind the route loader's snapshot. The hook says whether a list was read, and
 * keeps it, through a read that fails.
 *
 * The SDK is real; `fetch` answers as the API does.
 */
describe('the project migrations list', () => {
  const realFetch = globalThis.fetch
  const copy = { $id: 'mig1', status: 'completed' }
  const answers: Response[] = []
  const requested: string[] = []

  globalThis.fetch = (async (input: RequestInfo | URL) => {
    requested.push(new URL(String(input)).pathname)
    const next = answers.shift()
    if (!next) throw new Error('No answer queued')
    return next
  }) as typeof fetch

  const listed = () =>
    new NativeResponse(JSON.stringify({ total: 1, migrations: [copy] }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    })
  const refused = () =>
    new NativeResponse(
      JSON.stringify({
        message: 'Server Error',
        code: 500,
        type: 'general_unknown',
        version: '1.0',
      }),
      { status: 500, headers: { 'content-type': 'application/json' } },
    )

  afterEach(() => {
    cleanup()
    answers.length = 0
    requested.length = 0
  })

  afterAll(async () => {
    globalThis.fetch = realFetch
    await GlobalRegistrator.unregister()
  })

  /**
   * Wait inside act(), so React flushes the hook's updates itself. RTL's waitFor
   * leaves them to React's scheduler, which earlier DOM tests in a full run leave
   * without a window to run on.
   */
  async function until(check: () => void, timeout = 2000) {
    const started = Date.now()
    for (;;) {
      try {
        check()
        return
      } catch (error) {
        if (Date.now() - started > timeout) throw error
      }
      await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 10))
      })
    }
  }

  function render() {
    // The Console's own client, with its defaults: no retries, no refetch on focus
    const { queryClient } = getContext()
    const wrapper = ({ children }: { children: ReactNode }) =>
      createElement(QueryClientProvider, { client: queryClient }, children)
    return renderHook(() => useProjectMigrations('storefront', 'fra'), {
      wrapper,
    })
  }

  test('a read that fails after one that succeeded keeps the list, and reports the failure', async () => {
    answers.push(listed())
    const { result } = render()
    await until(() => expect(result.current.hasData).toBe(true))
    expect(result.current.migrations.map((m) => m.$id)).toEqual(['mig1'])

    answers.push(refused())
    await act(async () => {
      await result.current.refetch()
    })

    await until(() =>
      expect(result.current.error?.message).toBe('Server Error'),
    )
    expect(result.current.hasData).toBe(true)
    expect(result.current.migrations.map((m) => m.$id)).toEqual(['mig1'])
    expect(requested).toEqual(['/v1/migrations', '/v1/migrations'])
  })

  test('a first read that fails has no list to show', async () => {
    answers.push(refused())
    const { result } = render()
    await until(() => expect(result.current.error).toBeTruthy())
    expect(result.current.hasData).toBe(false)
    expect(result.current.migrations).toEqual([])
  })
})
