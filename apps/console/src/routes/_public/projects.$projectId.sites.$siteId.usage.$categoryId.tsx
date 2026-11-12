import { createFileRoute, redirect } from '@tanstack/react-router'
import { resourceUsageCategories } from '@/components/pages/projects/$projectId/shared/ResourceUsage'

/** Names the open category; the parent Usage route renders it. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/usage/$categoryId',
)({
  beforeLoad: ({ params }) => {
    const categories: string[] = resourceUsageCategories('site')
    if (!categories.includes(params.categoryId)) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId/usage',
        params,
        replace: true,
      })
    }
  },
  component: () => null,
})
