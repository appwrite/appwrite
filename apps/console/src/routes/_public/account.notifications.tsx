import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { AccountNotifications } from '@/components/pages/account/Notifications'

export const Route = createFileRoute('/_public/account/notifications')({
  head: () => ({ meta: [{ title: pageTitle('Notifications', 'Account') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().browserAlerts) {
      throw redirect({ to: '/account', replace: true })
    }
  },
  component: AccountNotificationsPage,
})

function AccountNotificationsPage() {
  return <AccountNotifications />
}
