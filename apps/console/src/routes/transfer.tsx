import { createFileRoute, Navigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { z } from 'zod'
import { Loader2 } from 'lucide-react'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { TransferAuthGate } from '@/components/pages/transfer/AuthGate'
import { ClaimProjectTransfer } from '@/components/pages/transfer/View'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { pageTitle } from '@/lib/utils/page-title'
import { isZeroAccount } from '@/lib/zero/session'

const transferSearchSchema = z.object({
  transferId: z.string().optional(),
  secret: z.string().optional(),
})

export const Route = createFileRoute('/transfer')({
  validateSearch: transferSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Transfer project') }] }),
  component: TransferPage,
})

function TransferPage() {
  const search = Route.useSearch()
  const transferId = search.transferId ?? ''
  const secret = search.secret ?? ''
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
                redirect: `/transfer?${new URLSearchParams({ transferId, secret })}`,
              }}
              replace
            />
          )
        }
        return <ClaimProjectTransfer transferId={transferId} secret={secret} />
      }}
    </RequireAuth>
  )
}
