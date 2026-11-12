import { Lock, Plug } from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtPanel,
  ArtToken as T,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

function ConnectionString() {
  return (
    <code dir="ltr" className="block font-mono text-[11px] leading-5">
      <span className="block whitespace-nowrap">
        <T tone="keyword">postgresql</T>
        <T tone="punctuation">://</T>
        <T tone="identifier">admin</T>
        <T tone="punctuation">:</T>
        <T tone="comment">••••••••</T>
        <T tone="punctuation">@</T>
      </span>
      <span className="block whitespace-nowrap">
        <T tone="property">db-7f3a2c.fra.appwrite.center</T>
        <T tone="punctuation">:</T>
        <T tone="number">5432</T>
        <T tone="punctuation">/</T>
        <T tone="identifier">db-7f3a2c</T>
      </span>
    </code>
  )
}

export function PostgresConnectVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6 text-start">
      <ArtPanel
        className="relative z-[1] w-full sm:w-[384px]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={Plug} />
          <p className="text-[13px] font-semibold text-foreground">
            {t('Credentials')}
          </p>
          <span
            dir="ltr"
            className="ms-auto shrink-0 font-mono text-[11px] text-muted-foreground"
          >
            :5432
          </span>
        </div>

        <div className="mt-3 rounded-lg border border-border bg-muted/25 p-2.5 dark:bg-white/[0.03]">
          <ConnectionString />
        </div>
      </ArtPanel>

      <ArtChip
        className="end-0 top-[52%] hidden sm:block"
        delayMs={520}
        floatDelayMs={600}
      >
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Lock} tone="success" />
          <div>
            <p className="text-[11px] font-medium text-foreground">
              {t('TLS by default')}
            </p>
            <p
              dir="ltr"
              className="font-mono text-[10px] text-muted-foreground"
            >
              sslmode=require
            </p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
