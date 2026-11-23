import { describe, expect, test } from 'bun:test'
import { QueryClient } from '@tanstack/react-query'
import { ensureQueryDataIfFound } from '@/lib/react-query/ensure-query-data-if-found'

class FakeAppwriteException extends Error {
  code: number
  type: string
  constructor(message: string, code: number, type: string) {
    super(message)
    this.name = 'AppwriteException'
    this.code = code
    this.type = type
  }
}

function options<T>(queryFn: () => Promise<T>) {
  return {
    queryKey: ['deployment', 'test', Math.random()],
    queryFn,
    retry: false,
  }
}

describe('ensureQueryDataIfFound', () => {
  test('returns the data when the query succeeds', async () => {
    const queryClient = new QueryClient()
    const result = await ensureQueryDataIfFound(
      queryClient,
      options(async () => ({ $id: 'dep' })),
    )
    expect(result).toEqual({ $id: 'dep' })
  })

  test('resolves to undefined when the API responds with 404', async () => {
    const queryClient = new QueryClient()
    const result = await ensureQueryDataIfFound(
      queryClient,
      options(async () => {
        throw new FakeAppwriteException(
          'Deployment with the requested ID could not be found.',
          404,
          'deployment_not_found',
        )
      }),
    )
    expect(result).toBeUndefined()
  })

  test('rethrows non-404 errors', async () => {
    const queryClient = new QueryClient()
    await expect(
      ensureQueryDataIfFound(
        queryClient,
        options(async () => {
          throw new FakeAppwriteException(
            'Server error',
            500,
            'general_unknown',
          )
        }),
      ),
    ).rejects.toThrow('Server error')
  })
})
