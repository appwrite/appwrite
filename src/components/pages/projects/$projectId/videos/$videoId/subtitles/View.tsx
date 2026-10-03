import {
  useState,
  type ComponentProps,
  type MouseEvent,
  type ReactNode,
} from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { DeleteSubtitle } from '../../_components/DeleteSubtitle'
import { SubtitleMenuItems } from '../../_components/SubtitleMenuItems'
import { SubtitlesEmptyState } from '../../_components/SubtitlesEmptyState'
import { VideoStatusBadge } from '../../_components/VideoStatusBadge'
import { VideoActionButton, VideoPage } from '../../_components/VideoPage'
import { useVideoDetailActions } from '../../_components/video-detail-actions'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  useDeleteVideoSubtitle,
  useDownloadVideoSubtitle,
  useUpdateVideoSubtitle,
  useVideoSubtitles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import type { SubtitleDownloadFormat } from '@/lib/videos/subtitle-download'
import { getVideoSubtitleLanguage } from '@/lib/videos/subtitle-languages'

type ViewProps = {
  initialData?: { subtitles: Models.VideoSubtitleList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const navigate = useNavigate()
  const { openCreateSubtitle, canWrite, writeDisabledReason } =
    useVideoDetailActions()
  const { data } = useVideoSubtitles(projectId, videoId)
  const updateMutation = useUpdateVideoSubtitle(projectId, videoId)
  const deleteMutation = useDeleteVideoSubtitle(projectId, videoId)
  const downloadSubtitle = useDownloadVideoSubtitle(projectId, videoId)
  const [deleting, setDeleting] = useState<Models.VideoSubtitle | null>(null)

  const subtitles = data?.subtitles ?? initialData?.subtitles.subtitles ?? []

  const toggleDefault = (subtitle: Models.VideoSubtitle) => {
    updateMutation.mutate(
      { subtitleId: subtitle.$id, isDefault: !subtitle.default },
      {
        onSuccess: () => toast.success(t('Default subtitle updated')),
        onError: (error) =>
          toast.error(getErrorMessage(error) || t('Failed to save subtitle')),
      },
    )
  }

  const download = (
    subtitle: Models.VideoSubtitle,
    format: SubtitleDownloadFormat,
  ) => {
    downloadSubtitle(subtitle, format).catch((error: unknown) =>
      toast.error(getErrorMessage(error) || t('Failed to download subtitles')),
    )
  }

  const confirmDelete = () => {
    if (!deleting) return
    deleteMutation.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Subtitle deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete subtitle'))
      },
    })
  }

  const openEditor = (subtitleId?: string) =>
    void navigate(
      subtitleId
        ? {
            to: '/projects/$projectId/videos/$videoId/subtitles/$subtitleId',
            params: { projectId, videoId, subtitleId },
          }
        : {
            to: '/projects/$projectId/videos/$videoId/subtitles/create',
            params: { projectId, videoId },
          },
    )

  const importButton = (
    <VideoActionButton
      variant="outline"
      size="sm"
      className="h-9 text-[13px]"
      onClick={openCreateSubtitle}
      disabledReason={writeDisabledReason}
    >
      {t('Upload subtitle')}
    </VideoActionButton>
  )

  const menuProps = (subtitle: Models.VideoSubtitle) => ({
    projectId,
    videoId,
    subtitle,
    canWrite,
    onOpen: () => openEditor(subtitle.$id),
    onToggleDefault: () => toggleDefault(subtitle),
    onDownload: (format: SubtitleDownloadFormat) => download(subtitle, format),
    onDelete: () => openDialogAfterOverlayCloses(() => setDeleting(subtitle)),
  })

  return (
    <VideoPage
      title={t('Subtitles')}
      actions={importButton}
      createLabel={t('Create subtitle')}
      onCreate={() => openEditor()}
      createDisabled={Boolean(writeDisabledReason)}
      createDisabledTooltip={writeDisabledReason ?? undefined}
      hideToolbar={subtitles.length === 0}
    >
      {subtitles.length === 0 ? (
        <SubtitlesEmptyState
          onCreate={() => openEditor()}
          onUpload={openCreateSubtitle}
          disabledReason={writeDisabledReason}
        />
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
          {subtitles.map((subtitle) => (
            <li key={subtitle.$id}>
              <ContextMenu>
                <ContextMenuTrigger asChild>
                  <SubtitleRow
                    projectId={projectId}
                    videoId={videoId}
                    subtitle={subtitle}
                    onOpen={() => openEditor(subtitle.$id)}
                    menu={
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${subtitle.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <SubtitleMenuItems
                            variant="dropdown"
                            {...menuProps(subtitle)}
                          />
                        </DropdownMenuContent>
                      </DropdownMenu>
                    }
                  />
                </ContextMenuTrigger>
                <ContextMenuContent className="w-56">
                  <SubtitleMenuItems variant="context" {...menuProps(subtitle)} />
                </ContextMenuContent>
              </ContextMenu>
            </li>
          ))}
        </ul>
      )}

      <DeleteSubtitle
        open={!!deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        subtitle={deleting}
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </VideoPage>
  )
}

function SubtitleRow({
  projectId,
  videoId,
  subtitle,
  onOpen,
  menu,
  ...triggerProps
}: ComponentProps<'div'> & {
  projectId: string
  videoId: string
  subtitle: Models.VideoSubtitle
  onOpen: () => void
  menu: ReactNode
}) {
  const t = useT()
  const language = getVideoSubtitleLanguage(subtitle.code)
  const languageLabel =
    language && language.name !== subtitle.name ? language.name : null
  const native =
    language?.nativeName && language.nativeName !== language.name
      ? language.nativeName
      : null

  // Menus render in portals, but their React events still bubble here.
  const handleClick = (event: MouseEvent<HTMLDivElement>) => {
    if (!event.currentTarget.contains(event.target as Node)) return
    if ((event.target as HTMLElement).closest('a, button')) return
    onOpen()
  }

  return (
    <div
      {...triggerProps}
      onClick={handleClick}
      className="group flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40 data-[state=open]:bg-muted/40 sm:gap-4"
    >
      <div
        aria-hidden
        className="flex h-10 w-11 shrink-0 items-center justify-center rounded-md bg-muted font-mono text-[11px] font-semibold uppercase text-muted-foreground"
      >
        <span className="truncate px-1">{subtitle.code || '-'}</span>
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 items-center gap-1.5">
          <Link
            to="/projects/$projectId/videos/$videoId/subtitles/$subtitleId"
            params={{ projectId, videoId, subtitleId: subtitle.$id }}
            className="truncate text-[13px] font-medium text-foreground hover:underline"
          >
            {subtitle.name}
          </Link>
          {subtitle.default ? (
            <Badge variant="success" className="text-[10px] shrink-0">
              {t('Default')}
            </Badge>
          ) : null}
        </div>
        <p className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
          {languageLabel ? (
            <span className="truncate">{languageLabel}</span>
          ) : null}
          {native ? (
            <span dir="auto" className="truncate">
              {native}
            </span>
          ) : null}
          {languageLabel || native ? <span aria-hidden>·</span> : null}
          <span className="shrink-0">
            {subtitle.embedded ? t('Embedded in source') : t('Storage file')}
          </span>
        </p>
      </div>
      <VideoStatusBadge status={subtitle.status} kind="subtitle" />
      <DateTooltip
        date={subtitle.$createdAt}
        className="hidden w-24 shrink-0 whitespace-nowrap text-end text-[12px] font-normal text-muted-foreground md:block"
      />
      <div className="shrink-0">{menu}</div>
    </div>
  )
}
