'use client'

import { useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import {
  PREMIUM_GEO_PROMO_DESCRIPTION,
  PREMIUM_GEO_PROMO_HINTS,
  PREMIUM_GEO_PROMO_LEARN_MORE_PATH,
} from '@/lib/billing/premium-geo-promo'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'

type CreateProjectSecurityAddonSectionProps = {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
  paymentMethodMissing?: boolean
  addonPrice: Models.AddonPrice | null
}

export function CreateProjectSecurityAddonSection({
  checked,
  onCheckedChange,
  disabled = false,
  paymentMethodMissing = false,
  addonPrice,
}: CreateProjectSecurityAddonSectionProps) {
  const t = useT()
  const [open, setOpen] = useState(true)

  const checkboxDisabled = disabled || paymentMethodMissing
  const monthlyLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : null

  const pricingLine = monthlyLabel
    ? t('{price}/mo, prorated.').replace('{price}', monthlyLabel)
    : t('Billed monthly, prorated.')

  const checkbox = (
    <Checkbox
      id="create-project-premium-geo-security"
      checked={checked}
      onCheckedChange={(value) => onCheckedChange(value === true)}
      disabled={checkboxDisabled}
      className="shrink-0"
    />
  )

  const checkboxControl = paymentMethodMissing ? (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{checkbox}</span>
      </TooltipTrigger>
      <TooltipContent side="right" className="max-w-xs text-[12px]">
        {t(
          'Add a payment method to your organization before enabling this addon.',
        )}
      </TooltipContent>
    </Tooltip>
  ) : (
    checkbox
  )

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <CollapsibleTrigger asChild>
        <button
          type="button"
          className="flex cursor-pointer items-center gap-1.5 text-[13px] font-medium text-foreground hover:text-foreground/80"
        >
          <ChevronDown
            className={cn(
              'h-4 w-4 text-muted-foreground transition-transform',
              !open && '-rotate-90',
            )}
            aria-hidden
          />
          {t('Advanced security')}
        </button>
      </CollapsibleTrigger>
      <CollapsibleContent className="pt-3">
        <label
          htmlFor="create-project-premium-geo-security"
          className={cn(
            'flex cursor-pointer items-start gap-4 rounded-lg border px-3.5 py-3 transition-colors',
            checked
              ? 'border-primary/40 bg-primary/[0.05]'
              : 'border-border bg-muted/15 hover:bg-muted/25',
            checkboxDisabled && 'cursor-not-allowed opacity-70',
          )}
        >
          <div className="shrink-0 self-start pt-0.5">{checkboxControl}</div>
          <div className="min-w-0 flex-1 space-y-2">
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {t('Premium Geo DB')}
              </p>
              <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                {t(PREMIUM_GEO_PROMO_DESCRIPTION)} {pricingLine}
              </p>
            </div>
            <p className="text-[11px] leading-snug text-muted-foreground/90">
              {PREMIUM_GEO_PROMO_HINTS.map((hint, index) => (
                <span key={hint}>
                  {index > 0 ? (
                    <span className="text-muted-foreground/45"> · </span>
                  ) : null}
                  {t(hint)}
                </span>
              ))}
              <span className="text-muted-foreground/45"> · </span>
              {t('+ more')}
              <span className="text-muted-foreground/45"> · </span>
              <BlogPageAnchor
                href={PREMIUM_GEO_PROMO_LEARN_MORE_PATH}
                {...analyticsAttrs('premium-geo-overview-promo-learn-more')}
                className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
              >
                {t('Learn more')}
              </BlogPageAnchor>
            </p>
          </div>
        </label>
      </CollapsibleContent>
    </Collapsible>
  )
}
