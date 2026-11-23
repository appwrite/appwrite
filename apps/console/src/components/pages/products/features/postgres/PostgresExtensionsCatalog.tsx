import { Puzzle, Sparkles } from 'lucide-react'
import {
  ArtPanel,
  ArtToken as T,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Extension = {
  /** Name you pass to the API, as it appears in the catalog. */
  key: string
  name: string
  description: string
}

type ExtensionCategory = {
  id: string
  title: string
  /** The first category takes the product tone, the rest take the secondary tone. */
  toned: boolean
  extensions: Extension[]
}

const CATEGORIES: ExtensionCategory[] = [
  {
    id: 'search',
    title: 'Search and AI',
    toned: true,
    extensions: [
      {
        key: 'vector',
        name: 'pgvector',
        description:
          'Vector data type and similarity search for embeddings, next to your relational data.',
      },
      {
        key: 'pg_trgm',
        name: 'pg_trgm',
        description:
          'Trigram matching for fuzzy string search, typo tolerance, and similarity ranking.',
      },
    ],
  },
  {
    id: 'spatial',
    title: 'Geospatial',
    toned: false,
    extensions: [
      {
        key: 'postgis',
        name: 'PostGIS',
        description:
          'Spatial types, indexes, and functions for geographic objects and radius queries.',
      },
      {
        key: 'ltree',
        name: 'ltree',
        description:
          'Represent and query hierarchical, tree-like data such as categories and org charts.',
      },
    ],
  },
  {
    id: 'types',
    title: 'Security and data types',
    toned: false,
    extensions: [
      {
        key: 'pgcrypto',
        name: 'pgcrypto',
        description:
          'Cryptographic functions for hashing and encrypting values inside the database.',
      },
      {
        key: 'citext',
        name: 'citext',
        description:
          'Case-insensitive text type, so comparisons ignore letter case.',
      },
    ],
  },
]

function ExtensionRow({
  extension,
  toned,
  index,
}: {
  extension: Extension
  toned: boolean
  index: number
}) {
  const t = useT()

  return (
    <div
      className="product-hero-rise py-4 text-start first:pt-0 last:pb-0"
      style={riseStyle(200 + index * 110)}
    >
      <div className="flex items-center gap-2">
        <span
          dir="ltr"
          className={cn(
            'rounded-md px-2 py-0.5 font-mono text-[11.5px]',
            toned
              ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
              : 'bg-[rgb(var(--tone2-rgb)/0.18)] text-foreground',
          )}
        >
          {extension.key}
        </span>
        <span className="min-w-0 truncate text-[13px] font-medium text-foreground">
          {extension.name}
        </span>
      </div>
      <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
        {t(extension.description)}
      </p>
    </div>
  )
}

export function PostgresExtensionsCatalog({
  className,
}: {
  className?: string
}) {
  const t = useT()

  return (
    <div className={cn('pt-4', className)}>
      <div className="grid gap-10 lg:grid-cols-3 lg:gap-12">
        {CATEGORIES.map((category, categoryIndex) => (
          <div key={category.id} className="relative text-start">
            <div
              className={cn(
                'pointer-events-none absolute -inset-x-6 -top-12 -z-10 h-40 rounded-full blur-3xl',
                category.toned
                  ? 'bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.16),transparent)]'
                  : 'bg-[radial-gradient(closest-side,rgb(var(--tone2-rgb)/0.14),transparent)]',
              )}
              aria-hidden
            />
            <div className="flex items-center gap-3">
              <span
                className={cn(
                  'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[12px] font-medium',
                  category.toned
                    ? 'border-[rgb(var(--tone-rgb)/0.35)] bg-[rgb(var(--tone-rgb)/0.08)] text-[var(--tone-ink)]'
                    : 'border-[rgb(var(--tone2-rgb)/0.4)] bg-[rgb(var(--tone2-rgb)/0.1)] text-foreground',
                )}
              >
                <Puzzle className="size-3" aria-hidden />
                {t(category.title)}
              </span>
              <span
                className={cn(
                  'h-px flex-1',
                  category.toned
                    ? 'bg-[linear-gradient(90deg,rgb(var(--tone-rgb)/0.5),transparent)] rtl:bg-[linear-gradient(270deg,rgb(var(--tone-rgb)/0.5),transparent)]'
                    : 'bg-[linear-gradient(90deg,rgb(var(--tone2-rgb)/0.5),transparent)] rtl:bg-[linear-gradient(270deg,rgb(var(--tone2-rgb)/0.5),transparent)]',
                )}
                aria-hidden
              />
            </div>

            <div className="mt-6 [&>*+*]:border-t [&>*+*]:border-border">
              {category.extensions.map((extension, index) => (
                <ExtensionRow
                  key={extension.key}
                  extension={extension}
                  toned={category.toned}
                  index={categoryIndex * 2 + index}
                />
              ))}
            </div>
          </div>
        ))}
      </div>

      <p className="mt-10 text-center text-[13px] leading-6 text-muted-foreground">
        {t(
          'A sample of the catalog. Your database lists every extension it can install, including hstore, uuid-ossp, and many more.',
        )}
      </p>

      <div className="mt-8 flex justify-center border-t border-border pt-8">
        <ArtPanel
          className="w-fit max-w-full"
          innerClassName="px-3.5 py-3"
          delayMs={900}
          float
          floatDelayMs={500}
        >
          <p className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider text-muted-foreground">
            <Sparkles className="size-3 text-[var(--tone-ink)]" aria-hidden />
            {t('Install from a Server SDK')}
          </p>
          <pre
            dir="ltr"
            className="mt-2 overflow-x-auto whitespace-pre font-mono text-[11px] leading-5"
          >
            <code>
              <T tone="keyword">await</T> <T tone="identifier">postgresql</T>
              <T tone="punctuation">.</T>
              <T tone="function">createExtension</T>
              <T tone="punctuation">({'{'}</T>
              {'\n  '}
              <T tone="property">databaseId</T>
              <T tone="punctuation">,</T> <T tone="property">name</T>
              <T tone="punctuation">: </T>
              <T tone="string">&apos;vector&apos;</T>
              {'\n'}
              <T tone="punctuation">{'}'})</T>
            </code>
          </pre>
        </ArtPanel>
      </div>
    </div>
  )
}
