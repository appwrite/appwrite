import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/organizations/$orgId/settings')({
  loader: async ({ context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })
  },
  component: SettingsPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function SettingsPage() {
  return null
}
