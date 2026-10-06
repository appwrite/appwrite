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
  useDeleteVideoCaption,
  useDownloadVideoCaption,
  useUpdateVideoCaption,
  useVideoCaptions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import type { SubtitleDownloadFormat } from '@/lib/videos/subtitle-download'
import { getVideoSubtitleLanguage } from '@/lib/videos/subtitle-languages'

type ViewProps = {
  initialData?: { captions: Models.VideoCaptionList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const navigate = useNavigate()
  const { openCreateCaption, canWrite, writeDisabledReason } =
    useVideoDetailActions()
  const { data } = useVideoCaptions(projectId, videoId)
  const updateMutation = useUpdateVideoCaption(projectId, videoId)
  const deleteMutation = useDeleteVideoCaption(projectId, videoId)
  const downloadCaption = useDownloadVideoCaption(projectId, videoId)
  const [deleting, setDeleting] = useState<Models.VideoCaption | null>(null)

  const captions = data?.captions ?? initialData?.captions.captions ?? []

  const toggleDefault = (caption: Models.VideoCaption) => {
    updateMutation.mutate(
      { captionId: caption.$id, isDefault: !caption.default },
      {
        onSuccess: () => toast.success(t('Default caption updated')),
        onError: (error) =>
          toast.error(getErrorMessage(error) || t('Failed to save caption')),
      },
    )
  }

  const download = (
    caption: Models.VideoCaption,
    format: SubtitleDownloadFormat,
  ) => {
    downloadCaption(caption, format).catch((error: unknown) =>
      toast.error(getErrorMessage(error) || t('Failed to download captions')),
    )
  }

  const confirmDelete = () => {
    if (!deleting) return
    deleteMutation.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Caption deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete caption'))
      },
    })
  }

  const openEditor = (captionId?: string) =>
    void navigate(
      captionId
        ? {
            to: '/projects/$projectId/videos/$videoId/captions/$captionId',
            params: { projectId, videoId, captionId },
          }
        : {
            to: '/projects/$projectId/videos/$videoId/captions/create',
            params: { projectId, videoId },
          },
    )

  const importButton = (
    <VideoActionButton
      variant="outline"
      size="sm"
      className="h-9 text-[13px]"
      onClick={openCreateCaption}
      disabledReason={writeDisabledReason}
    >
      {t('Upload caption')}
    </VideoActionButton>
  )

  const menuProps = (caption: Models.VideoCaption) => ({
    projectId,
    videoId,
    caption,
    canWrite,
    onOpen: () => openEditor(caption.$id),
    onToggleDefault: () => toggleDefault(caption),
    onDownload: (format: SubtitleDownloadFormat) => download(caption, format),
    onDelete: () => openDialogAfterOverlayCloses(() => setDeleting(caption)),
  })

  return (
    <VideoPage
      title={t('Captions')}
      actions={importButton}
      createLabel={t('Create caption')}
      onCreate={() => openEditor()}
      createDisabled={Boolean(writeDisabledReason)}
      createDisabledTooltip={writeDisabledReason ?? undefined}
      hideToolbar={captions.length === 0}
    >
      {captions.length === 0 ? (
        <SubtitlesEmptyState
          onCreate={() => openEditor()}
          onUpload={openCreateCaption}
          disabledReason={writeDisabledReason}
        />
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
          {captions.map((caption) => (
            <li key={caption.$id}>
              <ContextMenu>
                <ContextMenuTrigger asChild>
                  <CaptionRow
                    projectId={projectId}
                    videoId={videoId}
                    caption={caption}
                    onOpen={() => openEditor(caption.$id)}
                    menu={
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${caption.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <SubtitleMenuItems
                            variant="dropdown"
                            {...menuProps(caption)}
                          />
                        </DropdownMenuContent>
                      </DropdownMenu>
                    }
                  />
                </ContextMenuTrigger>
                <ContextMenuContent className="w-56">
                  <SubtitleMenuItems variant="context" {...menuProps(caption)} />
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

function CaptionRow({
  projectId,
  videoId,
  caption,
  onOpen,
  menu,
  ...triggerProps
}: ComponentProps<'div'> & {
  projectId: string
  videoId: string
  caption: Models.VideoCaption
  onOpen: () => void
  menu: ReactNode
}) {
  const t = useT()
  const language = getVideoSubtitleLanguage(caption.code)
  const languageLabel =
    language && language.name !== caption.name ? language.name : null
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
        <span className="truncate px-1">{caption.code || '-'}</span>
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 items-center gap-1.5">
          <Link
            to="/projects/$projectId/videos/$videoId/captions/$captionId"
            params={{ projectId, videoId, captionId: caption.$id }}
            className="truncate text-[13px] font-medium text-foreground hover:underline"
          >
            {caption.name}
          </Link>
          {caption.default ? (
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
            {caption.embedded ? t('Embedded in source') : t('Storage file')}
          </span>
        </p>
      </div>
      <VideoStatusBadge status={caption.status} kind="caption" />
      <DateTooltip
        date={caption.$createdAt}
        className="hidden w-24 shrink-0 whitespace-nowrap text-end text-[12px] font-normal text-muted-foreground md:block"
      />
      <div className="shrink-0">{menu}</div>
    </div>
  )
}
