import { Check, FolderLock, Minus } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { ArtChip, ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const BUCKET_RULES = ['team:design/read', 'team:design/create', 'role:developer/update'] as const
const FILE_RULES = ['user:paige/read', 'user:toby/read'] as const

const ACTIONS = [
  { label: 'Read', allowed: true },
  { label: 'Create', allowed: true },
  { label: 'Update', allowed: false },
  { label: 'Delete', allowed: false },
] as const

function RuleChip({ label, accent, delayMs }: { label: string; accent: boolean; delayMs: number }) {
  return (
    <span
      className={cn(
        'product-hero-rise inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px]',
        accent
          ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]'
          : 'border-border bg-muted/30 text-muted-foreground',
      )}
      style={riseStyle(delayMs)}
    >
      {label}
    </span>
  )
}

export function StoragePermissionsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[460px] w-full max-w-[540px] sm:h-[420px]">
      <ArtPanel
        className="absolute start-0 top-[3%] z-[1] w-[min(290px,82%)]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={FolderLock} className="size-8" />
          <div className="min-w-0">
            <p dir="ltr" className="text-start font-mono text-[12px] font-semibold text-foreground">
              avatars
            </p>
            <p className="text-[11px] text-muted-foreground">{t('Bucket permissions apply to all files')}</p>
          </div>
        </div>
        <div dir="ltr" className="mt-3 flex flex-wrap gap-1.5">
          {BUCKET_RULES.map((rule, index) => (
            <RuleChip key={rule} label={rule} accent={index === 0} delayMs={300 + index * 110} />
          ))}
        </div>
      </ArtPanel>

      <div className="pointer-events-none absolute inset-0" aria-hidden>
        <span className="absolute start-[12%] top-[36%] h-[36%] border-s border-dashed border-foreground/25" />
        <span className="absolute start-[12%] top-[72%] w-[10%] border-t border-dashed border-foreground/25 sm:w-[34%]" />
      </div>

      <ArtChip className="end-0 top-0 hidden sm:block" delayMs={700} floatDelayMs={500}>
        <p dir="ltr" className="text-start font-mono text-[10px] text-muted-foreground">
          team:design
        </p>
        <div className="mt-1.5 flex gap-1">
          {ACTIONS.map((action) => (
            <span
              key={action.label}
              className={cn(
                'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px]',
                action.allowed
                  ? 'border-border bg-background text-foreground dark:bg-card'
                  : 'border-dashed border-border text-muted-foreground',
              )}
            >
              {action.allowed ? (
                <Check className="size-2.5 text-[var(--tone-ink)]" strokeWidth={3} aria-hidden />
              ) : (
                <Minus className="size-2.5" aria-hidden />
              )}
              {t(action.label)}
            </span>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[40%] w-[min(220px,62%)]" delayMs={900} floatDelayMs={1100}>
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="text-[11px] font-medium text-foreground">{t('File security')}</p>
            <p className="mt-0.5 text-[10px] leading-4 text-muted-foreground">
              {t('Enable per-file permissions on individual uploads.')}
            </p>
          </div>
          <Switch checked disabled className="mt-0.5 scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
        </div>
      </ArtChip>

      <ArtPanel
        className="absolute bottom-[3%] end-0 z-[1] w-[min(290px,80%)] sm:end-[2%]"
        innerClassName="p-3.5"
        delayMs={500}
        float
        floatDelayMs={300}
      >
        <div className="flex items-center gap-2.5">
          <span
            className="size-8 shrink-0 rounded-full bg-[linear-gradient(135deg,rgb(var(--tone2-rgb)/0.9),rgb(var(--tone-rgb)/0.8))]"
            aria-hidden
          />
          <p dir="ltr" className="min-w-0 truncate text-start font-mono text-[12px] font-semibold text-foreground">
            profile-128.webp
          </p>
        </div>
        <div dir="ltr" className="mt-3 flex flex-wrap gap-1.5">
          {FILE_RULES.map((rule, index) => (
            <RuleChip key={rule} label={rule} accent={index === 0} delayMs={800 + index * 110} />
          ))}
        </div>
        <p className="mt-2.5 text-[10px] leading-4 text-muted-foreground">
          {t('File-level rules override bucket defaults for sensitive assets.')}
        </p>
      </ArtPanel>
    </div>
  )
}
