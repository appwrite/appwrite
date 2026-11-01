import { describe, expect, test } from 'bun:test'
import { AppwriteException } from '@appwrite.io/console'
import {
  getErrorMessage,
  getErrorTitle,
  isPlanLimitError,
} from '@/lib/utils/error-formatting'

describe('plan limit errors', () => {
  const refused = new AppwriteException(
    'Additional monitors not allowed in the selected plan.',
    403,
    'additional_resource_not_allowed',
  )

  test('read as the limit, not as a missing permission', () => {
    expect(isPlanLimitError(refused)).toBe(true)
    expect(getErrorTitle(refused)).toBe('Plan limit reached')
    expect(getErrorMessage(refused)).toBe(
      'Additional monitors not allowed in the selected plan.',
    )
  })

  test('other 403s stay permission errors', () => {
    const forbidden = new AppwriteException(
      'Forbidden',
      403,
      'general_access_forbidden',
    )
    expect(isPlanLimitError(forbidden)).toBe(false)
    expect(getErrorMessage(forbidden)).toBe(
      'You do not have permission to access this resource.',
    )
  })
})
