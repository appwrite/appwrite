'use client'

import { X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { SupportPanel } from '@/components/global/shared/SupportPanel'
import { useT } from '@/lib/i18n/translate'

type CommandCenterSupportViewProps = {
  isMobile: boolean
  orgId?: string | null
  onBack: () => void
  onClose: () => void
  onKeyDown: (e: React.KeyboardEvent) => void
}

export function CommandCenterSupportView({
  isMobile,
  orgId,
  onBack,
  onClose,
  onKeyDown,
}: CommandCenterSupportViewProps) {
  const t = useT()
  return (
    <div
      className={cn('flex flex-col', isMobile && 'flex-1 min-h-0')}
      onKeyDown={onKeyDown}
    >
      <div className="flex shrink-0 items-center border-b border-border px-3 h-14">
        <button
          type="button"
          onClick={onBack}
          className="flex h-6 shrink-0 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
        >
          ← {t('Back')}
        </button>
        <span className="ms-3 text-[14px] font-medium text-foreground">
          {t('Support')}
        </span>
        <button
          type="button"
          onClick={onClose}
          className="ms-auto flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          aria-label={t('Close')}
        >
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className={cn(isMobile && 'flex-1 overflow-y-auto')}>
        <SupportPanel orgId={orgId} onNavigateAway={onClose} />
      </div>
    </div>
  )
}
