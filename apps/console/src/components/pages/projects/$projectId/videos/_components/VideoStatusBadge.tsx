import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type BadgeVariant =
  | 'success'
  | 'warning'
  | 'error'
  | 'info'
  | 'pending'
  | 'processing'

/** Keyed by `VideoEncodingStatus` from `getVideoEncodingStatus`. */
const VIDEO_STATUS: Record<string, { label: string; variant: BadgeVariant }> = {
  none: { label: 'Not encoded', variant: 'pending' },
  encoding: { label: 'Encoding', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  error: { label: 'Failed', variant: 'error' },
}

const RENDITION_STATUS: Record<
  string,
  { label: string; variant: BadgeVariant }
> = {
  pending: { label: 'Queued', variant: 'pending' },
  started: { label: 'Encoding', variant: 'processing' },
  ended: { label: 'Encoded', variant: 'processing' },
  uploading: { label: 'Uploading', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  error: { label: 'Failed', variant: 'error' },
  aborted: { label: 'Aborted', variant: 'warning' },
}

const SUBTITLE_STATUS: Record<
  string,
  { label: string; variant: BadgeVariant }
> = {
  pending: { label: 'Queued', variant: 'pending' },
  started: { label: 'Processing', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  error: { label: 'Failed', variant: 'error' },
}

const STATUS_MAPS = {
  video: VIDEO_STATUS,
  rendition: RENDITION_STATUS,
  subtitle: SUBTITLE_STATUS,
}

export function VideoStatusBadge({
  status,
  kind = 'video',
  className,
}: {
  status: string | undefined
  kind?: keyof typeof STATUS_MAPS
  className?: string
}) {
  const t = useT()
  const entry = STATUS_MAPS[kind][status ?? ''] ?? {
    label: status || 'Unknown',
    variant: 'info' as const,
  }
  return (
    <Badge
      variant={entry.variant}
      className={cn('text-[10px] shrink-0 gap-1', className)}
    >
      {entry.variant === 'processing' && kind !== 'video' ? (
        <Loader2 className="h-2.5 w-2.5 animate-spin" />
      ) : null}
      {t(entry.label)}
    </Badge>
  )
}
