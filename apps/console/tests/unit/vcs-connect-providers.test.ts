import { describe, expect, test } from 'bun:test'
import {
  getVcsProviderColumns,
  getVisibleVcsOAuthProviders,
} from '@/lib/vcs/providers'

const ids = (configuredProviders: string[] | undefined) =>
  getVisibleVcsOAuthProviders(configuredProviders).map(
    (provider) => provider.id,
  )

describe('Git connect providers', () => {
  test('every provider shows, GitHub first, then Entire and Origin', () => {
    expect(ids(undefined)).toEqual([
      'github',
      'entire',
      'origin',
      'gitlab',
      'bitbucket',
      'gitea',
    ])
  })

  test('only the providers the server has configured show', () => {
    expect(ids(['origin', 'github'])).toEqual(['github', 'origin'])
    expect(ids(['github', 'gitlab'])).toEqual(['github', 'gitlab'])
    expect(ids([])).toEqual([])
  })
})

describe('Git connect provider columns', () => {
  test('rows stay balanced as providers are added', () => {
    const columns = Array.from({ length: 11 }, (_, i) =>
      getVcsProviderColumns(i + 1),
    )
    expect(columns).toEqual([1, 2, 3, 2, 3, 3, 3, 3, 3, 3, 3])
  })
})
