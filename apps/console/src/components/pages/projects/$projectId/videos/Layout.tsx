import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  Link,
  Outlet,
  useLocation,
  useNavigate,
  useParams,
} from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Check, Loader2, Minus, SquareArrowOutUpRight } from 'lucide-react'
import { CreateRenditions } from './_components/CreateRenditions'
import { VideoRenditionsProgressHeaderAlert } from './_components/VideoRenditionsProgressHeaderAlert'
import { CreateSubtitle } from './_components/CreateSubtitle'
import { VideoStatusBadge } from './_components/VideoStatusBadge'
import { VideoTermHint } from './_components/VideoTermHint'
import { VideoThumb } from './_components/VideoThumb'
import {
  VideoDetailActionsContext,
  type VideoDetailActions,
} from './_components/video-detail-actions'
import {
  getVideoTabDisabledReason,
  VIDEO_INSPECTOR_ACTION,
  VIDEO_TAB_GROUP_LABELS,
  VIDEO_TABS,
  videoTabPath,
  type VideoTab,
  type VideoTabDefinition,
  type VideoTabGroup,
} from './_components/video-tabs'
import { useVideoInspector } from './_components/player/VideoInspectorContext'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canCreateVideo,
  canShowVideoSettings,
} from '@/lib/console-access-checks'
import {
  SECONDARY_SIDEBAR_GROUP_HEADING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  getVideoEncodingStatus,
  isVideoRenditionActive,
  useOrganizationScopes,
  useProject,
  useProjectVideo,
  useVideoRenditions,
  useVideoSubtitles,
  useVideoTimeline,
  type VideoEncodingStatus,
  type VideoTimelineCue,
} from '@/lib/react-query/hooks'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const TAB_GROUPS: VideoTabGroup[] = ['video', 'encoding', 'delivery', 'manage']

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
    return VIDEO_TABS.some((tab) => tab.id === segment)
      ? (segment as VideoTab)
      : 'overview'
  }, [location.pathname, videoId])

  const { data: video, isLoading } = useProjectVideo(projectId, videoId)
  const inspector = useVideoInspector()
  const openInspector = inspector
    ? () => {
        inspector.open({
          projectId,
          videoId,
          videoName: video?.name ?? '',
        })
        if (activeTab !== 'overview') {
          void navigate({
            to: '/projects/$projectId/videos/$videoId',
            params: { projectId, videoId },
          })
        }
      }
    : undefined
  const { data: renditionsData } = useVideoRenditions(projectId, videoId)
  const { data: subtitlesData } = useVideoSubtitles(projectId, videoId)
  const { data: timeline, isLoading: timelineLoading } = useVideoTimeline(
    projectId,
    videoId,
  )

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettings = canShowVideoSettings(access, features)
  const canWrite = canCreateVideo(access, features)
  const writeDisabledReason = canWrite
    ? null
    : t("You don't have permission to manage videos.")

  useEffect(() => {
    if (showSettings || activeTab !== 'settings') return
    navigate({
      to: '/projects/$projectId/videos/$videoId',
      params: { projectId, videoId },
      replace: true,
    })
  }, [showSettings, activeTab, projectId, videoId, navigate])

  const actions = useMemo<VideoDetailActions>(
    () => ({
      openCreateRenditions: () => setRenditionsOpen(true),
      openCreateSubtitle: () => setSubtitleOpen(true),
      canWrite,
      writeDisabledReason,
    }),
    [canWrite, writeDisabledReason],
  )

  const tabs = useMemo(
    () => VIDEO_TABS.filter((tab) => tab.id !== 'settings' || showSettings),
    [showSettings],
  )

  const renditionsLoaded = renditionsData !== undefined
  const activeTabDisabled =
    renditionsLoaded &&
    getVideoTabDisabledReason(activeTab, renditionsData.renditions) !== null

  useEffect(() => {
    if (!activeTabDisabled) return
    navigate({
      to: '/projects/$projectId/videos/$videoId',
      params: { projectId, videoId },
      replace: true,
    })
  }, [activeTabDisabled, projectId, videoId, navigate])

  if (isLoading && !video) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading video...')}
        </p>
      </div>
    )
  }

  if (!video) {
    return (
      <div className="flex h-full items-center justify-center px-6">
        <div className="max-w-sm text-center">
          <h2 className="text-[15px] font-semibold text-foreground">
            {t('Video not found')}
          </h2>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              "The video you're looking for doesn't exist or you don't have access to it.",
            )}
          </p>
        </div>
      </div>
    )
  }

  const renditions = renditionsData?.renditions ?? []
  const subtitles = subtitlesData?.subtitles ?? []
  const encodingStatus = getVideoEncodingStatus(renditions)

  return (
    <VideoDetailActionsContext.Provider value={actions}>
      <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
        <VideoRenditionsProgressHeaderAlert
          projectId={projectId}
          videoId={videoId}
          renditions={renditions}
        />
        <div className="flex min-h-0 min-w-0 flex-1">
          <aside className="hidden w-[248px] shrink-0 flex-col border-e border-border bg-background lg:flex">
            <SubnavHeader projectId={projectId} video={video} />
            <nav
              aria-label={t('Video sections')}
              className="min-h-0 flex-1 space-y-4 overflow-y-auto px-2 py-3"
            >
              {TAB_GROUPS.map((group) => {
                const groupTabs = tabs.filter((tab) => tab.group === group)
                if (groupTabs.length === 0) return null
                const heading = VIDEO_TAB_GROUP_LABELS[group]
                return (
                  <div key={group}>
                    {heading ? (
                      <p className={SECONDARY_SIDEBAR_GROUP_HEADING_CLASS}>
                        {t(heading)}
                      </p>
                    ) : null}
                    <div className="space-y-0.5">
                      {groupTabs.map((tab) => (
                        <SubnavLink
                          key={tab.id}
                          tab={tab}
                          active={activeTab === tab.id}
                          projectId={projectId}
                          video={video}
                          renditions={renditions}
                          subtitles={subtitles}
                          timeline={timeline}
                          timelineLoading={timelineLoading}
                          encodingStatus={encodingStatus}
                        />
                      ))}
                      {group === VIDEO_INSPECTOR_ACTION.group &&
                      openInspector ? (
                        <InspectorNavButton onOpen={openInspector} />
                      ) : null}
                    </div>
                  </div>
                )
              })}
            </nav>
          </aside>

          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <div className="shrink-0 border-b border-border lg:hidden">
              <div className="flex items-center gap-2 px-4 pt-3">
                <span className="min-w-0 truncate text-[14px] font-semibold text-foreground">
                  {video.name || t('Untitled video')}
                </span>
                <VideoStatusBadge status={encodingStatus} />
              </div>
              <nav
                aria-label={t('Video sections')}
                className="flex gap-1 overflow-x-auto px-3 py-2 [scrollbar-width:none]"
              >
                {tabs.map((tab) => {
                  const Icon = tab.icon
                  const disabledReason = getVideoTabDisabledReason(
                    tab.id,
                    renditions,
                  )
                  if (disabledReason) {
                    return (
                      <DisabledTabHint key={tab.id} reason={t(disabledReason)}>
                        <span
                          aria-disabled
                          className={secondarySidebarNavLinkClassName(
                            false,
                            'flex shrink-0 cursor-not-allowed items-center gap-1.5 opacity-50',
                          )}
                        >
                          <Icon className="h-3.5 w-3.5" />
                          {t(tab.label)}
                        </span>
                      </DisabledTabHint>
                    )
                  }
                  return (
                    <Link
                      key={tab.id}
                      to={videoTabPath(tab.id)}
                      params={{ projectId, videoId }}
                      className={secondarySidebarNavLinkClassName(
                        activeTab === tab.id,
                        'flex shrink-0 items-center gap-1.5',
                      )}
                    >
                      <Icon className="h-3.5 w-3.5" />
                      {t(tab.label)}
                      {tab.id === 'renditions' && tab.term ? (
                        <VideoTermHint term={tab.term} side="bottom" />
                      ) : null}
                    </Link>
                  )
                })}
                {openInspector ? (
                  <button
                    type="button"
                    onClick={openInspector}
                    className={secondarySidebarNavLinkClassName(
                      false,
                      'flex shrink-0 items-center gap-1.5',
                    )}
                  >
                    <VIDEO_INSPECTOR_ACTION.icon className="h-3.5 w-3.5" />
                    {t(VIDEO_INSPECTOR_ACTION.label)}
                    <SquareArrowOutUpRight className="h-3 w-3 text-muted-foreground" />
                  </button>
                ) : null}
              </nav>
            </div>
            <div className="min-h-0 flex-1 overflow-hidden">
              <Outlet />
            </div>
          </div>
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

function SubnavHeader({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  return (
    <div className="shrink-0 space-y-2 border-b border-border p-3">
      <VideoThumb
        projectId={projectId}
        video={video}
        width={420}
        showDuration
        className="aspect-video w-full rounded-md border border-border/60"
      />
      <p
        className="truncate text-[13px] font-medium text-foreground"
        title={video.name}
      >
        {video.name || t('Untitled video')}
      </p>
    </div>
  )
}

function SubnavLink({
  tab,
  active,
  projectId,
  video,
  renditions,
  subtitles,
  timeline,
  timelineLoading,
  encodingStatus,
}: {
  tab: VideoTabDefinition
  active: boolean
  projectId: string
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
  timeline:
    | { url: string; vtt: string; cues: VideoTimelineCue[] }
    | null
    | undefined
  timelineLoading: boolean
  encodingStatus: VideoEncodingStatus
}) {
  const t = useT()
  const Icon = tab.icon
  const disabledReason = getVideoTabDisabledReason(tab.id, renditions)
  if (disabledReason) {
    return (
      <DisabledTabHint reason={t(disabledReason)}>
        <span
          aria-disabled
          className={cn(
            secondarySidebarNavLinkClassName(false),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
            'cursor-not-allowed opacity-50 hover:bg-transparent',
          )}
        >
          <Icon className="h-3.5 w-3.5 shrink-0" />
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
            {t(tab.label)}
          </span>
        </span>
      </DisabledTabHint>
    )
  }
  return (
    <Link
      to={videoTabPath(tab.id)}
      params={{ projectId, videoId: video.$id }}
      className={cn(
        secondarySidebarNavLinkClassName(active),
        SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
      )}
    >
      <Icon className="h-3.5 w-3.5 shrink-0" />
      {tab.id === 'renditions' && tab.term ? (
        <span className="flex min-w-0 flex-1 items-center gap-1">
          <span className="min-w-0 truncate text-start">{t(tab.label)}</span>
          <VideoTermHint term={tab.term} side="right" />
        </span>
      ) : (
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
          {t(tab.label)}
        </span>
      )}
      <SubnavTrailing
        tab={tab.id}
        video={video}
        renditions={renditions}
        subtitles={subtitles}
        timeline={timeline}
        timelineLoading={timelineLoading}
        encodingStatus={encodingStatus}
      />
    </Link>
  )
}

function InspectorNavButton({ onOpen }: { onOpen: () => void }) {
  const t = useT()
  const Icon = VIDEO_INSPECTOR_ACTION.icon
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        secondarySidebarNavLinkClassName(false),
        SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
      )}
    >
      <Icon className="h-3.5 w-3.5 shrink-0" />
      <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
        {t(VIDEO_INSPECTOR_ACTION.label)}
      </span>
      <SquareArrowOutUpRight
        aria-hidden
        className="h-3 w-3 shrink-0 text-muted-foreground"
      />
    </button>
  )
}

