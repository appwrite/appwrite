import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/domains/View'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { domainsHero } from '@/lib/domains/marketing-content'

const domainsSearchSchema = z.object({
  q: z.string().optional(),
})

export const Route = createFileRoute('/domains')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: domainsSearchSchema,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Domains',
      description: domainsHero.description,
    }),
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: DomainsPage,
})

function DomainsPage() {
  const { q } = Route.useSearch()

  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout
        header={{
          marketingNav: true,
        }}
        showFooter={false}
      >
        <View initialSearch={q?.trim() ?? ''} />
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
