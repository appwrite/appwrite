import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { BlocksConsoleView } from '@/components/pages/blocks/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/blocks')({
  component: BlocksPage,
  head: () => ({ meta: [{ title: pageTitle('Blocks') }] }),
})

function BlocksPage() {
  return (
    <RequireAuth>
      <BlocksConsoleView />
    </RequireAuth>
  )
}
