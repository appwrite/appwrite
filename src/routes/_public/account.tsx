import { View } from '@/components/pages/account/View'
import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'

export const Route = createFileRoute('/_public/account')({
  component: AccountPage,
})

function AccountPage() {
  return (
    <RequireAuth>
      <View />
    </RequireAuth>
  )
}
