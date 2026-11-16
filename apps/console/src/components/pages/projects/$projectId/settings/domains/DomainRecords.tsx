import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import { isSmtpRule } from '@/lib/function-smtp-domain'
import { VerifySmtpDomainContent } from '@/components/pages/projects/$projectId/functions/domains/VerifySmtpDomainContent'
import { VerifyDomainContent } from './VerifyDomainContent'

interface DomainRecordsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  region?: string
  rule: Models.ProxyRule
  /** Which resource the custom domain points to. Same values as VerifyDomainContent. */
  resourceType?: 'api' | 'function' | 'site'
}

/** DNS records for one proxy rule, the same instructions Retry and Verify show. */
export function DomainRecordsDialog({
  open,
  onOpenChange,
  region,
  rule,
  resourceType = 'api',
}: DomainRecordsDialogProps) {
  const t = useT()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('DNS Records')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2 font-mono">
            {rule.domain}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 max-h-[70dvh] overflow-y-auto">
          {isSmtpRule(rule) ? (
            <VerifySmtpDomainContent rule={rule} region={region} noCard />
          ) : (
            <VerifyDomainContent
              rule={rule}
              region={region}
              resourceType={resourceType}
              noCard
            />
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
          >
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
