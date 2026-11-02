import { createFileRoute, Navigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { z } from 'zod'
import { Loader2 } from 'lucide-react'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { TransferAuthGate } from '@/components/pages/transfer/AuthGate'
import { ClaimTransfer } from '@/components/pages/transfer/View'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { buildTransferPath } from '@/lib/transfers'
import { pageTitle } from '@/lib/utils/page-title'
import { isZeroAccount } from '@/lib/zero/session'

const transferSearchSchema = z.object({
  transferId: z.string().optional(),
  secret: z.string().optional(),
  // Project links carry no resource, so links shared before domain transfers keep working
  resource: z.enum(['projects', 'domains']).optional(),
})

export const Route = createFileRoute('/transfer')({
  validateSearch: transferSearchSchema,
  head: ({ match }) => ({
    meta: [
      {
        title: pageTitle(
          match.search.resource === 'domains'
            ? 'Transfer domain'
            : 'Transfer project',
        ),
      },
    ],
  }),
  component: TransferPage,
})

function TransferPage() {
  const search = Route.useSearch()
  const transferId = search.transferId ?? ''
  const secret = search.secret ?? ''
  const resource = search.resource ?? 'projects'
  const loading = (
    <div className="flex min-h-svh items-center justify-center bg-background">
      <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
    </div>
  )

  return (
    <RequireAuth
      loadingComponent={loading}
      fallback={
        <TransferAuthGate
          resource={resource}
          transferId={transferId}
          secret={secret}
          anonymous={false}
        />
      }
    >
      {({ account }) => {
        const user = account as Models.User | undefined
        if (isZeroAccount(user)) {
          return (
            <TransferAuthGate
              resource={resource}
              transferId={transferId}
              secret={secret}
              anonymous
            />
          )
        }
        // The page is open to guests, so the guard's verify-email redirect skips it.
        if (requiresConsoleEmailVerification(user)) {
          return (
            <Navigate
              to="/verify-email"
              search={{
                redirect: buildTransferPath(resource, transferId, secret),
              }}
              replace
            />
          )
        }
        return (
          <ClaimTransfer
            resource={resource}
            transferId={transferId}
            secret={secret}
          />
        )
      }}
    </RequireAuth>
  )
}
