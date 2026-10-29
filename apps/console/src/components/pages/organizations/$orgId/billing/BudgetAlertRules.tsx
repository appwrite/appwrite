import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Webhook } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  StatusPill,
  ruleState,
} from '@/components/pages/projects/$projectId/usage/alerts/StatusPill'
import {
  organizationBudgetRulesQueryOptions,
  useOrganizationById,
  useSetBudgetRulesWebhook,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const usd = (amount: number) =>
  amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' })

/**
 * The organization's budget alerts as the alert rules that send them: their
 * status and the spend they last read. The budget settings above own their
 * thresholds, so the only change offered here is a webhook on all of them.
 */
export function BudgetAlertRules({ orgId }: { orgId?: string }) {
  const t = useT()
  const { organization } = useOrganizationById(orgId)
  const rules = useQuery(organizationBudgetRulesQueryOptions(orgId))
  const setWebhook = useSetBudgetRulesWebhook(orgId ?? '')
  const [open, setOpen] = useState(false)
  const [url, setUrl] = useState('')

  // Editions without organization rules answer with an error: nothing to show.
  if (!orgId || rules.isError || !rules.data || rules.data.length === 0) {
    return null
  }

  const budget = Number(organization?.billingBudget ?? 0)
  const webhookUrl =
    rules.data.find((rule) => rule.channels.includes('webhook'))?.webhookUrl ??
    ''

  const save = (next: string) => {
    setWebhook.mutate(
      { rules: rules.data, webhookUrl: next.trim() },
      {
        onSuccess: () => {
          toast.success(
            next.trim() === ''
              ? t('Webhook removed from budget alerts')
              : t('Budget alerts will also post to the webhook'),
          )
          setOpen(false)
        },
        onError: (err: Error) =>
          toast.error(
            getErrorMessage(err, t('Failed to update budget alerts')),
          ),
      },
    )
  }

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Budget alert rules')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'Sent when additional usage reaches each threshold. They follow the budget cap and alerts above.',
            )}
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={() => {
            setUrl(webhookUrl)
            setOpen(true)
          }}
        >
          <Webhook className="me-1.5 h-3.5 w-3.5" />
          {webhookUrl ? t('Edit webhook') : t('Add webhook')}
        </Button>
      </div>
      <ul className="divide-y divide-border border-t border-border">
        {rules.data.map((rule) => {
          const state = ruleState(rule)
          const percent = rule.value ?? 0
          return (
            <li
              key={rule.$id}
              className="grid grid-cols-[84px_minmax(0,1fr)_auto] items-center gap-3 px-6 py-3"
            >
              <StatusPill status={state} />
              <div className="min-w-0">
                <p className="truncate text-[13px] font-medium text-foreground">
                  {rule.threshold >= 100
                    ? t('Cap reached')
                    : t('{percent}% of {budget}')
                        .replace('{percent}', String(rule.threshold))
                        .replace('{budget}', usd(budget))}
                </p>
                <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                  <span className="rounded-full bg-muted px-1.5 py-px text-[11px]">
                    {t('Managed by budget')}
                  </span>
                  {rule.triggeredAt && (
                    <span>
                      {t('Last sent')} <DateTooltip date={rule.triggeredAt} />
                    </span>
                  )}
                </p>
              </div>
              <div className="text-end tabular-nums">
                <p
                  className={cn(
                    'text-[13px] font-semibold',
                    state === 'firing' ? 'text-red-500' : 'text-foreground',
                  )}
                >
                  {rule.value === null ? '–' : usd((budget * percent) / 100)}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {rule.value === null
                    ? t('Waiting for the first check')
                    : `${percent}%`}
                </p>
              </div>
            </li>
          )
        })}
      </ul>
      {webhookUrl && (
        <p className="border-t border-border px-6 py-3 text-[12px] text-muted-foreground">
          {t('Also posted to')}{' '}
          <span className="font-mono text-foreground">{webhookUrl}</span>
        </p>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('Budget alert webhook')}</DialogTitle>
            <DialogDescription>
              {t(
                'Every budget alert also posts a signed request to this URL. Leave it empty to stop.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="budget-webhook-url">{t('Webhook URL')}</Label>
            <Input
              id="budget-webhook-url"
              type="url"
              placeholder="https://example.com/budget"
              value={url}
              onChange={(event) => setUrl(event.target.value)}
              disabled={setWebhook.isPending}
            />
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={setWebhook.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              onClick={() => save(url)}
              disabled={setWebhook.isPending}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
