import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { BuildLogsCard } from '@/components/global/shared/BuildLogsCard'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

function normalizeLogs(logs: unknown): string {
  if (typeof logs === 'string') return logs
  if (Array.isArray(logs)) return logs.join('\n')
  return ''
}

interface ViewLogsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  rule: Models.ProxyRule
}

export function ViewLogsDialog({
  open,
  onOpenChange,
  rule,
}: ViewLogsDialogProps) {
  const t = useT()
  const buildLogs = normalizeLogs(rule.logs)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0 max-h-[90dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 pb-4 text-start shrink-0">
          <DialogTitle>{t('View logs')}</DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 py-4 overflow-y-auto flex-1 min-h-0 space-y-4">
          {rule.status === 'verifying' && (
            <Alert
              variant="default"
              className="border-blue-500/30 bg-blue-500/5"
            >
              <AlertDescription className="text-[13px] text-muted-foreground">
                {t('SSL certificate is being issued. This usually takes a couple of minutes - no action needed on your end.')}
              </AlertDescription>
            </Alert>
          )}
          <BuildLogsCard
            buildLogs={buildLogs}
            emptyMessage={t('No logs available')}
            hideTitle
            downloadFilename={`verification-logs-${rule.domain.replace(/\./g, '-')}.txt`}
            hideWhenEmpty={false}
          />
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end shrink-0">
          <Button
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
