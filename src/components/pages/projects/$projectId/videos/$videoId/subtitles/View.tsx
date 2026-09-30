import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Captions, Copy, FileJson, Pencil, Star, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useVideoDetailActions } from '../../Layout'
import { CreateSubtitle } from '../../_components/CreateSubtitle'
import { VideoStatusBadge } from '../../_components/VideoStatusBadge'
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
import {
  useDeleteVideoSubtitle,
  useUpdateVideoSubtitle,
  useVideoSubtitles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getVideoSubtitleLanguage } from '@/lib/videos/subtitle-languages'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type ViewProps = {
  initialData?: { subtitles: Models.VideoSubtitleList }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { openCreateSubtitle, canWrite } = useVideoDetailActions()
  const { data } = useVideoSubtitles(projectId, videoId)
  const updateMutation = useUpdateVideoSubtitle(projectId, videoId)
  const deleteMutation = useDeleteVideoSubtitle(projectId, videoId)
  const [editing, setEditing] = useState<Models.VideoSubtitle | null>(null)
  const [deleting, setDeleting] = useState<Models.VideoSubtitle | null>(null)

  const subtitles = data?.subtitles ?? initialData?.subtitles.subtitles ?? []

  const makeDefault = (subtitle: Models.VideoSubtitle) => {
    updateMutation.mutate(
      { subtitleId: subtitle.$id, isDefault: true },
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

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-6 sm:px-6">
      {subtitles.length === 0 ? (
        <EmptyState
          icon={Captions}
          title={t('No subtitles')}
          description={t(
            'Add WebVTT or SRT files from Storage. Every HLS, DASH, and CMAF manifest lists them as text tracks.',
          )}
          isEmpty
          hasFilters={false}
          variant="card"
          action={
            canWrite ? (
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={openCreateSubtitle}
              >
                {t('Create subtitle')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD_CLASSNAME}>{t('Name')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>
                  {t('Language')}
                </TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Status')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Source')}</TableHead>
                <TableHead className={HEAD_CLASSNAME}>{t('Created')}</TableHead>
                <TableHead className={`${HEAD_CLASSNAME} text-end w-[60px]`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {subtitles.map((subtitle) => (
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
                      <CopyableId id={subtitle.$id} size="xs" maxWidth={140} />
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px]">
                    {getVideoSubtitleLanguage(subtitle.code)?.name ??
                      subtitle.code}{' '}
                    <span className="font-mono text-[12px] text-muted-foreground">
                      ({subtitle.code})
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <VideoStatusBadge
                      status={subtitle.status}
                      kind="subtitle"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                    {subtitle.embedded ? t('Embedded') : t('Storage file')}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip date={subtitle.$createdAt} />
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
                        {!subtitle.default ? (
                          <DropdownMenuItem
                            disabled={!canWrite || updateMutation.isPending}
                            onClick={() => makeDefault(subtitle)}
                          >
                            <MenuItemContent icon={Star}>
                              {t('Make default')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        ) : null}
                        <DropdownMenuSeparator />
                        <DropdownMenuSub>
                          <DropdownMenuSubTrigger>
                            <MenuItemIcon icon={Copy} />
                            {t('Copy')}
                          </DropdownMenuSubTrigger>
                          <DropdownMenuSubContent className="w-44">
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
                            <DropdownMenuItem
                              onClick={() => copyResourceAsJson(() => subtitle)}
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
              ))}
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
        description={`${t('Delete')} "${deleting?.name ?? ''}"? ${t('The Storage file is kept. This action cannot be undone.')}`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
