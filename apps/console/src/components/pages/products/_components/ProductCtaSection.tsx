import type { ProductIcon } from '@/lib/products/types'
import type { ReactNode } from 'react'
import { ProductIconTile, ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { useT } from '@/lib/i18n/translate'

export function ProductCtaSection({
  icon,
  title,
  description,
  children,
}: {
  icon: ProductIcon
  title: string
  description: string
  children: ReactNode
}) {
  const t = useT()

  return (
    <section className="relative border-t border-border px-4 py-16 sm:px-6 sm:py-24">
      <div className="relative isolate mx-auto max-w-5xl overflow-hidden rounded-3xl border border-border bg-card/40 px-6 py-14 text-center sm:px-12 sm:py-20">
        <ProductToneBackdrop variant="cta" />
        <div className="product-tone-hairline absolute inset-x-12 top-0 h-px" aria-hidden />
        <div className="relative z-[1]">
          <div className="flex justify-center">
            <ProductIconTile icon={icon} size="lg" />
          </div>
          <h2 className="mx-auto mt-7 max-w-2xl text-balance font-aeonik-pro text-[32px] font-normal tracking-tight text-foreground sm:text-[44px] leading-none">
            {t(title)}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-[15px] leading-7 text-muted-foreground">
            {t(description)}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-2">{children}</div>
        </div>
      </div>
    </section>
  )
}
