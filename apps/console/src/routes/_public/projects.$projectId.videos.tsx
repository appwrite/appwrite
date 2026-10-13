import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { VideoInspectorProvider } from '@/components/pages/projects/$projectId/videos/_components/player/VideoInspectorContext'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/videos')({
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().videos) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Videos') }] }),
  component: VideosLayout,
})

function VideosLayout() {
  return (
    <VideoInspectorProvider>
      <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col">
        <Outlet />
      </div>
    </VideoInspectorProvider>
  )
}
