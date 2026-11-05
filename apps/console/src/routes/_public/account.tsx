import { View } from '@/components/pages/account/View'
import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { NotFoundView } from '@/components/error/NotFound'

export const Route = createFileRoute('/_public/account')({
  // Account View already provides ConsoleLayout; avoid a nested shell.
  notFoundComponent: NotFoundView,
  component: AccountLayout,
})

function AccountLayout() {
  return (
    <RequireAuth>
      <View />
    </RequireAuth>
  )
}
