import { createFileRoute, redirect } from '@tanstack/react-router'
import { UsageCategoryView } from '@/components/pages/projects/$projectId/usage/CategoryView'
import { useUsageFilters } from '@/components/pages/projects/$projectId/usage/usage-filters-context'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema } from '@/lib/table-filters'
import {
  findUsageCategory,
  getDefaultUsageCategoryId,
  getUsageCategories,
  isValidUsageCategory,
} from '@/components/pages/projects/$projectId/usage/usage-nav'

export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/$categoryId',
)({
  validateSearch: listSearchSchema,
  beforeLoad: ({ params }) => {
    const { projectId, categoryId } = params
    if (!isValidUsageCategory(categoryId)) {
      throw redirect({
        to: '/projects/$projectId/usage/$categoryId',
        params: {
          projectId,
          categoryId: getDefaultUsageCategoryId(),
        },
        replace: true,
      })
    }
  },
  head: ({ params }) => {
    const category = findUsageCategory(
      getUsageCategories('pro'),
      params.categoryId,
    )
    const title = category?.label ?? 'Usage'

    return { meta: [{ title: pageTitle(title) }] }
  },
  component: UsageCategoryPage,
})

function UsageCategoryPage() {
  const { projectId, categoryId } = Route.useParams()
  const { plan, dateRange, chartInterval } = useUsageFilters()

  return (
    <UsageCategoryView
      projectId={projectId}
      categoryId={categoryId}
      plan={plan}
      dateRange={dateRange}
      chartInterval={chartInterval}
    />
  )
}
