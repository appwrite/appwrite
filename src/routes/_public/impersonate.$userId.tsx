import { createFileRoute } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/impersonate/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/impersonate/$userId')({
  head: () => ({ meta: [{ title: pageTitle('Impersonate user') }] }),
  component: ImpersonatePage,
})

function ImpersonatePage() {
  const { userId } = Route.useParams()

  return (
    <RequireAuth
      loadingComponent={
        <div className="flex h-[100dvh] items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      <View userId={userId} />
    </RequireAuth>
  )
}
