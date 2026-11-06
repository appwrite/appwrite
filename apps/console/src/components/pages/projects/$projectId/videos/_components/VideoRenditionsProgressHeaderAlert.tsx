import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { AlertCircle, Loader2 } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertGhostButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import {
  isVideoRenditionActive,
  parseVideoProgress,
} from '@/lib/react-query/hooks/videos'
import { useT } from '@/lib/i18n/translate'

type VideoRenditionsProgressHeaderAlertProps = {
  projectId: string
  videoId: string
  renditions: Models.VideoRendition[]
}

export function VideoRenditionsProgressHeaderAlert({
  projectId,
  videoId,
  renditions,
}: VideoRenditionsProgressHeaderAlertProps) {
  const t = useT()

  const activeRenditions = renditions.filter((r) =>
    isVideoRenditionActive(r.status),
  )
  const failedRenditions = renditions.filter((r) => r.status === 'error')
  const readyCount = renditions.filter((r) => r.status === 'ready').length
  const total = renditions.length

  const encoding = activeRenditions.length > 0
  const failuresOnly = !encoding && failedRenditions.length > 0

  if (!encoding && !failuresOnly) {
    return null
  }

  const progressValues = activeRenditions.map((r) =>
    parseVideoProgress(r.progress),
  )
  const averageProgress =
    progressValues.length > 0
      ? progressValues.reduce((sum, value) => sum + value, 0) /
        progressValues.length
      : 0

  const variant = failuresOnly ? 'warning' : 'info'
  const Icon = failuresOnly ? AlertCircle : Loader2

  const title = encoding
    ? t('Processing renditions')
    : t('Some renditions failed')

  const detail = encoding
    ? t('{encoding} encoding · {ready} of {total} ready')
        .replace('{encoding}', String(activeRenditions.length))
        .replace('{ready}', String(readyCount))
        .replace('{total}', String(total))
    : t('{failed} failed · {ready} of {total} ready')
        .replace('{failed}', String(failedRenditions.length))
        .replace('{ready}', String(readyCount))
        .replace('{total}', String(total))

  return (
    <HeaderAlertBar
      variant={variant}
      icon={Icon}
      className={
        encoding
          ? 'shrink-0 min-h-12 py-2.5 [&_svg]:animate-spin'
          : 'shrink-0 min-h-12 py-2.5'
      }
      action={
        <Link
          to="/projects/$projectId/videos/$videoId/renditions"
          params={{ projectId, videoId }}
          className={headerAlertGhostButtonClass(variant)}
        >
          {t('View renditions')}
        </Link>
      }
    >
      <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
        <p className="min-w-0 leading-snug">
          <span className="font-semibold">{title}</span>
          <span className="font-normal opacity-90"> · {detail}</span>
        </p>
        {encoding ? (
          <div className="w-full min-w-0 shrink sm:max-w-[220px] [&_.text-muted-foreground]:text-current/80">
            <ProgressBarRow value={averageProgress} className="mb-0" />
          </div>
        ) : null}
      </div>
    </HeaderAlertBar>
  )
}
