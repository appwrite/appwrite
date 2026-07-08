import { LayoutTemplate, Search } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const TEMPLATE_CARDS = [
  {
    id: 'stripe',
    name: 'Payments with Stripe',
    description: 'Receive card payments and store paid orders.',
    runtime: 'node',
    useCase: 'Payments',
  },
  {
    id: 'openai',
    name: 'Prompt ChatGPT',
    description: 'Ask questions and let GPT answer from your app.',
    runtime: 'python',
    useCase: 'AI',
  },
  {
    id: 'meilisearch',
    name: 'Sync with Meilisearch',
    description: 'Index database rows for search-as-you-type.',
    runtime: 'node',
    useCase: 'Search',
  },
  {
    id: 'discord',
    name: 'Discord Command Bot',
    description: 'Add slash commands to your Discord servers.',
    runtime: 'go',
    useCase: 'Messaging',
  },
] as const

export function FunctionsTemplatesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'functions', label: t('Functions') },
        { id: 'templates', label: t('Templates'), active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Function templates')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Pre-built integrations to skip boilerplate.')}
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('Catalog')}
          </Badge>
        </div>

        <div className="flex items-center gap-2 rounded-lg border border-border bg-background/80 px-3 py-2">
          <Search className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <span className="text-[11px] text-muted-foreground">{t('Search templates...')}</span>
        </div>

        <div className="grid gap-2 sm:grid-cols-2">
          {TEMPLATE_CARDS.map((template, index) => (
            <div
              key={template.id}
              className="rounded-lg border border-border bg-background/80 p-3 transition-[border-color,background-color] duration-300 group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/40"
              style={{ transitionDelay: `${index * 50}ms` }}
            >
              <div className="flex items-start justify-between gap-2">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                  <LayoutTemplate className="size-3.5 text-muted-foreground" aria-hidden />
                </span>
                <Badge variant="info" className="shrink-0 text-[10px]">
                  {t(template.useCase)}
                </Badge>
              </div>
              <p className="mt-2 text-[12px] font-semibold text-foreground">{t(template.name)}</p>
              <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
                {t(template.description)}
              </p>
              <div className="mt-2 flex items-center gap-1.5">
                <LanguageIcon language={template.runtime} size="sm" />
                <span className="text-[10px] text-muted-foreground capitalize">
                  {template.runtime}
                </span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
