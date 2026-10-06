import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/education/View'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/education')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/education',
      pageName: 'Education',
      description:
        'Students can expand their skillset without spending a penny. Sign up for the Appwrite Education program to get six months of Pro resources on Appwrite Cloud.',
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: EducationPage,
})

function EducationPage() {
  return (<View />
    )
}
