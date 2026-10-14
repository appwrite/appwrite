import { useState, type ReactNode } from 'react'
import type { VideoOutput } from '@appwrite.io/console'
import { Film, Music } from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  formatResolution,
  formatVideoDuration,
} from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { VideoOutputBadge } from './VideoOutputBadge'

/** Read from the preview element; `duration` in milliseconds. */
export type CreateVideoSourceMeta = {
  width: number
  height: number
  duration: number
  hasVideo: boolean
}

export type CreateVideoSourceSummary = {
  kindLabel: string
  name: string
  size?: number
  mimeType?: string
  previewUrl: string | null
  posterUrl?: string
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2">
      <dt className="shrink-0 text-[12px] text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words text-end text-[12px] font-medium text-foreground">
        {children}
      </dd>
    </div>
  )
}

function SourcePreview({
  source,
  meta,
  onMeta,
}: {
  source: CreateVideoSourceSummary | null
  meta: CreateVideoSourceMeta | null
  onMeta: (meta: CreateVideoSourceMeta) => void
}) {
  const t = useT()
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const url = source?.previewUrl ?? null
  const failed = !!url && failedUrl === url
  const audioOnly = meta ? !meta.hasVideo : false

  return (
    <div className="relative aspect-video overflow-hidden bg-black">
      {url && !failed ? (
        <video
          key={url}
          src={url}
          poster={source?.posterUrl}
          controls
          muted
          playsInline
          preload="metadata"
          className="h-full w-full object-contain"
          onLoadedMetadata={(event) => {
            const el = event.currentTarget
            onMeta({
              width: el.videoWidth,
              height: el.videoHeight,
              duration: Number.isFinite(el.duration) ? el.duration * 1000 : 0,
              hasVideo: el.videoWidth > 0 && el.videoHeight > 0,
            })
          }}
          onError={() => setFailedUrl(url)}
        />
      ) : source?.posterUrl ? (
        <img
          src={source.posterUrl}
          alt=""
          className="h-full w-full object-contain"
        />
      ) : null}
      {((!url && !source?.posterUrl) || failed || audioOnly) ? (
        <div
          className={cn(
            'pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 text-white/60',
            audioOnly && url && !failed && 'bottom-10',
          )}
        >
          {audioOnly ? (
            <Music className="h-7 w-7" />
          ) : (
            <Film className="h-7 w-7" />
          )}
          <span className="text-[12px]">
            {!url
              ? t('No source selected')
              : failed
                ? t('Preview unavailable')
                : t('Audio only')}
          </span>
        </div>
      ) : null}
    </div>
  )
}

export function CreateVideoSummary({
  source,
  meta,
  onMeta,
  bucketName,
  showEncoding,
  output,
  profileNames,
}: {
  source: CreateVideoSourceSummary | null
  meta: CreateVideoSourceMeta | null
  onMeta: (meta: CreateVideoSourceMeta) => void
  bucketName: string | null
  showEncoding: boolean
  output: VideoOutput
  profileNames: string[]
}) {
  const t = useT()
  return (
    <div className="min-w-0 overflow-hidden rounded-xl border border-border bg-card/50">
      <SourcePreview source={source} meta={meta} onMeta={onMeta} />
      <div className="px-4 py-3">
        <p className="truncate text-[14px] font-semibold text-foreground">
          {source?.name ?? t('New video')}
        </p>
        <p className="mt-0.5 text-[12px] text-muted-foreground">
          {source ? t(source.kindLabel) : t('Choose a source to see a preview.')}
        </p>
      </div>
      {source ? (
        <dl className="divide-y divide-border border-t border-border px-4">
          {meta?.hasVideo ? (
            <Row label={t('Resolution')}>
              <span className="font-mono">
                {formatResolution(meta.width, meta.height)}
              </span>
            </Row>
          ) : null}
          {meta?.duration ? (
            <Row label={t('Duration')}>
              <span className="font-mono">
                {formatVideoDuration(meta.duration)}
              </span>
            </Row>
          ) : null}
          {source.size ? (
            <Row label={t('Size')}>{formatBytes(source.size)}</Row>
          ) : null}
          {source.mimeType ? (
            <Row label={t('Type')}>
              <span className="font-mono">{source.mimeType}</span>
            </Row>
          ) : null}
          {bucketName ? (
            <Row label={t('Bucket')}>
              <span className="block truncate">{bucketName}</span>
            </Row>
          ) : null}
        </dl>
      ) : null}
      {showEncoding ? (
        <dl className="divide-y divide-border border-t border-border bg-muted/20 px-4">
          <Row label={t('Output')}>
            <VideoOutputBadge output={output} />
          </Row>
          <Row label={t('Profiles')}>
            {profileNames.length > 0 ? (
              <span className="block truncate">{profileNames.join(', ')}</span>
            ) : (
              <span className="text-muted-foreground">{t('None')}</span>
            )}
          </Row>
        </dl>
      ) : null}
    </div>
  )
}
