import { createFileRoute, redirect } from '@tanstack/react-router'
import { AIChatPanelContent } from '@/components/global/providers/AIChat'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import {
  ASSISTANT_MESSAGES_PAGE_SIZE,
  assistantAutomationsQueryOptions,
  assistantConversationsQueryOptions,
  assistantMessagesQueryOptions,
  assistantModelsQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks'
import {
  parseAIChatActiveConversationId,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
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
    // Prefetch agent data when signed in so the shell does not paint empty states first.
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    await Promise.all([
      context.queryClient.ensureQueryData(assistantConversationsQueryOptions()),
      context.queryClient.ensureQueryData(assistantModelsQueryOptions()),
      context.queryClient.ensureQueryData(assistantAutomationsQueryOptions('')),
    ])

    const activeConversationId = parseAIChatActiveConversationId(
      account.prefs as UserPrefs | undefined,
    )
    if (!activeConversationId) return

    await context.queryClient
      .ensureQueryData(
        assistantMessagesQueryOptions(
          activeConversationId,
          ASSISTANT_MESSAGES_PAGE_SIZE,
        ),
      )
      .catch(() => {
        // Conversation may have been deleted; page still renders.
      })
  },
})

function AssistantPage() {
  return (
    <div className="h-[100dvh] max-h-[100dvh] overflow-hidden bg-background">
      <AIChatPanelContent variant="page" />
    </div>
  )
}
