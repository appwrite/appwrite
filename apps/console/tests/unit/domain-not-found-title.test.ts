import { describe, expect, test } from 'bun:test'
import { formatResourceNotFoundError } from '@/lib/utils/error-formatting'

describe('formatResourceNotFoundError', () => {
  test('titles a missing organization domain', () => {
    const error = Object.assign(
      new Error('Domain with the requested ID could not be found.'),
      { code: 404, type: 'domain_not_found' },
    )
    expect(formatResourceNotFoundError(error)?.title).toBe('Domain not found')
  })
})
