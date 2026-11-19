import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import type { TeamRule } from '@/lib/appwrite/team-rules'
import { useT } from '@/lib/i18n/translate'
import { useUpdateOrganizationEmailDomain } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { EmailDomainRoles } from './EmailDomainRoles'

/** Changes the roles new people get; people who already joined keep theirs. */
export function UpdateEmailDomain({
  rule,
  organizationId,
  onOpenChange,
}: {
  rule: TeamRule | null
  organizationId: string
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const [roles, setRoles] = useState<string[]>([])
  const update = useUpdateOrganizationEmailDomain(organizationId)

  useEffect(() => {
    if (rule) setRoles(rule.teamRoles)
  }, [rule])

  const handleSave = () => {
    if (!rule || roles.length === 0) return
    update.mutate(
      { ruleId: rule.$id, roles },
      {
        onSuccess: () => {
          toast.success(t('Email domain updated'))
          onOpenChange(false)
        },
        onError: (failure: Error) => {
          toast.error(
            getErrorMessage(failure) || t('Failed to update email domain'),
          )
        },
      },
    )
  }

  return (
    <Dialog open={rule !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Update roles')}</DialogTitle>
          <p className="mt-1 font-mono text-[13px] text-foreground">
            {rule?.domain}
          </p>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Applies to people who join from now on. People who already joined keep their roles; change those in Members.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-2 px-6 py-4">
          <Label className="text-[13px] font-medium">{t('Roles')}</Label>
          <EmailDomainRoles
            value={roles}
            onChange={setRoles}
            disabled={update.isPending}
          />
          {roles.length === 0 ? (
            <p className="text-[12px] text-muted-foreground">
              {t('Pick at least one role.')}
            </p>
          ) : null}
        </div>
        <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSave}
            disabled={update.isPending || roles.length === 0}
          >
            {update.isPending ? t('Saving...') : t('Update')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
