import { View } from '@/components/pages/account/View'
import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/account')({
  component: AccountPage,
  head: () => ({ meta: [{ title: pageTitle('Account') }] }),
})

function AccountPage() {
  return (
    <RequireAuth>
      <View />
    </RequireAuth>
  )
}
