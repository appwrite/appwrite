import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { Loader2 } from 'lucide-react'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { ClaimProjectTransfer } from '@/components/pages/transfer/View'
import { pageTitle } from '@/lib/utils/page-title'

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

  return (
    <RequireAuth
      loadingComponent={
        <div className="flex min-h-svh items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      {() => (
        <ClaimProjectTransfer
          transferId={search.transferId ?? ''}
          secret={search.secret ?? ''}
        />
      )}
    </RequireAuth>
  )
}
