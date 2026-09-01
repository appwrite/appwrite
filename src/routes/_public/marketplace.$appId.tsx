import { createFileRoute, redirect } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'

/**
 * Org-agnostic share URL for a marketplace app. Resolves the visitor's own
 * organization and forwards to the org-scoped detail page, so the same link
 * works for users of any organization.
 */
export const Route = createFileRoute('/_public/marketplace/$appId')({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) {
      // The root route owns the sign-in flow.
      throw redirect({ to: '/', replace: true })
    }

    const orgId = await resolvePostAuthOrganizationId(
      account,
      context.queryClient,
    )
    throw redirect({
      to: '/organizations/$orgId/marketplace/$appId',
      params: { orgId, appId: params.appId },
      replace: true,
    })
  },
  component: MarketplaceShareRedirect,
})

function MarketplaceShareRedirect() {
  return (
    <div className="flex min-h-svh items-center justify-center bg-background">
      <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
    </div>
  )
}
