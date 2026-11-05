import type { CSSProperties } from 'react'
import { Copy } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtPanel,
  ArtToken as T,
  ArtWindow,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'

const COMPATIBLE_TOOLS = [
  { id: 'rclone', name: 'rclone', iconSrc: '/icons/rclone.svg' },
  { id: 'terraform', name: 'Terraform', iconSrc: '/icons/terraform.svg' },
  { id: 'aws-cli', name: 'AWS CLI', iconSrc: '/icons/amazon.svg' },
  { id: 'cyberduck', name: 'Cyberduck', iconSrc: '/icons/cyberduck.svg' },
] as const

const CREDENTIALS = [
  { label: 'Endpoint', value: 'https://fra.cloud.appwrite.io/v1/s3', masked: false },
  { label: 'Access key', value: '6512a8f0e4c1', masked: false },
  { label: 'Secret key', value: '••••••••••••••••', masked: true },
] as const

export function StorageS3Visual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] pb-36 sm:pe-14">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        title={<span dir="ltr">~/site · zsh</span>}
        bodyClassName="px-4 py-3.5"
      >
        <pre dir="ltr" className="overflow-hidden font-mono text-[11px] leading-[1.85]">
          <code>
            <T tone="comment">$</T> <T tone="function">rclone</T> <T tone="identifier">copy</T>{' '}
            <T tone="string">./assets</T> <T tone="string">appwrite:marketing-assets</T>
            {'\n'}
            <T tone="comment">Transferred:</T> <T tone="number">128</T> <T tone="comment">/</T>{' '}
            <T tone="number">128</T>
            <T tone="comment">, </T>
            <T tone="number">100%</T>
            {'\n'}
            <T tone="comment">Elapsed time:</T> <T tone="number">4.2s</T>
            {'\n\n'}
            <T tone="comment">$</T> <T tone="function">aws</T> <T tone="identifier">s3 ls</T>{' '}
            <T tone="string">s3://marketing-assets</T>
            {'\n'}
            <T tone="comment">2026-10-03</T> <T tone="number">842 KB</T> <T tone="identifier">hero-banner.webp</T>
            {'\n'}
            <T tone="comment">2026-10-03</T> <T tone="number">1.2 MB</T> <T tone="identifier">pricing.pdf</T>
            <span className="ms-0.5 inline-block h-3 w-1.5 translate-y-0.5 animate-[ai-mock-cursor-blink_1s_step-end_infinite] bg-foreground/60 motion-reduce:animate-none" />
          </code>
        </pre>
      </ArtWindow>

      <div className="absolute end-0 top-10 z-[2] hidden flex-col gap-2 sm:flex">
        {COMPATIBLE_TOOLS.map((tool, index) => (
          <span
            key={tool.id}
            className="product-hero-rise"
            style={riseStyle(500 + index * 110)}
            title={tool.name}
          >
            <span
              className="product-hero-float flex size-11 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card"
              style={floatStyle(index * 380) as CSSProperties}
            >
              <ProductFeaturePublicIcon src={tool.iconSrc} className="size-[18px]" />
            </span>
          </span>
        ))}
      </div>

      <ArtPanel
        className="absolute bottom-0 start-0 z-[2] w-[min(320px,100%)] sm:start-[6%]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={700}
        float
        floatDelayMs={400}
      >
        <div className="flex items-center justify-between gap-2">
          <p className="text-[12px] font-semibold text-foreground">{t('S3-compatible access')}</p>
          <Badge variant="info" className="shrink-0 text-[10px]">
            SigV4
          </Badge>
        </div>
        <p className="mt-0.5 text-[11px] text-muted-foreground">{t('Project-scoped endpoint with SigV4 signing.')}</p>
        <div className="mt-2.5 space-y-1.5">
          {CREDENTIALS.map((credential) => (
            <div
              key={credential.label}
              className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2.5 py-1.5"
            >
              <span className="w-[72px] shrink-0 text-[10px] text-muted-foreground">{t(credential.label)}</span>
              <span dir="ltr" className="min-w-0 flex-1 truncate text-start font-mono text-[10px] text-foreground">
                {credential.value}
              </span>
              <Copy className="size-3 shrink-0 text-muted-foreground" aria-hidden />
            </div>
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}
