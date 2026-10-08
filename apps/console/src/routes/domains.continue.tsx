import { useEffect, useRef } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import type { Models } from '@appwrite.io/console'
import { Loader2 } from 'lucide-react'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  buildBuyDomainWizardSearch,
  type BuyDomainContinueSearch,
} from '@/lib/domains/buy-wizard'
import { ensurePersonalOrgAndFirstProject } from '@/lib/ensure-personal-org'
import { pageTitle } from '@/lib/utils/page-title'

export const domainsContinueSearchSchema = z.object({
  domain: z.string().optional(),
  stage: z.enum(['checkout']).optional(),
})

export type DomainsContinueSearch = z.infer<typeof domainsContinueSearchSchema>

export const Route = createFileRoute('/domains/continue')({
  validateSearch: domainsContinueSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('Continue domain purchase', 'Domains') }],
  }),
  component: DomainsContinuePage,
})

function DomainsContinuePage() {
  const search = Route.useSearch()

  return (
    <RequireAuth
      loadingComponent={
        <div className="flex min-h-svh items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      {({ account: accountUnknown }) => (
        <DomainsContinueRedirect
          accountOrgId={
            (accountUnknown as Models.User | undefined)?.prefs
              ?.organization as string | undefined
          }
          search={search}
        />
      )}
    </RequireAuth>
  )
}

function DomainsContinueRedirect({
  accountOrgId,
  search,
}: {
  accountOrgId?: string
  search: DomainsContinueSearch
}) {
  const navigate = useNavigate()
  const startedRef = useRef(false)

  useEffect(() => {
    if (startedRef.current) return
    startedRef.current = true

    ;(async () => {
      const wizardSearch = buildBuyDomainWizardSearch(search as BuyDomainContinueSearch)
      let orgId = accountOrgId

      if (!orgId) {
        try {
          orgId = await ensurePersonalOrgAndFirstProject()
        } catch {
          navigate({ to: '/account', replace: true })
          return
        }
      }

      navigate({
        to: '/organizations/$orgId/domains/buy',
        params: { orgId },
        search: wizardSearch,
        replace: true,
      })
    })()
  }, [accountOrgId, navigate, search])

  return (
    <div className="flex min-h-svh items-center justify-center bg-background">
      <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
    </div>
  )
}
