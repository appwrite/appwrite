import { FileText, Lock } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  ArtChip,
  ArtConnector,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const FILES = [
  { name: 'contract.pdf', size: '1.2 MB', cipher: '9f2c 41ab e07d 6c19', offset: 'sm:me-6' },
  { name: 'backup.zip', size: '4.8 MB', cipher: 'c83e 0d5f 7a22 b4e1', offset: 'sm:me-0' },
  { name: 'credentials.json', size: '12 KB', cipher: '1b7a f9c0 34de 82f5', offset: 'sm:me-4' },
] as const

function VaultEmblem() {
  return (
    <div className="relative aspect-square w-[150px] shrink-0 sm:w-[160px]" aria-hidden>
      <div className="absolute inset-0 rounded-full border border-dashed border-foreground/15" />
      <div className="product-hero-orbit absolute inset-[14%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]" />
      <div className="absolute inset-[20%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone2-rgb)/0.3),transparent_70%)]" />
      <div className="product-tone-shadow absolute inset-[30%] flex items-center justify-center rounded-[30%] border border-[rgb(var(--tone-rgb)/0.45)] bg-background dark:bg-card">
        <Lock className="size-7 text-[var(--tone-ink)]" strokeWidth={1.5} />
      </div>
    </div>
  )
}

export function StorageEncryptionVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[580px] pb-6 pt-24 sm:pt-20">
      <ArtChip className="end-0 top-0 w-[min(250px,100%)]" delayMs={200}>
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-foreground">{t('Encryption')}</p>
            <p className="mt-0.5 text-[10px] leading-4 text-muted-foreground">
              {t('Applies to new files in this bucket')}
            </p>
          </div>
          <Switch checked disabled className="mt-0.5 scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
        </div>
      </ArtChip>

      <div className="flex flex-col items-center gap-4 sm:flex-row sm:gap-0">
        <div className="w-full min-w-0 flex-1 space-y-2">
          {FILES.map((file, index) => (
            <ArtPanel
              key={file.name}
              className={file.offset}
              innerClassName="flex items-center gap-2 px-3 py-2"
              delayMs={80 + index * 120}
            >
              <FileText className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <span dir="ltr" className="min-w-0 truncate text-[12px] text-foreground">
                {file.name}
              </span>
              <span dir="ltr" className="ms-auto shrink-0 font-mono text-[10px] text-muted-foreground">
                {file.size}
              </span>
            </ArtPanel>
          ))}
        </div>

        <div className="flex items-center">
          <div className="hidden w-8 sm:block" aria-hidden>
            <ArtConnector travel travelDelayMs={300} />
          </div>
          <div className="product-hero-rise" style={riseStyle(0)}>
            <VaultEmblem />
          </div>
          <div className="hidden w-8 sm:block" aria-hidden>
            <ArtConnector travel travelDelayMs={1500} />
          </div>
        </div>

        <div className="w-full min-w-0 flex-1">
          <div className="space-y-2">
            {FILES.map((file, index) => (
              <div
                key={file.cipher}
                className={cn(
                  'product-hero-rise flex items-center gap-2 rounded-xl border border-dashed border-[rgb(var(--tone-rgb)/0.4)] bg-[rgb(var(--tone-rgb)/0.05)] px-3 py-2',
                  index === 1 && 'sm:ms-4',
                )}
                style={riseStyle(650 + index * 120)}
              >
                <Lock className="size-3 shrink-0 text-[var(--tone-ink)]" aria-hidden />
                <span dir="ltr" className="min-w-0 truncate font-mono text-[11px] tracking-wide text-muted-foreground">
                  {file.cipher}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="product-hero-rise mt-8 flex flex-col items-center gap-2 text-center" style={riseStyle(1100)}>
        <span className="inline-flex items-center gap-2 rounded-full border border-border bg-background px-2.5 py-1 shadow-sm dark:bg-card">
          <span dir="ltr" className="font-mono text-[11px] font-medium text-foreground">
            documents
          </span>
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('Encrypted')}
          </Badge>
        </span>
        <p className="max-w-[360px] text-[11px] leading-5 text-muted-foreground">
          {t('Encrypt new uploads at rest so leaked files stay unreadable.')}
        </p>
      </div>
    </div>
  )
}
