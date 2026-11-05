import { describe, expect, spyOn, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { QueryClient } from '@tanstack/react-query'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'
import { rewriteLegacyConsolePath } from '@/lib/legacy-console-path'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import { MARKETING_PAGE_PATHS } from '@/lib/marketing/marketing-page-paths'
import * as organizationOverview from '@/lib/organization-overview-prefetch'
import {
  prefetchPostAuthDestination,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'

describe('GitHub Education entry points', () => {
  test('sends the legacy education sign-up URL to the enrollment flow', () => {
    expect(rewriteLegacyConsolePath('/console/education')).toBe(
      EDUCATION_JOIN_PATH,
    )
    expect(rewriteLegacyConsolePath('/console/education/error')).toBe(
      EDUCATION_JOIN_PATH,
    )
    expect(rewriteLegacyConsolePath('/console/education/')).toBe(
      EDUCATION_JOIN_PATH,
    )
  })

  test('sends legacy console onboarding to the home page', () => {
    expect(rewriteLegacyConsolePath('/console/onboarding/create-project')).toBe(
      '/',
    )
  })

  test('leaves the marketing education page at the root path', () => {
    expect(rewriteLegacyConsolePath('/education')).toBe('/education')
    expect(MARKETING_PAGE_PATHS).toContain('/education')
  })

  test('keeps the enrollment flow off the marketing host rules', () => {
    // A marketing path would be dropped by resolvePostAuthRedirect and
    // prerendered as static HTML, neither of which suits an auth flow.
    expect(isMarketingPagePath(EDUCATION_JOIN_PATH)).toBe(false)
  })

  test('preserves the enrollment destination after authentication', () => {
    const destination = `${EDUCATION_JOIN_PATH}?status=failure`
    expect(resolvePostAuthRedirect(destination)).toBe(destination)
    expect(toRedirectNavigateOptions(destination)).toEqual({
      to: EDUCATION_JOIN_PATH,
      search: { status: 'failure' },
    })
  })

  test('lets education enrollment provision its own organization', async () => {
    const prefetchDefault = spyOn(
      organizationOverview,
      'resolveAndPrefetchDefaultOrganization',
    ).mockResolvedValue('default-org')
    try {
      const queryClient = new QueryClient()
      const account = { prefs: {} } as Models.User
      for (const destination of [
        EDUCATION_JOIN_PATH,
        `${EDUCATION_JOIN_PATH}/?status=failure`,
      ]) {
        await prefetchPostAuthDestination(queryClient, account, destination)
      }
      expect(prefetchDefault).not.toHaveBeenCalled()
      await prefetchPostAuthDestination(queryClient, account, '/')
      expect(prefetchDefault).toHaveBeenCalledTimes(1)
    } finally {
      prefetchDefault.mockRestore()
    }
  })
})
