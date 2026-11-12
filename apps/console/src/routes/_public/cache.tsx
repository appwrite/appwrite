import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { CacheConsoleView } from '@/components/pages/cache/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/cache')({
  component: CachePage,
  head: () => ({ meta: [{ title: pageTitle('Cache') }] }),
})

function CachePage() {
  return (
    <RequireAuth>
      <CacheConsoleView />
    </RequireAuth>
  )
}
