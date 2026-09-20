import { createFileRoute } from '@tanstack/react-router'
import { DebugMenuCommunityShareExamplesPanel } from '@/components/global/providers/DebugMenuCommunityShareExamplesPanel'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/community-share-examples')({
  head: () => ({
    meta: [{ title: pageTitle('Community share examples (debug)') }],
  }),
  component: CommunityShareExamplesDebugPage,
})

function CommunityShareExamplesDebugPage() {
  const { profileId } = useConsoleProfile()

  return (
    <div className="relative h-full overflow-y-auto bg-background">
      <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
        <DebugMenuCommunityShareExamplesPanel activeProfileId={profileId} />
      </div>
    </div>
  )
}
