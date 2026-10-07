import { Search } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Template = {
  id: string
  name: string
  description: string
  runtime: string
  useCase: string
  icon?: string
}

const TEMPLATE_COLUMNS: Template[][] = [
  [
    {
      id: 'stripe',
      name: 'Payments with Stripe',
      description: 'Receive card payments and store paid orders.',
      runtime: 'node',
      useCase: 'Payments',
      icon: '/icons/stripe.svg',
    },
    {
      id: 'meilisearch',
      name: 'Sync with Meilisearch',
      description: 'Index database rows for search-as-you-type.',
      runtime: 'node',
      useCase: 'Search',
    },
  ],
  [
    {
      id: 'openai',
      name: 'Prompt ChatGPT',
      description: 'Ask questions and let GPT answer from your app.',
      runtime: 'python',
      useCase: 'AI',
      icon: '/icons/chatgpt.svg',
    },
    {
      id: 'discord',
      name: 'Discord Command Bot',
      description: 'Add slash commands to your Discord servers.',
      runtime: 'go',
      useCase: 'Messaging',
      icon: '/icons/discord-simple.svg',
    },
  ],
]

const USE_CASE_FILTERS = ['All', 'Payments', 'AI', 'Search', 'Messaging'] as const

const RUNTIME_LABELS: Record<string, string> = {
  node: 'Node.js',
  python: 'Python',
  go: 'Go',
}

function TemplateTile({
  template,
  highlighted,
  delayMs,
  floatDelayMs,
}: {
  template: Template
  highlighted: boolean
  delayMs: number
  floatDelayMs: number
}) {
  const t = useT()
  return (
    <ArtPanel
      delayMs={delayMs}
      float
      floatDelayMs={floatDelayMs}
      innerClassName={cn(
        'p-3.5',
        highlighted &&
          'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
          {template.icon ? (
            <ProductFeaturePublicIcon src={template.icon} className="size-[18px]" />
          ) : (
            <Search className="size-4 text-foreground" aria-hidden />
          )}
        </span>
        <Badge variant="info" className="shrink-0 text-[10px]">
          {t(template.useCase)}
        </Badge>
      </div>
      <p className="mt-3 text-[12px] font-semibold text-foreground">{t(template.name)}</p>
      <p className="mt-1 text-[11px] leading-5 text-muted-foreground">{t(template.description)}</p>
      <div className="mt-3 flex items-center gap-1.5">
        <LanguageIcon language={template.runtime} size="sm" className="size-4" />
        <span className="text-[10px] text-muted-foreground">{RUNTIME_LABELS[template.runtime]}</span>
      </div>
    </ArtPanel>
  )
}

export function FunctionsTemplatesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px]">
      <div className="flex flex-col items-center gap-2.5">
        <ArtPanel
          className="w-full max-w-[320px]"
          innerClassName="flex items-center gap-2 rounded-full px-3.5 py-2"
          delayMs={0}
        >
          <Search className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <span className="text-[11px] text-muted-foreground">{t('Search templates...')}</span>
          <span className="ms-auto rounded-full bg-[rgb(var(--tone-rgb)/0.12)] px-2 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]">
            {t('Function templates')}
          </span>
        </ArtPanel>
        <div className="flex flex-wrap justify-center gap-1.5">
          {USE_CASE_FILTERS.map((filter, index) => (
            <span
              key={filter}
              className={cn(
                'product-hero-rise rounded-full border px-2.5 py-1 text-[11px]',
                index === 0
                  ? 'border-foreground/20 bg-foreground text-background'
                  : 'border-border bg-background text-muted-foreground shadow-sm dark:bg-card',
              )}
              style={riseStyle(150 + index * 70)}
            >
              {t(filter)}
            </span>
          ))}
        </div>
      </div>

      <div className="mt-6 grid gap-3 sm:grid-cols-2">
        {TEMPLATE_COLUMNS.map((column, columnIndex) => (
          <div key={columnIndex} className={cn('space-y-3', columnIndex === 1 && 'sm:pt-10')}>
            {column.map((template, rowIndex) => (
              <TemplateTile
                key={template.id}
                template={template}
                highlighted={columnIndex === 0 && rowIndex === 0}
                delayMs={400 + (rowIndex * 2 + columnIndex) * 130}
                floatDelayMs={(rowIndex * 2 + columnIndex) * 450}
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
