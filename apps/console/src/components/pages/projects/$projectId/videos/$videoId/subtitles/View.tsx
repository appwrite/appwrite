import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  Captions,
  Copy,
  ExternalLink,
  FileJson,
  ListVideo,
  Pencil,
  Star,
  StarOff,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { CreateSubtitle } from '../../_components/CreateSubtitle'
import { VideoStatusBadge } from '../../_components/VideoStatusBadge'
import { VideoActionButton, VideoPage } from '../../_components/VideoPage'
import { VideoTermHint } from '../../_components/VideoTermHint'
import { useVideoDetailActions } from '../../_components/video-detail-actions'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import {
  useDeleteVideoSubtitle,
  useUpdateVideoSubtitle,
  useVideoSubtitles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getVideoSubtitleLanguage } from '@/lib/videos/subtitle-languages'
import { getVideoSubtitleManifestUrl } from '@/lib/videos/urls'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

const SUBTITLE_OUTPUTS = [
  { id: 'hls', label: 'Copy HLS subtitle URL' },
  { id: 'dash', label: 'Copy DASH subtitle URL' },
  { id: 'cmaf', label: 'Copy CMAF subtitle URL' },
] as const

type ViewProps = {
  initialData?: { subtitles: Models.VideoSubtitleList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { openCreateSubtitle, canWrite, writeDisabledReason } =
    useVideoDetailActions()
  const { data } = useVideoSubtitles(projectId, videoId)
  const updateMutation = useUpdateVideoSubtitle(projectId, videoId)
  const deleteMutation = useDeleteVideoSubtitle(projectId, videoId)
  const [editing, setEditing] = useState<Models.VideoSubtitle | null>(null)
  const [deleting, setDeleting] = useState<Models.VideoSubtitle | null>(null)

  const subtitles = data?.subtitles ?? initialData?.subtitles.subtitles ?? []

  const setDefault = (subtitle: Models.VideoSubtitle, isDefault: boolean) => {
    updateMutation.mutate(
      { subtitleId: subtitle.$id, isDefault },
      {
        onSuccess: () => toast.success(t('Default subtitle updated')),
        onError: (error) =>
          toast.error(getErrorMessage(error) || t('Failed to save subtitle')),
      },
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

  const addButton = (
    <VideoActionButton
      size="sm"
      className="h-9 text-[13px]"
      onClick={openCreateSubtitle}
      disabledReason={writeDisabledReason}
    >
      {t('Add subtitle')}
    </VideoActionButton>
  )

  return (
    <VideoPage
      title={t('Subtitles')}
      createLabel={t('Add subtitle')}
      onCreate={openCreateSubtitle}
      createDisabled={Boolean(writeDisabledReason)}
      createDisabledTooltip={writeDisabledReason ?? undefined}
    >
      {subtitles.length === 0 ? (
        <EmptyState
          icon={Captions}
          title={t('No subtitles')}
          description={t(
            'Add WebVTT or SRT files from Storage. Tracks embedded in the source file are extracted automatically by the first rendition or timeline job.',
          )}
          isEmpty
          hasFilters={false}
          variant="card"
          action={addButton}
        />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD_CLASSNAME}>{t('Name')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  <span className="inline-flex items-center gap-1">
                    {t('Language')}
                    <VideoTermHint term="languageCode" />
                  </span>
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  <span className="inline-flex items-center gap-1">
                    {t('Source')}
                    <VideoTermHint term="embedded" />
                  </span>
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  <span className="inline-flex items-center gap-1">
                    {t('Segment length')}
                    <VideoTermHint term="targetDuration" />
                  </span>
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Status')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Created')}</TableHead>
                <TableHead className={`${HEAD_CLASSNAME} text-end w-[60px]`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {subtitles.map((subtitle) => {
                const ready = subtitle.status === 'ready'
                const hasFile = !subtitle.embedded && !!subtitle.fileId
                return (
                  <TableRow key={subtitle.$id}>
                    <TableCell className="px-4 py-3">
                      <div className="flex min-w-0 flex-col gap-1">
                        <span className="flex min-w-0 items-center gap-1.5">
                          <span className="truncate text-[13px] font-medium">
                            {subtitle.name}
                          </span>
                          {subtitle.default ? (
                            <Badge
                              variant="success"
                              className="text-[10px] shrink-0"
                            >
                              {t('Default')}
                            </Badge>
                          ) : null}
                        </span>
                        <CopyableId
                          id={subtitle.$id}
                          size="xs"
                          maxWidth={140}
                        />
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px]">
                      {getVideoSubtitleLanguage(subtitle.code)?.name ??
                        subtitle.code}{' '}
                      <span className="font-mono text-[12px] text-muted-foreground">
                        ({subtitle.code})
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                      {subtitle.embedded ? (
                        t('Embedded in source')
                      ) : (
                        <span className="font-mono text-[12px]">
                          {subtitle.fileId}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                      {Number(subtitle.targetDuration) > 0
                        ? `${subtitle.targetDuration}s`
                        : '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <VideoStatusBadge
                        status={subtitle.status}
                        kind="subtitle"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip
                        date={subtitle.$createdAt}
                        className="whitespace-nowrap text-[12px] font-normal text-muted-foreground"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${subtitle.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => setEditing(subtitle)}
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            disabled={!canWrite || updateMutation.isPending}
                            onClick={() =>
                              setDefault(subtitle, !subtitle.default)
                            }
                          >
                            <MenuItemContent
                              icon={subtitle.default ? StarOff : Star}
                            >
                              {subtitle.default
                                ? t('Remove default')
                                : t('Make default')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent className="w-60">
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('ID'), subtitle.$id)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('Name'), subtitle.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              {SUBTITLE_OUTPUTS.map((output) => (
                                <DropdownMenuItem
                                  key={output.id}
                                  disabled={!ready}
                                  onClick={() =>
                                    copyToClipboard(
                                      t('Subtitle URL'),
                                      getVideoSubtitleManifestUrl(
                                        projectId,
                                        videoId,
                                        subtitle.$id,
                                        output.id,
                                      ),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={ListVideo}>
                                    {t(output.label)}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              ))}
                              <DropdownMenuItem
                                onClick={() =>
                                  copyResourceAsJson(() => subtitle)
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          {hasFile ? (
                            <DropdownMenuItem
                              onClick={() =>
                                openInNewTab(
                                  withAdminMode(
                                    sdk
                                      .forProject(projectId)
                                      .storage.getFileView({
                                        bucketId: subtitle.bucketId,
                                        fileId: subtitle.fileId,
                                      }),
                                  ),
                                )
                              }
                            >
                              <MenuItemContent icon={ExternalLink}>
                                {t('Open subtitle file')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          ) : null}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => setDeleting(subtitle)}
                          >
                            <MenuItemContent icon={Trash2}>
                              {t('Delete')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}

      <CreateSubtitle
        open={!!editing}
        onOpenChange={(open) => {
          if (!open) setEditing(null)
        }}
        projectId={projectId}
        videoId={videoId}
        subtitle={editing}
      />
      <ConfirmActionDialog
        open={!!deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        title={t('Delete subtitle')}
        description={`${t('Delete')} "${deleting?.name ?? ''}"? ${
          deleting?.embedded
            ? t(
                'Embedded tracks are extracted only once, so this track will not come back. This action cannot be undone.',
              )
            : t('The Storage file is kept. This action cannot be undone.')
        }`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </VideoPage>
  )
}
