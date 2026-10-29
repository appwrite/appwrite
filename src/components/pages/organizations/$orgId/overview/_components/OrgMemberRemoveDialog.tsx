import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'
import type { TeamMember } from '@/lib/utils/mock-data'

export function OrgMemberRemoveDialog({
  open,
  onOpenChange,
  member,
  isPending,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  member: TeamMember | null
  isPending: boolean
  onConfirm: () => void
}) {
  const t = useT()
  const isInvite = member?.status === 'pending'
  const memberLabel =
    member?.userName || member?.userEmail || t('this member')

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (isPending) return
        onOpenChange(nextOpen)
      }}
    >
      <DialogContent
        className="z-[130] p-0 sm:max-w-md"
        overlayClassName="z-[120]"
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {isInvite ? t('Cancel Invitation') : t('Remove from Team')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {isInvite ? (
              <>
                {t('Are you sure you want to cancel the invitation for')}{' '}
                {memberLabel}? {t('They will not be able to join the organization.')}
              </>
            ) : (
              <>
                {t('Are you sure you want to remove')} {memberLabel}{' '}
                {t(
                  'from the team? They will lose access to all organization resources.',
                )}
              </>
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isPending}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            disabled={!member || isPending}
            onClick={onConfirm}
          >
            {isInvite ? t('Cancel invitation') : t('Remove')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
