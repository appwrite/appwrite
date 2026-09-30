import type { Models } from '@appwrite.io/console'
import { Link, useNavigate } from '@tanstack/react-router'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatResolution, formatVideoDuration } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { VideoContextMenu } from './VideoContextMenu'
import { VideoStatusBadge } from './VideoStatusBadge'
import { VideoThumb } from './VideoThumb'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

interface VideosListTableProps {
  projectId: string
  videos: Models.Video[]
  selectedVideoIds: Set<string>
  onToggleVideo: (videoId: string) => void
  onToggleAll: () => void
}

export function VideosListTable({
  projectId,
  videos,
  selectedVideoIds,
  onToggleVideo,
  onToggleAll,
}: VideosListTableProps) {
  const t = useT()
  const navigate = useNavigate()

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className="w-[40px] px-4">
              <Checkbox
                checked={
                  videos.length > 0 && selectedVideoIds.size === videos.length
                }
                onCheckedChange={onToggleAll}
              />
            </TableHead>
            <TableHead className={HEAD_CLASS}>{t('Video')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Duration')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Resolution')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Codecs')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Size')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Created')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {videos.map((video) => (
            <VideoContextMenu
              key={video.$id}
              projectId={projectId}
              video={{ $id: video.$id, name: video.name }}
            >
              <TableRow
                className={cn(
                  'cursor-pointer border-b border-border/50 transition-colors',
                  selectedVideoIds.has(video.$id)
                    ? 'bg-muted'
                    : 'hover:bg-muted/30',
                )}
                onClick={(event) => {
                  const target = event.target as HTMLElement
                  if (
                    target.closest('button') ||
                    target.closest('[role="checkbox"]') ||
                    target.closest('a')
                  ) {
                    return
                  }
                  navigate({
                    to: '/projects/$projectId/videos/$videoId',
                    params: { projectId, videoId: video.$id },
                  })
                }}
              >
                <TableCell
                  onClick={(event) => event.stopPropagation()}
                  className="px-4 py-3"
                >
                  <Checkbox
                    checked={selectedVideoIds.has(video.$id)}
                    onCheckedChange={() => onToggleVideo(video.$id)}
                  />
                </TableCell>
                <TableCell className="px-4 py-3">
                  <Link
                    to="/projects/$projectId/videos/$videoId"
                    params={{ projectId, videoId: video.$id }}
                    className="block min-w-0"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <VideoThumb
                        projectId={projectId}
                        video={video}
                        width={160}
                        className="h-9 w-16 shrink-0 rounded-md"
                      />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {video.name || t('Untitled video')}
                        </p>
                        <div className="mt-0.5">
                          <CopyableId id={video.$id} size="xs" />
                        </div>
                      </div>
                    </div>
                  </Link>
                </TableCell>
                <TableCell className="px-4 py-3">
                  <VideoStatusBadge status={video.status} />
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                  {formatVideoDuration(video.duration)}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                  {formatResolution(video.width, video.height)}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                  {[video.videoCodec, video.audioCodec]
                    .filter(Boolean)
                    .join(' / ') || '-'}
                </TableCell>
                <TableCell className="px-4 py-3 font-mono text-[12px] text-muted-foreground">
                  {video.size ? formatBytes(video.size) : '-'}
                </TableCell>
                <TableCell className="px-4 py-3 text-end">
                  <DateTooltip
                    date={video.$createdAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </TableCell>
              </TableRow>
            </VideoContextMenu>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
