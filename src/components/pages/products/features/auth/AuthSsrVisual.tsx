import { Check, Cookie } from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtToken as T,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'

const FRAMEWORKS = [
  { id: 'nextjs', label: 'Next.js', icon: '/icons/nextjs.svg' },
  { id: 'nuxt', label: 'Nuxt', icon: '/icons/nuxt.svg' },
  { id: 'svelte', label: 'SvelteKit', icon: '/icons/svelte.svg' },
] as const

export function AuthSsrVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] px-2 pb-14 pt-12 sm:px-6">
      <div className="absolute start-0 top-0 z-[2] flex gap-1.5">
        {FRAMEWORKS.map((framework, index) => (
          <span
            key={framework.id}
            className="product-hero-rise inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 shadow-sm dark:bg-card"
            style={riseStyle(400 + index * 120)}
          >
            <ProductFeaturePublicIcon src={framework.icon} className="size-3.5" />
            <span className="text-[11px] font-medium text-foreground">{framework.label}</span>
          </span>
        ))}
      </div>

      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        title={<span dir="ltr">middleware.ts</span>}
        trailing={<span className="text-[10px] font-medium text-muted-foreground">SSR</span>}
      >
        <pre dir="ltr" className="overflow-hidden font-mono text-[11.5px] leading-[1.8]">
          <code>
            <T tone="keyword">import</T> <T tone="punctuation">{'{'}</T> <T tone="identifier">createSessionClient</T>{' '}
            <T tone="punctuation">{'}'}</T> <T tone="keyword">from</T> <T tone="string">&apos;@appwrite.io/ssr&apos;</T>
            {'\n\n'}
            <T tone="keyword">const</T> <T tone="identifier">client</T> <T tone="operator">=</T>{' '}
            <T tone="function">createSessionClient</T>
            <T tone="punctuation">(</T>
            <T tone="identifier">request</T>
            <T tone="punctuation">)</T>
            {'\n'}
            <T tone="keyword">const</T> <T tone="identifier">user</T> <T tone="operator">=</T> <T tone="keyword">await</T>{' '}
            <T tone="identifier">client</T>
            <T tone="punctuation">.</T>
            <T tone="identifier">account</T>
            <T tone="punctuation">.</T>
            <T tone="function">get</T>
            <T tone="punctuation">()</T>
          </code>
        </pre>
      </ArtWindow>

      <ArtChip className="bottom-2 end-0" delayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Check} tone="success" />
          <p className="text-[12px] font-medium text-foreground">{t('Session verified')}</p>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-[6%]" delayMs={1150} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Cookie} />
          <span dir="ltr" className="font-mono text-[11px] text-muted-foreground">
            Set-Cookie: a_session_…
          </span>
        </div>
      </ArtChip>
    </div>
  )
}
