import type { Models } from '@appwrite.io/console'
import { flushSync } from 'react-dom'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useDeleteFirewallRule } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'

interface DeleteRuleProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  rule: Models.WafRule | null
}

export function DeleteRule({
  open,
  onOpenChange,
  projectId,
  rule,
}: DeleteRuleProps) {
  const t = useT()
  const deleteMutation = useDeleteFirewallRule(projectId)

  const handleDelete = async () => {
    if (!rule) return
    const ruleId = rule.$id
    // Close and commit before the mutation refetches the list. Otherwise the
    // row's DropdownMenu/ContextMenu unmounts while this dialog is still open
    // and Radix can leave body pointer-events locked (Create rule stops working).
    flushSync(() => {
      onOpenChange(false)
    })
    try {
      await deleteMutation.mutateAsync(ruleId)
      toast.success(t('Firewall rule deleted'))
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to delete firewall rule')),
      )
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Delete firewall rule')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Delete')} &quot;{rule?.name}&quot;?{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={deleteMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
