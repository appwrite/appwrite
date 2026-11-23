import { createFileRoute } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { View } from '@/components/pages/auth/preview/View'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Appwrite redirects visitors of protected Sites previews here:
 * {console}/auth/preview?projectId={projectId}&origin={origin}&path={path}
 */
export const Route = createFileRoute('/_public/auth/preview')({
  head: () => ({ meta: [{ title: pageTitle('Preview') }] }),
  component: AuthPreviewPage,
})

function AuthPreviewPage() {
  return (
    <RequireAuth
      loadingComponent={
        <div className="flex h-[100dvh] items-center justify-center bg-background">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      }
    >
      {({ account }) => {
        const user = account as Models.User | undefined
        // The router JSON-parses search values, which rewrites custom project
        // IDs like `1e3` or `1.10`. Read the query exactly as Appwrite sent it.
        const search = new URLSearchParams(window.location.search)
        return (
          <View
            projectId={search.get('projectId') ?? undefined}
            origin={search.get('origin') ?? undefined}
            path={search.get('path') ?? undefined}
            accountLabel={user?.email || user?.name || ''}
          />
        )
      }}
    </RequireAuth>
  )
}
