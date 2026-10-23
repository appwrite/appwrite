import { describe, expect, test } from 'bun:test'
import { CONSOLE_PROFILES } from '@/lib/console-profiles'
import { getVisibleVcsOAuthProviders } from '@/lib/vcs/providers'

const ids = (features: { extraVcsOAuth: boolean; vcsEntire: boolean }) =>
  getVisibleVcsOAuthProviders(features).map((provider) => provider.id)

describe('Git connect providers', () => {
  test('both profiles hide Origin and Entire by default', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(ids(profile.features)).toEqual(['github', 'gitlab', 'bitbucket'])
    }
  })

  test('vcsEntire shows only Entire, second after GitHub', () => {
    expect(ids({ extraVcsOAuth: false, vcsEntire: true })).toEqual([
      'github',
      'entire',
      'gitlab',
      'bitbucket',
    ])
  })

  test('extraVcsOAuth shows only Origin', () => {
    expect(ids({ extraVcsOAuth: true, vcsEntire: false })).toEqual([
      'github',
      'origin',
      'gitlab',
      'bitbucket',
    ])
  })
})
