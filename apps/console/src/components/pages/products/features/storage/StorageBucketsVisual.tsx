import { FileImage, FileText, FileVideo, Search, Trash2, Upload } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { ArtChip, ArtToken as T, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const FILES = [
  { name: 'hero-banner.webp', size: '842 KB', icon: FileImage, selected: true },
  { name: 'pricing.pdf', size: '1.2 MB', icon: FileText, selected: true },
  { name: 'team-photo.jpg', size: '3.4 MB', icon: FileImage, selected: false },
  { name: 'launch-video.mp4', size: '24 MB', icon: FileVideo, selected: false },
] as const

const BACK_BUCKETS = [
  { name: 'documents', count: '54', className: 'inset-x-[12%] top-0 opacity-60' },
  { name: 'avatars', count: '2,310', className: 'inset-x-[6%] top-[26px] opacity-85' },
] as const

const CHECKBOX_CLASS =
  'disabled:cursor-default disabled:opacity-100 data-[state=checked]:border-[var(--tone-ink)] data-[state=checked]:bg-[var(--tone-ink)] data-[state=checked]:text-background dark:data-[state=checked]:bg-[var(--tone-ink)]'

export function StorageBucketsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[500px] pb-16 pt-[54px]">
      {BACK_BUCKETS.map((bucket, index) => (
        <div
          key={bucket.name}
          className={cn('product-hero-rise absolute h-24', bucket.className)}
          style={riseStyle(200 + index * 120)}
          aria-hidden
        >
          <div className="flex h-full items-start justify-between rounded-xl border border-border bg-background px-3.5 py-1.5 shadow-sm dark:border-white/10 dark:bg-card">
            <span dir="ltr" className="font-mono text-[10px] font-medium text-muted-foreground">
              {bucket.name}
            </span>
            <span dir="ltr" className="text-[10px] text-muted-foreground">
              {bucket.count} {t('files')}
            </span>
          </div>
        </div>
      ))}

      <div
        className="product-hero-rise product-tone-shadow relative z-[1] overflow-hidden rounded-xl border border-border bg-background dark:border-white/10 dark:bg-card"
        style={riseStyle(400)}
      >
        <div className="flex items-center justify-between gap-3 px-3.5 pb-2.5 pt-3">
          <div className="min-w-0">
            <p dir="ltr" className="text-start font-mono text-[12px] font-semibold text-foreground">
              marketing-assets
            </p>
            <p className="mt-0.5 text-[11px] text-muted-foreground">
              128 {t('files')} · 2.1 GB
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-1.5">
            <div className="hidden h-7 w-[140px] items-center gap-1.5 rounded-md border border-border bg-muted/30 px-2 sm:flex">
              <Search className="size-3 text-muted-foreground" aria-hidden />
              <span className="truncate text-[11px] text-muted-foreground">{t('Search files...')}</span>
            </div>
            <span className="inline-flex h-7 items-center gap-1.5 rounded-md bg-foreground px-2.5 text-[11px] font-medium text-background">
              <Upload className="size-3" aria-hidden />
              {t('Upload')}
            </span>
          </div>
        </div>
        <div className="divide-y divide-border border-t border-border">
          {FILES.map((file, index) => {
            const Icon = file.icon
            return (
              <div
                key={file.name}
                className={cn(
                  'product-hero-rise flex items-center gap-2.5 px-3.5 py-2',
                  file.selected && 'bg-[rgb(var(--tone-rgb)/0.06)]',
                )}
                style={riseStyle(550 + index * 90)}
              >
                <Checkbox checked={file.selected} disabled aria-hidden className={CHECKBOX_CLASS} />
                <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                <span dir="ltr" className="min-w-0 truncate text-[12px] text-foreground">
                  {file.name}
                </span>
                <span dir="ltr" className="ms-auto shrink-0 font-mono text-[10px] text-muted-foreground">
                  {file.size}
                </span>
              </div>
            )
          })}
        </div>
      </div>

      <ArtChip className="bottom-0 start-[3%]" delayMs={1100} floatDelayMs={600}>
        <div className="flex items-center gap-2 whitespace-nowrap">
          <Badge variant="inactive" className="shrink-0 text-[10px]">
            {t('2 selected')}
          </Badge>
          <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/30 px-2 py-1 text-[11px] font-medium text-foreground">
            <Trash2 className="size-3" aria-hidden />
            {t('Delete selected')}
          </span>
        </div>
      </ArtChip>

      <ArtChip className="bottom-1 end-0 hidden sm:block" delayMs={1300} floatDelayMs={300}>
        <p dir="ltr" className="font-mono text-[10px] leading-relaxed">
          <T tone="identifier">storage</T>
          <T tone="punctuation">.</T>
          <T tone="function">listFiles</T>
          <T tone="punctuation">(</T>
          <T tone="string">&apos;marketing-assets&apos;</T>
          <T tone="punctuation">)</T>
        </p>
      </ArtChip>
    </div>
  )
}
