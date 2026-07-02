'use client'

import { X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { FeedbackForm } from '@/components/global/shared/FeedbackForm'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

type CommandCenterFeedbackViewProps = {
  isMobile: boolean
  orgId?: string | null
  projectId?: string | null
  onBack: () => void
  onClose: () => void
  onKeyDown: (e: React.KeyboardEvent) => void
}

export function CommandCenterFeedbackView({
  isMobile,
  orgId,
  projectId,
  onBack,
  onClose,
  onKeyDown,
}: CommandCenterFeedbackViewProps) {
  const t = useT()
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

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
          {t('Send feedback')}
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
        <FeedbackForm
          source="command-center"
          orgId={orgId ?? ''}
          projectId={projectId ?? ''}
          billingPlanId={organizationPlan?.$id}
          onSubmitted={() => {
            setTimeout(onClose, 1500)
          }}
        />
      </div>
    </div>
  )
}
