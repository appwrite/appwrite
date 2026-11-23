/**
 * Create Site Index Route
 *
 * Combined entry point showing templates and repositories side by side.
 */

import { createFileRoute } from '@tanstack/react-router'
import { CreateSiteView } from '@/components/pages/projects/$projectId/sites/create/CreateSiteView'
import { siteTemplatesQueryOptions } from '@/lib/react-query/hooks'
import { SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE } from '@/lib/sites/site-template-wizard'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/',
)({
  head: () => ({ meta: [{ title: pageTitle('Create', 'Sites') }] }),
  loader: ({ params, context }) => {
    if (typeof window === 'undefined') return
    void context.queryClient.prefetchQuery(
      siteTemplatesQueryOptions(
        params.projectId,
        undefined,
        undefined,
        SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE,
      ),
    )
  },
  component: CreateSitePage,
})

function CreateSitePage() {
  return <CreateSiteView />
}