function DisabledTabHint({
  reason,
  children,
}: {
  reason: string
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent side="right" className="text-[12px]">
        {reason}
      </TooltipContent>
    </Tooltip>
  )
}

function SubnavTrailing({
  tab,
  video,
  renditions,
  subtitles,
  timeline,
  timelineLoading,
  encodingStatus,
}: {
  tab: VideoTab
  video: Models.Video
  renditions: Models.VideoRendition[]
  subtitles: Models.VideoSubtitle[]
  timeline:
    | { url: string; vtt: string; cues: VideoTimelineCue[] }
    | null
    | undefined
  timelineLoading: boolean
  encodingStatus: VideoEncodingStatus
}) {
  const t = useT()
  const countClass = 'shrink-0 text-[11px] tabular-nums text-muted-foreground'
  const dot = (tone: 'success' | 'warning' | 'error', label: string) => (
    <span
      role="img"
      aria-label={label}
      title={label}
      className={cn(
        'h-1.5 w-1.5 shrink-0 rounded-full',
        tone === 'success' && 'bg-emerald-500',
        tone === 'warning' && 'bg-amber-500',
        tone === 'error' && 'bg-red-500',
      )}
    />
  )

  switch (tab) {
    case 'overview':
      return <VideoStatusBadge status={encodingStatus} />
    case 'renditions': {
      if (renditions.length === 0) return null
      const ready = renditions.filter((r) => r.status === 'ready').length
      const total = renditions.length
      const encoding = renditions.some((r) => isVideoRenditionActive(r.status))
      const hasFailed = renditions.some((r) => r.status === 'error')
      return (
        <span className="flex shrink-0 items-center gap-1.5">
          {encoding ? (
            <Loader2
              className="h-3 w-3 shrink-0 animate-spin text-muted-foreground"
              aria-hidden
            />
          ) : hasFailed ? (
            dot('error', t('Some renditions failed'))
          ) : null}
          <span
            className={countClass}
            title={t('{ready} of {total} ready')
              .replace('{ready}', String(ready))
              .replace('{total}', String(total))}
          >
            {ready}/{total}
          </span>
        </span>
      )
    }
    case 'subtitles':
      if (
        subtitles.some((s) => s.status === 'pending' || s.status === 'started')
      ) {
        return <Loader2 className="h-3 w-3 shrink-0 animate-spin" />
      }
      return subtitles.length > 0 ? (
        <span className={countClass}>{subtitles.length}</span>
      ) : null
    case 'timeline': {
      if (timelineLoading && timeline === undefined) return null
      const hasTimeline = Boolean(timeline && timeline.cues.length > 0)
      if (hasTimeline) {
        return (
          <Check
            className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
            aria-label={t('Timeline generated')}
          />
        )
      }
      return (
        <Minus
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45"
          aria-label={t('No timeline yet')}
        />
      )
    }
    case 'streaming':
      return renditions.some((r) => r.status === 'ready')
        ? dot('success', t('Ready to stream'))
        : null
    default:
      return null
  }
}
