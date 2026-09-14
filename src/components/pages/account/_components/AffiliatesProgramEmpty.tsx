import { useEffect, useState } from 'react'
import { Gift, Link2, Share2, Sparkles } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  AFFILIATE_ATTRIBUTION_DAYS,
  AFFILIATE_REWARD_AMOUNT_USD,
  useCreateAffiliateLink,
} from '@/lib/react-query/hooks'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

const PROGRAM_STEPS = [
  {
    icon: Link2,
    title: 'Create an invite link',
    description:
      'Generate a shareable link with an optional name for each campaign or channel.',
  },
  {
    icon: Share2,
    title: 'Share with developers',
    description:
      'Clicks are tracked automatically. Signups are attributed for 180 days.',
  },
  {
    icon: Sparkles,
    title: 'Earn Pro credits',
    description:
      'When a referred user upgrades to Pro, you receive $15 in organization credits.',
  },
] as const

export function AffiliatesProgramEmpty() {
  const t = useT()
  const createLink = useCreateAffiliateLink()
  const [createOpen, setCreateOpen] = useState(false)
  const [linkId, setLinkId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')

  useEffect(() => {
    if (!createOpen) {
      setLinkId(undefined)
      setName('')
    }
  }, [createOpen])

  const handleCreate = async () => {
    try {
      await createLink.mutateAsync({
        linkId,
        name: name.trim() || undefined,
      })
      toast.success(t('Affiliate link created'))
      setCreateOpen(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create affiliate link')))
    }
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-10 sm:px-10 sm:py-12">
          <div className="mx-auto flex max-w-2xl flex-col items-center text-center">
            <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <Gift className="h-7 w-7 text-muted-foreground" aria-hidden />
            </div>
            <h2 className="text-[18px] font-semibold tracking-tight text-foreground">
              {t('Earn credits by referring developers')}
            </h2>
            <p className="mt-2 max-w-lg text-[13px] leading-relaxed text-muted-foreground">
              {t(
                'The Affiliates program rewards you when people you invite join Appwrite and upgrade to Pro. Create a link to get started.',
              )}
            </p>
          </div>

          <div className="mx-auto mt-10 grid max-w-3xl gap-3 sm:grid-cols-3">
            {PROGRAM_STEPS.map((step, index) => {
              const Icon = step.icon
              return (
                <div
                  key={step.title}
                  className="rounded-lg border border-border bg-background/60 px-4 py-4 text-start"
                >
                  <div className="flex items-center gap-2.5">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                      <Icon className="h-4 w-4" aria-hidden />
                    </div>
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {t('Step')} {index + 1}
                    </span>
                  </div>
                  <h3 className="mt-3 text-[13px] font-semibold text-foreground">
                    {t(step.title)}
                  </h3>
                  <p className="mt-1.5 text-[12px] leading-relaxed text-muted-foreground">
                    {t(step.description)}
                  </p>
                </div>
              )
            })}
          </div>

          <div className="mx-auto mt-8 max-w-3xl">
            <p className="text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('How rewards work')}
            </p>
            <div className="mt-3 grid gap-3 sm:grid-cols-3">
              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 text-center sm:text-start">
                <p className="text-[15px] font-semibold tabular-nums text-foreground">
                  {formatCurrency(AFFILIATE_REWARD_AMOUNT_USD)}
                </p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t('Credits added to your organization for each Pro upgrade')}
                </p>
              </div>
              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 text-center sm:text-start">
                <p className="text-[15px] font-semibold tabular-nums text-foreground">
                  {AFFILIATE_ATTRIBUTION_DAYS} {t('days')}
                </p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Time after signup during which a Pro upgrade still counts for you',
                  )}
                </p>
              </div>
              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 text-center sm:text-start">
                <p className="text-[15px] font-semibold text-foreground">Pro</p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Only referrals who upgrade to Pro generate a reward',
                  )}
                </p>
              </div>
            </div>
            <div className="mt-6 flex justify-center">
              <Button
                className="h-9 text-[13px]"
                onClick={() => setCreateOpen(true)}
                {...analyticsAttrs('create-affiliate-link')}
              >
                <Link2 className="mr-1.5 h-4 w-4" />
                {t('Create invite link')}
              </Button>
            </div>
          </div>
        </div>
      </div>

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Create link')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Create a shareable invite link. The link ID is your referral code.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="affiliate-empty-link-name">{t('Name')}</Label>
              <Input
                id="affiliate-empty-link-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('Optional name')}
                maxLength={128}
                className="h-9"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="affiliate-empty-link-id">{t('Link ID')}</Label>
              <IdInput
                id="affiliate-empty-link-id"
                value={linkId}
                onChange={setLinkId}
                placeholder={t('Leave blank to auto-generate')}
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setCreateOpen(false)}
              disabled={createLink.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              disabled={createLink.isPending}
              onClick={handleCreate}
              {...analyticsAttrs('create-affiliate-link')}
            >
              {t('Create')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
