import { createFileRoute, redirect, isRedirect } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { AlertTriangle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'
import { setAuthAudience } from '@/lib/auth-audience'
import {
  PartnerOrganizationRequiredError,
  resolvePostAuthOrganizationId,
} from '@/lib/ensure-personal-org'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { organizationHomeLink } from '@/lib/partners'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/partner')({
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    setAuthAudience('partner')
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) {
      throw redirect({
        to: '/sign-in',
        search: { audience: 'partner' },
        replace: true,
      })
    }
    if (requiresConsoleEmailVerification(account)) {
      throw redirect({ to: '/verify-email', replace: true })
    }
    try {
      const refreshed = await refreshConsoleAccountAfterAuth(
        context.queryClient,
      )
      const orgId = await resolvePostAuthOrganizationId(
        refreshed,
        context.queryClient,
        { audience: 'partner' },
      )
      throw redirect({
        ...organizationHomeLink(orgId, true),
        replace: true,
      })
    } catch (error) {
      if (isRedirect(error)) throw error
      if (error instanceof PartnerOrganizationRequiredError) {
        return { missingPartnerOrganization: true as const }
      }
      throw redirect({ to: CONSOLE_ENTRY_PATH, replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Partner login') }] }),
  component: PartnerEntryPage,
})

function PartnerEntryPage() {
  const t = useT()
  const loaderData = Route.useLoaderData()

  if (!loaderData?.missingPartnerOrganization) {
    return null
  }

  return (
    <RequireAuth>
      <div className="flex min-h-svh w-full flex-col items-center justify-center bg-background px-4 py-8">
        <div className="flex w-full max-w-md flex-col items-center gap-8">
          <div className="rounded-full bg-destructive/10 p-3">
            <AlertTriangle
              className="h-8 w-8 text-destructive"
              aria-hidden
            />
          </div>
          <div className="space-y-3 text-center">
            <h1 className="text-2xl font-semibold">
              {t('No partner organization found')}
            </h1>
            <p className="text-sm leading-relaxed text-muted-foreground">
              {t('This account is not a member of a partner organization.')}
            </p>
          </div>
          <div className="flex w-full flex-col gap-3 sm:flex-row">
            <Button asChild className="w-full sm:flex-1">
              <Link to="/">{t('Go to console')}</Link>
            </Button>
            <Button asChild variant="outline" className="w-full sm:flex-1">
              <Link to="/sign-in" search={{ audience: 'partner' }}>
                {t('Partner login')}
              </Link>
            </Button>
          </div>
        </div>
      </div>
    </RequireAuth>
  )
}
