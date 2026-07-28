import { useState } from 'react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MessageSquarePlus } from 'lucide-react'
import {
  FeedbackForm,
  type FeedbackFormContext,
} from '@/components/global/shared/FeedbackForm'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export type FeedbackPopoverContext = FeedbackFormContext

export function FeedbackPopover({
  source = 'n/a',
  orgId = '',
  projectId = '',
  billingPlanId,
}: FeedbackPopoverContext = {}) {
  const t = useT()
  const [isOpen, setIsOpen] = useState(false)
  const [formKey, setFormKey] = useState(0)

  const handleOpenChange = (open: boolean) => {
    setIsOpen(open)
    if (!open) {
      setTimeout(() => setFormKey((k) => k + 1), 200)
    }
  }

  const handleSubmitted = () => {
    setTimeout(() => {
      setIsOpen(false)
      setFormKey((k) => k + 1)
    }, 1500)
  }

  return (
    <Popover open={isOpen} onOpenChange={handleOpenChange}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t('Feedback')}
              {...analyticsAttrs('feedback-open')}
            >
              <MessageSquarePlus className="h-4 w-4" />
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>{t('Feedback')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="end" className="w-80 p-0">
        <FeedbackForm
          key={formKey}
          source={source}
          orgId={orgId}
          projectId={projectId}
          billingPlanId={billingPlanId}
          onSubmitted={handleSubmitted}
        />
      </PopoverContent>
    </Popover>
  )
}
