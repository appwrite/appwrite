import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/domains/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { domainsHero } from '@/lib/domains/marketing-content'

const domainsSearchSchema = z.object({
  q: z.string().optional(),
})

export const Route = createFileRoute('/_marketing/domains')({
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    showFooter: false,
  },
  ssr: true,
  validateSearch: domainsSearchSchema,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Domains',
      description: domainsHero.description,
    }),
  }),
  component: DomainsPage,
})

function DomainsPage() {
  const { q } = Route.useSearch()
  const navigate = Route.useNavigate()

  return (
    <View
      initialSearch={q ?? ''}
      onSearchValueChange={(value) => {
        void navigate({
          search: (previous) => ({ ...previous, q: value || undefined }),
          replace: true,
          resetScroll: false,
        })
      }}
    />
  )
}
