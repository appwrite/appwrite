import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useParams } from '@tanstack/react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { ChevronLeft, ChevronRight, Layers, Plus } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  useOrganizationScopes,
  useProject,
  useVideoProfiles,
  videosQueryOptions,
  VIDEOS_DEFAULT_SORT_BY,
  VIDEOS_DEFAULT_SORT_ORDER,
  VIDEOS_SIDEBAR_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { formatVideoDuration } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { DatabaseSidebarTableSearch } from '../../databases/_components/DatabaseSidebarTableSearch'
import { CreateVideo } from './CreateVideo'
import { VideoContextMenu } from './VideoContextMenu'
import { VideoTermHint } from './VideoTermHint'
import { VideoThumb } from './VideoThumb'

type SortOrder = 'asc' | 'desc'
type SortField =
  | 'name'
  | '$createdAt'
  | '$updatedAt'
  | 'duration'
  | 'height'
  | 'size'

const SORT_OPTIONS: Array<{ by: SortField; order: SortOrder; label: string }> =
  [
    { by: '$createdAt', order: 'desc', label: 'Created (newest first)' },
    { by: '$createdAt', order: 'asc', label: 'Created (oldest first)' },
    { by: '$updatedAt', order: 'desc', label: 'Updated (newest first)' },
    { by: '$updatedAt', order: 'asc', label: 'Updated (oldest first)' },
    { by: 'name', order: 'asc', label: 'Name (A → Z)' },
    { by: 'name', order: 'desc', label: 'Name (Z → A)' },
    { by: 'duration', order: 'desc', label: 'Duration (longest first)' },
    { by: 'duration', order: 'asc', label: 'Duration (shortest first)' },
    { by: 'height', order: 'desc', label: 'Resolution (highest first)' },
    { by: 'height', order: 'asc', label: 'Resolution (lowest first)' },
    { by: 'size', order: 'desc', label: 'Size (largest first)' },
    { by: 'size', order: 'asc', label: 'Size (smallest first)' },
  ]

const LIST_GRID_CLASS =
  'grid items-center gap-x-3 grid-cols-[minmax(0,1fr)_52px]'

