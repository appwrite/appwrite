import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { useClaimAffiliateReward } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export function ClaimAffiliateReward({
  reward,
  open,
  onOpenChange,
  organizations,
}: {
  reward: Models.AffiliateReward | null
  open: boolean
  onOpenChange: (open: boolean) => void
  organizations: Models.Organization[]
}) {
  const t = useT()
  const claimReward = useClaimAffiliateReward()
  const [organizationId, setOrganizationId] = useState('')

  useEffect(() => {
    if (!open) {
      setOrganizationId('')
      return
    }
    if (organizations.length === 1) {
      setOrganizationId(organizations[0]!.$id)
    }
  }, [open, organizations])

  const handleClaim = async () => {
    if (!reward || !organizationId) return
    try {
      await claimReward.mutateAsync({
        rewardId: reward.$id,
        organizationId,
      })
      toast.success(t('Credits claimed for organization'))
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to claim credits')))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Claim credits')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose an organization you own to receive these affiliate credits.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4 space-y-3">
          <p className="text-[13px] text-muted-foreground">
            {t('Amount')}:{' '}
            <span className="font-medium text-foreground">
              {formatCurrency(reward?.amount ?? 0)}
            </span>
          </p>
          <div className="space-y-2">
            <label className="text-[13px] font-medium text-foreground">
              {t('Organization')}
            </label>
            <Select value={organizationId} onValueChange={setOrganizationId}>
              <SelectTrigger className="h-9">
                <SelectValue placeholder={t('Select organization')} />
              </SelectTrigger>
              <SelectContent>
                {organizations.map((org) => (
                  <SelectItem key={org.$id} value={org.$id}>
                    {org.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={claimReward.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            disabled={!organizationId || claimReward.isPending}
            onClick={handleClaim}
            {...analyticsAttrs('claim-affiliate-reward')}
          >
            {t('Claim credits')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
