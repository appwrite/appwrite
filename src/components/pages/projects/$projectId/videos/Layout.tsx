import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import {
  Link,
  Outlet,
  useLocation,
  useNavigate,
  useParams,
} from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CreateRenditions } from './_components/CreateRenditions'
import { CreateSubtitle } from './_components/CreateSubtitle'
import type { VideoTab } from './_components/VideoContextMenu'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canCreateVideo,
  canShowVideoSettings,
} from '@/lib/console-access-checks'
import {
  useOrganizationScopes,
  useProject,
  useProjectVideo,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const VIDEO_TABS: VideoTab[] = [
  'overview',
  'renditions',
  'subtitles',
  'settings',
]

type VideoDetailActions = {
  openCreateRenditions: () => void
  openCreateSubtitle: () => void
  canWrite: boolean
}

const VideoDetailActionsContext = createContext<VideoDetailActions>({
  openCreateRenditions: () => {},
  openCreateSubtitle: () => {},
  canWrite: false,
})

export function useVideoDetailActions() {
  return useContext(VideoDetailActionsContext)
}

export function Layout() {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const location = useLocation()
  const navigate = useNavigate()
  const [renditionsOpen, setRenditionsOpen] = useState(false)
  const [subtitleOpen, setSubtitleOpen] = useState(false)

  const activeTab = useMemo<VideoTab>(() => {
    const parts = location.pathname.split('/').filter(Boolean)
    const index = parts.indexOf(videoId)
    const segment = index >= 0 ? parts[index + 1] : undefined
    return VIDEO_TABS.includes(segment as VideoTab)
      ? (segment as VideoTab)
      : 'overview'
  }, [location.pathname, videoId])

  const { data: video, isLoading } = useProjectVideo(projectId, videoId)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettings = canShowVideoSettings(access, features)
  const canWrite = canCreateVideo(access, features)

  useEffect(() => {
    if (showSettings || activeTab !== 'settings') return
    navigate({
      to: '/projects/$projectId/videos/$videoId',
      params: { projectId, videoId },
      replace: true,
    })
  }, [showSettings, activeTab, projectId, videoId, navigate])

  const tabs: Tab[] = useMemo(() => {
    const params = { projectId, videoId }
    return [
      {
        id: 'overview',
        label: t('Overview'),
        to: '/projects/$projectId/videos/$videoId',
        params,
      },
      {
        id: 'renditions',
        label: t('Renditions'),
        to: '/projects/$projectId/videos/$videoId/renditions',
        params,
      },
      {
        id: 'subtitles',
        label: t('Subtitles'),
        to: '/projects/$projectId/videos/$videoId/subtitles',
        params,
      },
      ...(showSettings
        ? [
            {
              id: 'settings',
              label: t('Settings'),
              to: '/projects/$projectId/videos/$videoId/settings',
              params,
            },
          ]
        : []),
    ]
  }, [projectId, videoId, showSettings, t])

  const actions = useMemo<VideoDetailActions>(
    () => ({
      openCreateRenditions: () => setRenditionsOpen(true),
      openCreateSubtitle: () => setSubtitleOpen(true),
      canWrite,
    }),
    [canWrite],
  )

  if (isLoading && !video) {
    return (
      <div className="flex flex-col">
        <ServiceHeader title={t('Loading...')} fullWidthBorder />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading video...')}
            </p>
          </div>
        </div>
      </div>
    )
  }

  if (!video) {
    return (
      <div className="flex flex-col">
        <ServiceHeader title={t('Video not found')} fullWidthBorder />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="mb-4 text-[13px] text-muted-foreground">
              {t(
                "The video you're looking for doesn't exist or you don't have access to it.",
              )}
            </p>
            <Button variant="outline" asChild>
              <Link to="/projects/$projectId/videos" params={{ projectId }}>
                <ArrowLeft className="me-1.5 h-4 w-4" />
                {t('Back to videos')}
              </Link>
            </Button>
          </div>
        </div>
      </div>
    )
  }

  const createLabel =
    activeTab === 'renditions'
      ? t('Create renditions')
      : activeTab === 'subtitles'
        ? t('Create subtitle')
        : undefined

  const createDisabledTooltip = !canWrite
    ? t("You don't have permission to manage videos.")
    : undefined

  return (
    <VideoDetailActionsContext.Provider value={actions}>
      <div className="flex min-h-0 flex-1 flex-col">
        <ServiceHeader
          title={
            <DetailResourceHeaderTitle
              kind="video"
              label={video.name || t('Untitled video')}
              resourceId={video.$id}
              projectId={projectId}
              back={{
                to: '/projects/$projectId/videos',
                params: { projectId },
                'aria-label': t('Back to videos'),
              }}
            />
          }
          tabs={tabs}
          activeTab={activeTab}
          fullWidthBorder
          createLabel={createLabel}
          onCreate={
            activeTab === 'renditions'
              ? () => setRenditionsOpen(true)
              : activeTab === 'subtitles'
                ? () => setSubtitleOpen(true)
                : undefined
          }
          createDisabled={!canWrite}
          createDisabledTooltip={createDisabledTooltip}
        />
        <div className="min-h-0 flex-1">
          <Outlet />
        </div>
      </div>
      <CreateRenditions
        open={renditionsOpen}
        onOpenChange={setRenditionsOpen}
        projectId={projectId}
        video={video}
      />
      <CreateSubtitle
        open={subtitleOpen}
        onOpenChange={setSubtitleOpen}
        projectId={projectId}
        videoId={videoId}
      />
    </VideoDetailActionsContext.Provider>
  )
}