export function VideosSidebar() {
  const t = useT()
  const { projectId, videoId: activeVideoId } = useParams({
    strict: false,
  }) as { projectId: string; videoId?: string }
  const location = useLocation()
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [sortBy, setSortBy] = useState<string>(VIDEOS_DEFAULT_SORT_BY)
  const [sortOrder, setSortOrder] = useState<SortOrder>(
    VIDEOS_DEFAULT_SORT_ORDER,
  )
  const [createOpen, setCreateOpen] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebouncedSearch(search.trim()),
      300,
    )
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch, sortBy, sortOrder])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateVideo(access, features)

  const { data, isFetching, refetch } = useQuery({
    ...videosQueryOptions(
      projectId,
      page - 1,
      VIDEOS_SIDEBAR_PAGE_SIZE,
      debouncedSearch || undefined,
      undefined,
      sortBy,
      sortOrder,
    ),
    placeholderData: keepPreviousData,
  })
  const videos = useMemo(
    () => (data?.videos ?? []) as Models.Video[],
    [data?.videos],
  )
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / VIDEOS_SIDEBAR_PAGE_SIZE))
  const hasSearch = debouncedSearch.length > 0

  const { data: profilesData } = useVideoProfiles(projectId)
  const profilesActive = location.pathname.endsWith('/videos/profiles')

  return (
    <div className="@container flex h-full min-h-0 min-w-0 flex-col">
      <SidebarTabs />

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="shrink-0 px-3 pt-3">
          {noCreatePermission ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="block w-full">
                  <Button
                    variant="brandCta"
                    size="sm"
                    className="h-9 w-full gap-1.5 text-[13px] font-medium"
                    type="button"
                    disabled
                    {...analyticsAttrs('create-video')}
                  >
                    <Plus className="h-4 w-4" />
                    {t('Create video')}
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent side="right">
                {t("You don't have permission to create videos.")}
              </TooltipContent>
            </Tooltip>
          ) : (
            <Button
              variant="brandCta"
              size="sm"
              className="h-9 w-full gap-1.5 text-[13px] font-medium"
              type="button"
              onClick={() => setCreateOpen(true)}
              {...analyticsAttrs('create-video')}
            >
              <Plus className="h-4 w-4" />
              {t('Create video')}
            </Button>
          )}
        </div>

        <div className="shrink-0 border-b border-border px-3 pb-3 pt-2.5">
          <DatabaseSidebarTableSearch
            value={search}
            onChange={setSearch}
            placeholder={t('Search videos...')}
            clearAriaLabel={t('Clear search')}
            isFetching={isFetching}
            onRefresh={() => void refetch()}
            sortAriaLabel={t('Sort videos')}
            sortTooltip={t('Sort by attribute and direction')}
            sortMenu={
              <DropdownMenuContent align="end" className="w-60">
                <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Sort videos')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                  value={`${sortBy}:${sortOrder}`}
                  onValueChange={(value) => {
                    const option = SORT_OPTIONS.find(
                      (o) => `${o.by}:${o.order}` === value,
                    )
                    if (!option) return
                    setSortBy(option.by)
                    setSortOrder(option.order)
                  }}
                >
                  {SORT_OPTIONS.map((option, index) => (
                    <div key={`${option.by}:${option.order}`}>
                      {index > 0 && index % 2 === 0 ? (
                        <DropdownMenuSeparator />
                      ) : null}
                      <DropdownMenuRadioItem
                        value={`${option.by}:${option.order}`}
                        className="text-[13px]"
                      >
                        {t(option.label)}
                      </DropdownMenuRadioItem>
                    </div>
                  ))}
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            }
          />
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto">
          {isFetching && videos.length === 0 ? (
            <div className="p-2 text-center text-[12px] text-muted-foreground">
              {t('Loading…')}
            </div>
          ) : videos.length === 0 ? (
            <div className="px-3 py-4 text-center text-[12px] text-muted-foreground">
              {hasSearch
                ? t('No videos match your search.')
                : t('No videos yet. Create one from a Storage file.')}
            </div>
          ) : (
            <div role="table" aria-label={t('On demand')}>
              <div className="space-y-1 px-3 py-2">
                {videos.map((video) => (
                  <VideoContextMenu
                    key={video.$id}
                    projectId={projectId}
                    video={{ $id: video.$id, name: video.name }}
                  >
                    <Link
                      role="row"
                      to="/projects/$projectId/videos/$videoId"
                      params={{ projectId, videoId: video.$id }}
                      className={secondarySidebarNavLinkClassName(
                        activeVideoId === video.$id,
                        cn(LIST_GRID_CLASS, 'px-2 py-2 font-normal'),
                      )}
                    >
                      <span
                        role="cell"
                        className="flex min-w-0 items-center gap-3"
                      >
                        <VideoThumb
                          projectId={projectId}
                          video={video}
                          width={160}
                          className="aspect-video w-14 shrink-0 rounded border border-border/60 [&_svg]:h-3.5 [&_svg]:w-3.5"
                        />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-[13px] font-medium text-foreground">
                            {video.name || t('Untitled video')}
                          </span>
                          <VideoListMeta video={video} />
                        </span>
                      </span>
                      <span role="cell" className={CELL_CLASS}>
                        {video.duration > 0
                          ? formatVideoDuration(video.duration)
                          : '-'}
                      </span>
                    </Link>
                  </VideoContextMenu>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="shrink-0 border-t border-border px-3 py-2">
          <div className="flex items-center justify-between gap-1 text-[11px] text-muted-foreground">
            <span className="shrink-0 tabular-nums">
              {total === 0
                ? `0 ${t('videos')}`
                : `${(page - 1) * VIDEOS_SIDEBAR_PAGE_SIZE + 1}-${Math.min(page * VIDEOS_SIDEBAR_PAGE_SIZE, total)} ${t('of')} ${total.toLocaleString()} ${t('videos')}`}
            </span>
            {total > VIDEOS_SIDEBAR_PAGE_SIZE ? (
              <div className="flex items-center gap-0.5">
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                  aria-label={t('Previous page')}
                >
                  <ChevronLeft className="h-3 w-3 rtl:rotate-180" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  type="button"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={page >= totalPages}
                  aria-label={t('Next page')}
                >
                  <ChevronRight className="h-3 w-3 rtl:rotate-180" />
                </Button>
              </div>
            ) : null}
          </div>
        </div>

        <div className="flex h-[54px] shrink-0 items-center border-t border-border px-3">
          <Link
            to="/projects/$projectId/videos/profiles"
            params={{ projectId }}
            className={cn(
              secondarySidebarNavLinkClassName(profilesActive),
              SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
            )}
          >
            <Layers className="h-3.5 w-3.5 shrink-0" />
            <span
              className={cn(
                SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
                'flex min-w-0 items-center gap-1',
              )}
            >
              <span className="truncate">{t('Encoding profiles')}</span>
              <VideoTermHint term="profile" side="right" />
            </span>
            <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground">
              {profilesData?.total ?? profilesData?.profiles.length ?? ''}
            </span>
          </Link>
        </div>
      </div>

      <CreateVideo
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
      />
    </div>
  )
}

const CELL_CLASS =
  'truncate text-end font-mono text-[11px] tabular-nums text-muted-foreground'

/** Segmented control matching the docs audience switcher (Developers / Partners). */
function SidebarTabs() {
  const t = useT()
  return (
    <div className="shrink-0 border-b border-border px-3 py-3">
      <ToggleGroup
        type="single"
        variant="outline"
        size="sm"
        value="videos"
        className="w-full"
        aria-label={t('On demand and live')}
      >
        <ToggleGroupItem
          value="videos"
          className="min-w-0 flex-1 px-3 text-[12px] font-medium"
        >
          {t('On demand')}
        </ToggleGroupItem>
        <Tooltip>
          <TooltipTrigger asChild>
            <ToggleGroupItem
              value="live"
              aria-disabled
              className="min-w-0 flex-1 cursor-not-allowed gap-2 px-3 text-[12px] font-medium text-muted-foreground hover:bg-transparent hover:text-muted-foreground"
            >
              {t('Live')}
              <Badge variant="info" className="h-4 px-1.5 text-[10px]">
                {t('Soon')}
              </Badge>
            </ToggleGroupItem>
          </TooltipTrigger>
          <TooltipContent side="bottom" className="max-w-[240px] text-[12px]">
            {t(
              'Live streaming is coming soon. Ingest a live feed and deliver it with the same HLS and DASH outputs.',
            )}
          </TooltipContent>
        </Tooltip>
      </ToggleGroup>
    </div>
  )
}

function VideoListMeta({ video }: { video: Models.Video }) {
  const parts = [video.videoCodec, video.audioCodec].filter(Boolean)
  if (parts.length === 0) return null
  return (
    <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground">
      <span className="truncate font-mono">{parts.join(' · ')}</span>
    </span>
  )
}
