import { Badge } from '@/components/ui/badge'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { Syn } from '@/components/pages/home/product-bento/MockSyntax'
import { useT } from '@/lib/i18n/translate'

const FRAMEWORKS = [
  { id: 'nextjs', label: 'Next.js', icon: '/icons/nextjs.svg' },
  { id: 'nuxt', label: 'Nuxt', icon: '/icons/nuxt.svg' },
  { id: 'svelte', label: 'SvelteKit', icon: '/icons/svelte.svg' },
] as const

export function AuthSsrVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Server-side session')} eyebrow="SSR">
      <div className="space-y-4">
        <div className="flex flex-wrap gap-2">
          {FRAMEWORKS.map((framework) => (
            <div
              key={framework.id}
              className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background/80 px-2.5 py-1.5"
            >
              <ProductFeaturePublicIcon src={framework.icon} className="size-3.5" />
              <span className="text-[11px] font-medium text-foreground">{framework.label}</span>
            </div>
          ))}
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-muted/20 font-mono text-[10px] leading-relaxed sm:text-[11px]">
          <div className="border-b border-border bg-muted/15 px-3 py-1.5 text-[10px] text-muted-foreground">
            middleware.ts
          </div>
          <div className="space-y-0.5 p-3">
            <div>
              <Syn tone="keyword">import</Syn> <Syn tone="punctuation">{'{'}</Syn>{' '}
              <Syn tone="identifier">createSessionClient</Syn>{' '}
              <Syn tone="punctuation">{'}'}</Syn> <Syn tone="keyword">from</Syn>{' '}
              <Syn tone="string">&apos;@appwrite.io/ssr&apos;</Syn>
            </div>
            <div className="h-1" />
            <div>
              <Syn tone="keyword">const</Syn> <Syn tone="identifier">client</Syn>{' '}
              <Syn tone="operator">=</Syn> <Syn tone="function">createSessionClient</Syn>
              <Syn tone="punctuation">(</Syn>
              <Syn tone="identifier">request</Syn>
              <Syn tone="punctuation">)</Syn>
            </div>
            <div>
              <Syn tone="keyword">const</Syn> <Syn tone="identifier">account</Syn>{' '}
              <Syn tone="operator">=</Syn> <Syn tone="keyword">await</Syn>{' '}
              <Syn tone="identifier">client</Syn>
              <Syn tone="punctuation">.</Syn>
              <Syn tone="identifier">account</Syn>
              <Syn tone="punctuation">.</Syn>
              <Syn tone="function">get</Syn>
              <Syn tone="punctuation">()</Syn>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 opacity-80 transition-opacity duration-500 group-hover/visual:opacity-100 motion-reduce:opacity-100">
          <Badge variant="success" className="text-[10px]">
            {t('Session verified')}
          </Badge>
          <span className="rounded-md border border-border bg-background/80 px-2 py-1 font-mono text-[10px] text-muted-foreground">
            Set-Cookie: a_session_...
          </span>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
