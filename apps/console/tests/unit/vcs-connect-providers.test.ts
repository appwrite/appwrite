import { describe, expect, test } from 'bun:test'
import { CONSOLE_PROFILES } from '@/lib/console-profiles'
import { getVisibleVcsOAuthProviders } from '@/lib/vcs/providers'

const ids = (features: { extraVcsOAuth: boolean }) =>
  getVisibleVcsOAuthProviders(features).map((provider) => provider.id)

describe('Git connect providers', () => {
  test('both profiles show Entire, second after GitHub, and hide Origin', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(ids(profile.features)).toEqual([
        'github',
        'entire',
        'gitlab',
        'bitbucket',
      ])
    }
  })

  test('extraVcsOAuth adds Origin', () => {
    expect(ids({ extraVcsOAuth: true })).toEqual([
      'github',
      'entire',
      'origin',
      'gitlab',
      'bitbucket',
    ])
  })
})
