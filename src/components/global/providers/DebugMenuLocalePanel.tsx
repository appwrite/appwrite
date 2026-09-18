import { useEffect, useState } from 'react'
import { Copy, Loader2, RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import { localeQueryOptions } from '@/lib/react-query/hooks/locale'
import {
  isStartPlanEligibleCountry,
  normalizeCountryCode,
  START_PLAN_ELIGIBLE_COUNTRIES,
} from '@/lib/pricing/start-plan'

const MOCK_PRESETS = [
  { code: null, label: 'Auto', description: 'Use live locale.get() country' },
  { code: 'IN', label: 'India', description: 'Shows the Start plan' },
  { code: 'NP', label: 'Nepal', description: 'Shows the Start plan' },
  { code: 'US', label: 'United States', description: 'Hides the Start plan' },
] as const

async function copyValue(label: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied`)
  } catch {
    toast.error('Could not copy to clipboard')
  }
}

function LocaleRow({
  label,
  value,
  loading,
}: {
  label: string
  value: string | number | boolean | null | undefined
  loading: boolean
}) {
  const display =
    loading && (value === null || value === undefined)
      ? '…'
      : value === null || value === undefined || value === ''
        ? 'Missing'
        : String(value)
  const canCopy = !loading && value !== null && value !== undefined && value !== ''

  return (
    <div className="flex items-start justify-between gap-3 text-[11px]">
      <p className="text-[var(--network-globe-edge)]/80">{label}</p>
      <div className="flex min-w-0 items-center gap-1.5">
        <code
          className={cn(
            'max-w-[220px] truncate font-medium',
            loading || value === null || value === undefined || value === ''
              ? 'text-[var(--network-globe-edge)]/70'
              : 'text-foreground',
          )}
          title={canCopy ? display : undefined}
        >
          {display}
        </code>
        <button
          type="button"
          disabled={!canCopy}
          onClick={() => {
            if (!canCopy) return
            void copyValue(label, display)
          }}
          className="flex h-5 w-5 shrink-0 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
          aria-label={`Copy ${label}`}
        >
          <Copy className="h-3 w-3" aria-hidden />
        </button>
      </div>
    </div>
  )
}

export function DebugMenuLocalePanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const [customCountry, setCustomCountry] = useState(
    () => overrides.mockLocaleCountry ?? '',
  )
  const { data, isFetching, isError, error, refetch } = useQuery(
    localeQueryOptions(),
  )

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  const mockCountry = overrides.mockLocaleCountry
  const liveCountry = normalizeCountryCode(data?.countryCode)
  const effectiveCountry = mockCountry ?? liveCountry
  const loading = isFetching && !data

  return (
    <div className="space-y-3 px-1 py-1" aria-label="Locale">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">Visitor locale</p>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Live values come from{' '}
          <code className="rounded bg-muted/60 px-1 py-0.5 text-[10px]">
            locale.get()
          </code>
          . Mock country overrides Start plan eligibility without changing the
          API response.
        </p>
      </div>

      <div className="space-y-2.5 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-3">
        <LocaleRow label="IP" value={data?.ip} loading={loading} />
        <LocaleRow label="Country" value={data?.country} loading={loading} />
        <LocaleRow
          label="Country code"
          value={data?.countryCode}
          loading={loading}
        />
        <LocaleRow
          label="Continent"
          value={data?.continent}
          loading={loading}
        />
        <LocaleRow
          label="Continent code"
          value={data?.continentCode}
          loading={loading}
        />
        <LocaleRow label="EU" value={data?.eu} loading={loading} />
        <LocaleRow label="Currency" value={data?.currency} loading={loading} />
        <LocaleRow label="City" value={data?.city} loading={loading} />
        <LocaleRow label="Time zone" value={data?.timeZone} loading={loading} />
        {isError ? (
          <p className="text-[11px] text-amber-400/90">
            {error instanceof Error ? error.message : 'Failed to load locale'}
          </p>
        ) : null}
      </div>

      <div className="space-y-2 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-3">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
          Effective country
        </p>
        <p className="text-[13px] font-medium text-foreground">
          {effectiveCountry ?? 'Unknown'}
          {mockCountry ? (
            <span className="ms-1.5 text-[11px] font-normal text-[var(--network-globe-edge)]/80">
              (mocked)
            </span>
          ) : null}
        </p>
        <p className="text-[11px] text-[var(--network-globe-edge)]/80">
          Start plan:{' '}
          {isStartPlanEligibleCountry(effectiveCountry)
            ? `visible (${START_PLAN_ELIGIBLE_COUNTRIES.join(', ')})`
            : 'hidden'}
        </p>
      </div>

      <div className="space-y-2 rounded-lg px-3 pb-1">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
          Mock country
        </p>
        <div className="grid grid-cols-2 gap-2">
          {MOCK_PRESETS.map((preset) => {
            const active = mockCountry === preset.code
            return (
              <button
                key={preset.label}
                type="button"
                onClick={() => {
                  setDebugOverride('mockLocaleCountry', preset.code)
                  setCustomCountry(preset.code ?? '')
                }}
                className={cn(
                  'flex flex-col rounded-lg border px-3 py-2.5 text-start transition-colors',
                  active
                    ? 'border-[color-mix(in_srgb,var(--network-globe-edge)_60%,var(--border))] bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)]'
                    : 'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]',
                )}
              >
                <span className="text-[13px] font-medium text-foreground">
                  {preset.label}
                </span>
                <span className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
                  {preset.description}
                </span>
              </button>
            )
          })}
        </div>
        <form
          className="flex gap-2 pt-1"
          onSubmit={(event) => {
            event.preventDefault()
            const normalized = normalizeCountryCode(customCountry)
            if (!normalized) {
              setDebugOverride('mockLocaleCountry', null)
              setCustomCountry('')
              return
            }
            setDebugOverride('mockLocaleCountry', normalized)
            setCustomCountry(normalized)
          }}
        >
          <Input
            value={customCountry}
            onChange={(event) => setCustomCountry(event.target.value)}
            placeholder="Custom ISO code"
            maxLength={2}
            className="h-9 text-[13px] uppercase"
            aria-label="Custom country code"
          />
          <Button type="submit" size="sm" className="h-9 shrink-0 text-[13px]">
            Apply
          </Button>
        </form>
      </div>

      <div className="px-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 w-full border-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--border))] bg-transparent text-[13px] text-foreground hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={isFetching}
          onClick={() => void refetch()}
        >
          {isFetching ? (
            <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <RefreshCw className="me-1.5 h-3.5 w-3.5" />
          )}
          Refresh locale
        </Button>
      </div>
    </div>
  )
}
