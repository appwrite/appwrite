import { describe, expect, test } from 'bun:test'
import { getProviderReviewUrls } from '@/lib/vcs/providers'

const review = {
  organization: 'acme',
  repositoryName: 'api',
  pullRequestId: '7',
}

describe('Review links', () => {
  test('GitHub links the posted review, compares, and anchors lines and comments', () => {
    const urls = getProviderReviewUrls({
      ...review,
      provider: 'github',
      reviewId: '99',
    })!

    expect(urls.review).toBe(
      'https://github.com/acme/api/pull/7#pullrequestreview-99',
    )
    expect(urls.compare('a', 'b')).toBe(
      'https://github.com/acme/api/compare/a...b',
    )
    expect(urls.file('abc', 'src/index.ts', 3, 5)).toBe(
      'https://github.com/acme/api/blob/abc/src/index.ts#L3-L5',
    )
    expect(urls.comment('12')).toBe(
      'https://github.com/acme/api/pull/7#discussion_r12',
    )
  })

  test('GitLab uses the self-hosted host the installation names', () => {
    const urls = getProviderReviewUrls({
      ...review,
      provider: 'gitlab',
      organizationUrl: 'https://git.example.com/acme',
    })!

    expect(urls.pullRequest).toBe(
      'https://git.example.com/acme/api/-/merge_requests/7',
    )
    expect(urls.file('abc', 'a.ts', 3, 5)).toBe(
      'https://git.example.com/acme/api/-/blob/abc/a.ts#L3-5',
    )
    expect(urls.comment('d0c1')).toBeNull()
  })

  test('GitLab and Bitbucket fall back to their public hosts', () => {
    expect(
      getProviderReviewUrls({ ...review, provider: 'gitlab' })!.pullRequest,
    ).toBe('https://gitlab.com/acme/api/-/merge_requests/7')

    const bitbucket = getProviderReviewUrls({
      ...review,
      provider: 'bitbucket',
    })!
    expect(bitbucket.pullRequest).toBe(
      'https://bitbucket.org/acme/api/pull-requests/7',
    )
    expect(bitbucket.file('abc', 'a.ts', 3, 3)).toBe(
      'https://bitbucket.org/acme/api/src/abc/a.ts#lines-3',
    )
    expect(bitbucket.compare('a', 'b')).toBeNull()
  })

  test('Gitea needs the installation to know its host', () => {
    expect(getProviderReviewUrls({ ...review, provider: 'gitea' })).toBeNull()
    expect(
      getProviderReviewUrls({
        ...review,
        provider: 'gitea',
        organizationUrl: 'https://gitea.example.com/acme',
      })!.pullRequest,
    ).toBe('https://gitea.example.com/acme/api/pulls/7')
  })

  test('an unknown provider or a missing piece gives no links', () => {
    expect(getProviderReviewUrls({ ...review, provider: 'svn' })).toBeNull()
    expect(
      getProviderReviewUrls({
        ...review,
        provider: 'github',
        pullRequestId: '',
      }),
    ).toBeNull()
  })
})
