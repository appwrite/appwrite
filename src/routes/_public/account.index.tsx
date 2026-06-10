import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/account/')({
  head: () => ({ meta: [{ title: pageTitle('General', 'Account') }] }),
  component: AccountGeneralPage,
})

function AccountGeneralPage() {
  return null
}
