import { Layers } from 'lucide-react'
import { VideoActionButton } from './VideoPage'
import { useVideoDetailActions } from './video-detail-actions'
import { useT } from '@/lib/i18n/translate'

export function NothingToStreamNotice() {
  const t = useT()
  const { openCreateRenditions, writeDisabledReason } = useVideoDetailActions()
  return (
    <div className="flex flex-col gap-3 rounded-xl border border-border bg-card/50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <Layers className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <div>
          <p className="text-[14px] font-medium text-foreground">
            {t('Nothing to stream yet')}
          </p>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'Manifests become available as soon as the first rendition for that output is ready.',
            )}
          </p>
        </div>
      </div>
      <VideoActionButton
        size="sm"
        className="h-9 shrink-0 text-[13px]"
        onClick={openCreateRenditions}
        disabledReason={writeDisabledReason}
      >
        {t('Create renditions')}
      </VideoActionButton>
    </div>
  )
}
