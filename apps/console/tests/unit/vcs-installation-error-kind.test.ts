import { describe, expect, test } from 'bun:test'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'

describe('VCS installation error kind', () => {
  test('a repository the installation no longer reaches is missing, not an empty result', () => {
    expect(
      getVcsInstallationErrorKind({
        type: 'provider_repository_not_found',
        code: 404,
        message:
          'VCS (Version Control System) repository with the requested ID could not be found.',
      }),
    ).toBe('missing')
  })

  test('installation failures keep their kinds', () => {
    expect(
      getVcsInstallationErrorKind({
        type: 'general_provider_failure',
        message: 'Reconnect the installation to restore access.',
      }),
    ).toBe('reconnect')
    expect(
      getVcsInstallationErrorKind({
        type: 'general_provider_failure',
        message: 'Bad gateway',
      }),
    ).toBe('provider')
    expect(
      getVcsInstallationErrorKind({ type: 'general_resource_locked' }),
    ).toBe('locked')
    expect(
      getVcsInstallationErrorKind({ type: 'document_not_found' }),
    ).toBeNull()
  })
})
