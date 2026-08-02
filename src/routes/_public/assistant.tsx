import { createFileRoute, redirect } from '@tanstack/react-router'
import { AIChatPanelContent } from '@/components/global/providers/AIChat'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

const ASSISTANT_PAGE_DESCRIPTION =
  'Chat with the Appwrite assistant to inspect your project, explain issues, and take approved actions.'

function getAssistantPageMetaTags(siteOrigin?: string) {
  const origin = siteOrigin ?? getRequestSiteOrigin()
  return getPageMetaTags({
    title: pageTitle('Assistant'),
    description: ASSISTANT_PAGE_DESCRIPTION,
    canonical: resolveSiteAssetUrl('/assistant', origin),
    siteOrigin: origin,
  })
}

export const Route = createFileRoute('/_public/assistant')({
  ssr: true,
  component: AssistantPage,
  head: () => ({ meta: getAssistantPageMetaTags() }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().aiAssistant) {
      throw redirect({ to: '/', replace: true })
    }

    // Soft-resolve auth so the page can render for guests without a flash.
    await ensureConsoleAccountQueryData(context.queryClient)
  },
})

function AssistantPage() {
  return (
    <div className="h-[100dvh] max-h-[100dvh] overflow-hidden bg-background">
      <AIChatPanelContent variant="page" />
    </div>
  )
}
