import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { organizationsQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/organizations/$orgId/settings')({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Organization') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context

    await queryClient.prefetchQuery(organizationsQueryOptions())
  },
  component: SettingsPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function SettingsPage() {
  return null
}
