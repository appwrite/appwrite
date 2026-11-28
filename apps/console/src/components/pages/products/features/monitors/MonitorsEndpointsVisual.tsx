import { Cloud } from 'lucide-react'
import {
  ArtChip,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  MonitorsCaption,
  MonitorsField,
  MonitorsStatusPill,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Check = {
  type: 'HTTP' | 'DNS' | 'TCP' | 'SMTP'
  name: string
  target: string
  every: string
  firing: boolean
  result: string
}

/** Endpoint checks as the Monitors list shows them, one failing. */
const CHECKS: Check[] = [
  {
    type: 'HTTP',
    name: 'Checkout API health',
    target: 'GET api.checkout.example.com/v1/health',
    every: 'Every 1 min',
    firing: true,
    result: 'HTTP 503 · 412 ms',
  },
  {
    type: 'HTTP',
    name: 'Storefront',
    target: 'GET storefront.example.com',
    every: 'Every 1 min',
    firing: false,
    result: 'HTTP 200 · 86 ms',
  },
  {
    type: 'DNS',
    name: 'Storefront DNS',
    target: 'storefront.example.com · A',
    every: 'Every 5 min',
    firing: false,
    result: '104.18.2.15 · 18 ms',
  },
  {
    type: 'TCP',
    name: 'Queue',
    target: 'mq.example.com:5672',
    every: 'Every 1 min',
    firing: false,
    result: '24 ms',
  },
  {
    type: 'SMTP',
    name: 'Mail server',
    target: 'mail.example.com:587 · STARTTLS',
    every: 'Every 15 min',
    firing: false,
    result: '220 · 140 ms',
  },
]

/** A list of HTTP, DNS, TCP, and SMTP checks, and the settings of the HTTP one that is failing. */
export function MonitorsEndpointsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-0">
        <div className="flex gap-4 border-b border-border px-4 pt-3 text-[11.5px]">
          <span className="border-b-2 border-foreground pb-2 font-medium text-foreground">
            {t('Monitors')}
          </span>
          <span className="pb-2 text-muted-foreground">{t('Alerts')}</span>
        </div>
        <ul className="divide-y divide-border/70">
          {CHECKS.map((check, index) => (
            <li
              key={check.name}
              className="product-hero-rise grid grid-cols-[2.75rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-2.5"
              style={riseStyle(160 + index * 90)}
            >
              <span
                className={cn(
                  'rounded-md border px-1.5 py-0.5 text-center font-mono text-[9.5px] font-semibold',
                  check.firing
                    ? 'border-rose-500/40 text-rose-600 dark:text-rose-400'
                    : 'border-border text-muted-foreground',
                )}
              >
                {check.type}
              </span>
              <span className="min-w-0">
                <span className="block truncate text-[12px] font-medium text-foreground">
                  {t(check.name)}
                </span>
                <span
                  dir="ltr"
                  className="block truncate font-mono text-[10px] text-muted-foreground"
                >
                  {check.target}
                </span>
              </span>
              <span className="flex flex-col items-end gap-1">
                <MonitorsStatusPill status={check.firing ? 'firing' : 'ok'} />
                <span
                  dir="ltr"
                  className={cn(
                    'font-mono text-[9.5px]',
                    check.firing
                      ? 'text-rose-600 dark:text-rose-400'
                      : 'text-muted-foreground',
                  )}
                >
                  {check.result}
                </span>
              </span>
            </li>
          ))}
        </ul>
      </ArtPanel>

      <ArtPanel
        className="relative mt-3 sm:-me-[4%] sm:ms-auto sm:w-[300px]"
        delayMs={700}
        float
        floatDelayMs={400}
        innerClassName="p-3.5"
      >
        <MonitorsCaption>{t('HTTP check')}</MonitorsCaption>
        <dl className="mt-2 space-y-2 text-[11px]">
          <div>
            <dt className="text-muted-foreground">
              {t('Expected status codes')}
            </dt>
            <dd className="mt-1">
              <MonitorsField className="w-full" value={t('Any 2xx status')} />
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">
              {t('Certificate expiry warning')}
            </dt>
            <dd className="mt-1">
              <MonitorsField className="w-full" value={t('14 days')} />
            </dd>
          </div>
        </dl>
        <p className="mt-2.5 flex flex-wrap items-center gap-1 text-[11px] text-foreground">
          {t('Check every')}
          <MonitorsField select value={t('1 min')} />
          {t('and fail after')}
          <MonitorsField select value={t('10 s')} />
        </p>
      </ArtPanel>

      <ArtChip
        className="-top-1 end-0 hidden sm:block"
        delayMs={1000}
        floatDelayMs={300}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Cloud className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Appwrite Cloud, Pro and Scale')}
        </span>
      </ArtChip>
    </div>
  )
}
