import { createFileRoute, redirect } from '@tanstack/react-router'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { organizationAppQueryOptions } from '@/lib/react-query/hooks'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'

/**
 * Org-agnostic share URL for a marketplace app. Resolves the visitor's own
 * organization and forwards to the org-scoped detail page, so the same link
 * works for users of any organization.
 *
 * The hop is deliberately invisible: redirecting from `beforeLoad` means this
 * route never renders a loader of its own (the root branded loader stays up,
 * see `shouldShowLoader`), and the app query is warmed in parallel with
 * account resolution so the destination mounts with its data already cached.
 */
export const Route = createFileRoute('/_public/marketplace/$appId')({
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context

    // Depends only on the app id, so it overlaps account/org resolution and the
    // destination's route chunk load instead of queueing behind the redirect.
    void queryClient
      .prefetchQuery(organizationAppQueryOptions(params.appId))
      .catch(() => {})

    const account = await ensureConsoleAccountQueryData(queryClient)
    if (!account) {
      // The root route owns the sign-in flow.
      throw redirect({ to: '/', replace: true })
    }

    const orgId = await resolvePostAuthOrganizationId(account, queryClient)

    // Same data the org layout loader awaits after the hop; starting it here
    // overlaps it with the navigation itself.
    void prefetchOrganizationOverviewData(queryClient, orgId).catch(() => {})

    throw redirect({
      to: '/organizations/$orgId/marketplace/$appId',
      params: { orgId, appId: params.appId },
      replace: true,
    })
  },
  component: () => null,
})
