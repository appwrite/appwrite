/**
 * Domain Type Card
 *
 * Card-style choice between a web (HTTP) and an email (SMTP) domain at the
 * top of the function's Add domain wizard, in the same style as
 * DomainTargetCard. Only rendered behind the `functionEmail` flag.
 */

import { Globe, Mail } from 'lucide-react'
import { Link, useParams } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type DomainType = 'http' | 'smtp'

export interface DomainTypeCardProps {
  value: DomainType
  onChange: (v: DomainType) => void
  /** Email domains need the function's email trigger turned on first. */
  emailEnabled: boolean
  disabled?: boolean
}

export function DomainTypeCard({
  value,
  onChange,
  emailEnabled,
  disabled = false,
}: DomainTypeCardProps) {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const emailDisabled = disabled || (!emailEnabled && value !== 'smtp')

  const option = (selected: boolean, optionDisabled: boolean) =>
    cn(
      'text-start rounded-lg border p-4 transition-all cursor-pointer',
      selected
        ? 'border-foreground bg-primary/5'
        : 'border-border hover:border-muted-foreground/50',
      optionDisabled && 'opacity-50 cursor-not-allowed',
    )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Type')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <button
            type="button"
            onClick={() => onChange('http')}
            disabled={disabled}
            className={option(value === 'http', disabled)}
          >
            <Globe className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">{t('Web')}</div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              {t('Serve HTTP requests on your domain')}
            </p>
          </button>

          <button
            type="button"
            onClick={() => onChange('smtp')}
            disabled={emailDisabled}
            className={option(value === 'smtp', emailDisabled)}
          >
            <Mail className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">{t('Email')}</div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              {t('Run on mail sent to any address on your domain')}
            </p>
          </button>
        </div>

        {!emailEnabled ? (
          <p className="text-[12px] text-muted-foreground">
            {t('Email domains need the email trigger.')}{' '}
            <Link
              to="/projects/$projectId/functions/$functionId/settings/executions"
              params={{ projectId: projectId!, functionId: functionId! }}
              className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
            >
              {t('Enable it in settings')}
            </Link>
          </p>
        ) : null}
      </div>
    </div>
  )
}
