import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { Models } from '@appwrite.io/console'

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
  const logs = rule.logs || 'No logs available'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>View logs</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Domain verification logs for {rule.domain}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        
        <div className="px-6 pb-4 pt-0">
          <pre className="max-h-[400px] overflow-auto rounded-md bg-muted p-4 text-[13px] font-mono">
            {logs}
          </pre>
        </div>
        
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="text-sm text-muted-foreground hover:text-foreground"
          >
            Close
          </button>
        </div>
      </DialogContent>
    </Dialog>
  )
}


