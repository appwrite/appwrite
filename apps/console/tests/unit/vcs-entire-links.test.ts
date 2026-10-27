import { describe, expect, test } from 'bun:test'
import {
  getProviderOwnerUrl,
  getProviderPullRequestUrl,
  isEntireRepositoryUrl,
} from '@/lib/vcs/providers'
import type { Models } from '@appwrite.io/console'

describe('Entire links', () => {
  test('repositories on entire.com, and on entire.io as older deployments recorded them, are Entire', () => {
    expect(isEntireRepositoryUrl('https://entire.com/et/acme/api')).toBe(true)
    expect(
      isEntireRepositoryUrl('https://entire.com/gh/octocat/hello-world'),
    ).toBe(true)
    expect(isEntireRepositoryUrl('https://entire.io/et/acme/api')).toBe(true)
    expect(isEntireRepositoryUrl('https://github.com/entire.com/api')).toBe(
      false,
    )
    expect(isEntireRepositoryUrl('not a url')).toBe(false)
  })

  test('trails link under the project, or under gh for a GitHub mirror', () => {
    expect(
      getProviderPullRequestUrl({
        provider: 'entire',
        organization: 'acme',
        repositoryName: 'api',
        pullRequestId: '7',
      }),
    ).toBe('https://entire.com/et/acme/api/trails/7')
    expect(
      getProviderPullRequestUrl({
        provider: 'entire',
        organization: 'gh/octocat',
        repositoryName: 'hello-world',
        pullRequestId: '7',
      }),
    ).toBe('https://entire.com/gh/octocat/hello-world/trails/7')
  })

  test('an installation from a server without organizationUrl links to its owner on entire.com', () => {
    const installation = (organization: string) =>
      ({ provider: 'entire', organization }) as unknown as Models.Installation
    expect(getProviderOwnerUrl(installation('acme'))).toBe(
      'https://entire.com/et/acme',
    )
    expect(getProviderOwnerUrl(installation('gh/octocat'))).toBe(
      'https://entire.com/gh/octocat',
    )
  })
})
