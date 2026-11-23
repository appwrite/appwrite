import { describe, expect, test } from 'bun:test'
import { getErrorMessage } from '@/lib/utils/error-formatting'

function apiError(message: string, code: number) {
  return Object.assign(new Error(message), { code })
}

describe('403 error formatting', () => {
  test('shows the API explanation when the request is refused', () => {
    const error = apiError(
      'The maximum number of platforms allowed for the selected plan has reached. Upgrade to increase the limit.',
      403,
    )
    expect(getErrorMessage(error)).toBe(
      'The maximum number of platforms allowed for the selected plan has reached. Upgrade to increase the limit.',
    )
  })

  test('keeps the generic message when the API sends no message', () => {
    expect(getErrorMessage(apiError('', 403))).toBe(
      'You do not have permission to access this resource.',
    )
  })

  test('keeps the generic message for non-user-friendly errors', () => {
    expect(
      getErrorMessage(
        apiError('TypeError: cannot read properties at foo.ts', 403),
      ),
    ).toBe('You do not have permission to access this resource.')
  })
})
