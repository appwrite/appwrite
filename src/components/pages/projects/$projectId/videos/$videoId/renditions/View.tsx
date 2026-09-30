import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models, VideoOutput } from '@appwrite.io/console'
import { Copy, FileJson, Layers, RefreshCw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useVideoDetailActions } from '../../Layout'
import { VideoStatusBadge } from '../../_components/VideoStatusBadge'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
import {
  isVideoRenditionActive,
  parseVideoProgress,
  useDeleteVideoRendition,
  useProjectVideo,
  useVideoRenditions,
  videoKeys,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  formatBitrate,
  formatElapsed,
  formatResolution,
} from '@/lib/utils/video-format'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'
const RETRYABLE_STATUSES = new Set(['error', 'aborted'])

type ViewProps = {
  initialData?: { renditions: Models.VideoRenditionList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const queryClient = useQueryClient()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { openCreateRenditions, canWrite } = useVideoDetailActions()
  const { data: video } = useProjectVideo(projectId, videoId)
  const { data } = useVideoRenditions(projectId, videoId)
  const deleteMutation = useDeleteVideoRendition(projectId, videoId)
  const [deleting, setDeleting] = useState<Models.VideoRendition | null>(null)

  const renditions = useMemo(
    () =>
      [...(data?.renditions ?? initialData?.renditions.renditions ?? [])].sort(
        (a, b) =>
          a.output.localeCompare(b.output) ||
          b.width * b.height - a.width * a.height,
      ),
    [data?.renditions, initialData?.renditions.renditions],
  )

  const retryMutation = useMutation({
    mutationFn: async (rendition: Models.VideoRendition) => {
      const videos = sdk.forProject(projectId).videos
      await videos.deleteRendition({ videoId, renditionId: rendition.$id })
      return await videos.createRendition({
        videoId,
        profileId: rendition.profileId,
        output: rendition.output as VideoOutput,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: videoKeys.renditions(projectId, videoId),
      })
      toast.success(t('Rendition queued'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to retry rendition'))
    },
  })

  const confirmDelete = () => {
    if (!deleting) return
    deleteMutation.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Rendition deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete rendition'))
      },
    })
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-6 sm:px-6">
      {renditions.length === 0 ? (
        <EmptyState
          icon={Layers}
          title={t('No renditions')}
          description={
            video?.status === 'ready'
              ? t(
                  'Encode the source into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.',
                )
              : t(
                  'Renditions can be created once the source download is ready.',
                )
          }
          isEmpty
          hasFilters={false}
          variant="card"
          action={
            canWrite && video?.status === 'ready' ? (
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={openCreateRenditions}
              >
                {t('Create renditions')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD_CLASSNAME}>
                  {t('Rendition')}
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Output')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  {t('Resolution')}
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Bitrate')}</TableHead>
                <TableHead className={`${HEAD_CLASSNAME} min-w-[180px]`}>
                  {t('Status')}
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  {t('Encoding time')}
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Created')}</TableHead>
                <TableHead className={`${HEAD_CLASSNAME} text-end w-[60px]`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {renditions.map((rendition) => {
                const active = isVideoRenditionActive(rendition.status)
                return (
                  <TableRow key={rendition.$id}>
                    <TableCell className="px-4 py-3">
                      <div className="flex min-w-0 flex-col gap-1">
                        <span className="truncate text-[13px] font-medium">
                          {rendition.name}
                        </span>
                        <CopyableId
                          id={rendition.$id}
                          size="xs"
                          maxWidth={140}
                        />
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge variant="info" className="text-[10px] uppercase">
                        {rendition.output}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {formatResolution(rendition.width, rendition.height)}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                      {formatBitrate(rendition.videoBitRate, 'kbps')} /{' '}
                      {formatBitrate(rendition.audioBitRate, 'kbps')}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex flex-col gap-1.5">
                        <VideoStatusBadge
                          status={rendition.status}
                          kind="rendition"
                        />
                        {active ? (
                          <ProgressBarRow
                            value={parseVideoProgress(rendition.progress)}
                            className="mb-0 max-w-[160px]"
                          />
                        ) : null}
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                      {rendition.startedAt
                        ? formatElapsed(rendition.startedAt, rendition.endedAt)
                        : '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip date={rendition.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${rendition.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          {RETRYABLE_STATUSES.has(rendition.status) ? (
                            <>
                              <DropdownMenuItem
                                disabled={!canWrite || retryMutation.isPending}
                                onClick={() => retryMutation.mutate(rendition)}
                              >
                                <MenuItemContent icon={RefreshCw}>
                                  {t('Retry')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                            </>
                          ) : null}
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent className="w-44">
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('ID'), rendition.$id)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('Name'), rendition.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyResourceAsJson(
                                    () =>
                                      sdk
                                        .forProject(projectId)
                                        .videos.getRendition({
                                          videoId,
                                          renditionId: rendition.$id,
                                        }),
                                    { fallback: rendition },
                                  )
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => setDeleting(rendition)}
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

      <ConfirmActionDialog
        open={!!deleting}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        title={t('Delete rendition')}
        description={`${t('Delete')} "${deleting?.name ?? ''}"? ${t('Its segments are removed and players stop receiving this quality. This action cannot be undone.')}`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
