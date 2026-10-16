import { Braces, Layers, Table as TableIcon, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { ArtToken, floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { isBetaDatabaseType } from '@/lib/databases/database-type-display'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type EngineIcon = LucideIcon | typeof PostgresElephantIcon

type DatabaseEngine = {
  id: string
  name: string
  description: string
  icon: EngineIcon
  /** Mono snippet hinting at the data shape the engine works with. */
  sample: ReactNode
}

type DatabaseEngineGroup = {
  id: string
  title: string
  description: string
  /** Appwrite engines take the product tone; Native engines use the secondary tone with neutral brand marks. */
  toned: boolean
  engines: DatabaseEngine[]
}

const ENGINE_GROUPS: DatabaseEngineGroup[] = [
  {
    id: 'appwrite',
    title: 'Appwrite DBs',
    description:
      'Managed databases built into Appwrite for app data, documents, and AI workloads.',
    toned: true,
    engines: [
      {
        id: 'tablesdb',
        name: 'TablesDB',
        description:
          'Relational-style tables, columns, and indexes for structured data and complex queries.',
        icon: TableIcon,
        sample: (
          <>
            <ArtToken tone="class">Query</ArtToken>.<ArtToken tone="function">equal</ArtToken>(
            <ArtToken tone="string">'plan'</ArtToken>, <ArtToken tone="string">'pro'</ArtToken>)
          </>
        ),
      },
      {
        id: 'documentsdb',
        name: 'DocumentsDB',
        description:
          'Flexible JSON documents with filters and full-text search for evolving schemas.',
        icon: Braces,
        sample: (
          <>
            {'{ '}
            <ArtToken tone="property">"tags"</ArtToken>: [<ArtToken tone="string">"new"</ArtToken>]{' }'}
          </>
        ),
      },
      {
        id: 'vectorsdb',
        name: 'VectorsDB',
        description:
          'Embeddings and similarity search for semantic retrieval and AI features.',
        icon: Layers,
        sample: (
          <>
            [<ArtToken tone="number">0.12</ArtToken>, <ArtToken tone="number">-0.48</ArtToken>,{' '}
            <ArtToken tone="number">0.91</ArtToken>, …]
          </>
        ),
      },
    ],
  },
  {
    id: 'native',
    title: 'Native DBs',
    description:
      'Dedicated managed PostgreSQL and MySQL engines you connect to with standard SQL clients.',
    toned: false,
    engines: [
      {
        id: 'postgresql',
        name: 'PostgreSQL',
        description:
          'Managed PostgreSQL hosting with full SQL, pgvector, and portable schemas for Prisma, Drizzle, and existing tooling.',
        icon: PostgresElephantIcon,
        sample: (
          <>
            <ArtToken tone="sqlKeyword">SELECT</ArtToken> * <ArtToken tone="sqlKeyword">FROM</ArtToken> orders;
          </>
        ),
      },
      {
        id: 'mysql',
        name: 'MySQL',
        description:
          'Familiar MySQL compatibility for common relational apps and migrations.',
        icon: MySQLDolphinIcon,
        sample: (
          <>
            <ArtToken tone="sqlKeyword">SHOW TABLES</ArtToken>;
          </>
        ),
      },
    ],
  },
]

function EngineItem({
  engine,
  toned,
  index,
}: {
  engine: DatabaseEngine
  toned: boolean
  index: number
}) {
  const t = useT()
  const Icon = engine.icon
  return (
    <div
      className="product-hero-rise flex h-full flex-col py-8 text-start sm:px-6 sm:py-2 sm:first:ps-0 sm:last:pe-0 lg:px-8"
      style={riseStyle(150 + index * 110)}
    >
      <span className="relative flex size-14 shrink-0">
        <span
          className={cn(
            'absolute -inset-3 rounded-full blur-xl',
            toned ? 'bg-[rgb(var(--tone-rgb)/0.22)]' : 'bg-[rgb(var(--tone2-rgb)/0.2)]',
          )}
          aria-hidden
        />
        <span
          className={cn(
            'product-hero-float relative flex size-14 items-center justify-center rounded-2xl border bg-background shadow-[0_18px_44px_-20px_rgb(0_0_0/0.45)] dark:bg-card',
            toned
              ? 'border-[rgb(var(--tone-rgb)/0.4)] text-[var(--tone-ink)]'
              : 'border-[rgb(var(--tone2-rgb)/0.35)] text-foreground',
          )}
          style={floatStyle(index * 420)}
        >
          <Icon className="size-6" aria-hidden />
        </span>
      </span>

      <h4 className="mt-6 flex flex-wrap items-center gap-2 font-aeonik-pro text-[19px] tracking-tight text-foreground">
        {engine.name}
        {isBetaDatabaseType(engine.id) ? (
          <Badge variant="info" className="text-[10px] shrink-0 font-sans">
            {t('Beta')}
          </Badge>
        ) : null}
      </h4>
      <p className="mt-2 flex-1 text-[13.5px] leading-6 text-muted-foreground">{t(engine.description)}</p>

      <div
        dir="ltr"
        className="mt-6 self-start truncate rounded-lg border border-border/80 bg-muted/30 px-3 py-1.5 font-mono text-[11.5px] text-foreground/80 dark:bg-white/[0.03]"
      >
        {engine.sample}
      </div>
    </div>
  )
}

function EngineGroup({ group, startIndex }: { group: DatabaseEngineGroup; startIndex: number }) {
  const t = useT()
  return (
    <div className="relative text-start">
      <div
        className={cn(
          'pointer-events-none absolute -inset-x-8 -top-16 -z-10 h-56 rounded-full blur-3xl',
          group.toned
            ? 'bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.14),transparent)]'
            : 'bg-[radial-gradient(closest-side,rgb(var(--tone2-rgb)/0.14),transparent)]',
        )}
        aria-hidden
      />

      <div className="flex items-center gap-3">
        <span
          className={cn(
            'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[12px] font-medium',
            group.toned
              ? 'border-[rgb(var(--tone-rgb)/0.35)] bg-[rgb(var(--tone-rgb)/0.08)] text-[var(--tone-ink)]'
              : 'border-[rgb(var(--tone2-rgb)/0.4)] bg-[rgb(var(--tone2-rgb)/0.1)] text-foreground',
          )}
        >
          <span
            className={cn(
              'size-1.5 rounded-full',
              group.toned ? 'bg-[var(--tone-ink)]' : 'bg-[rgb(var(--tone2-rgb))]',
            )}
            aria-hidden
          />
          {t(group.title)}
        </span>
        <span
          className={cn(
            'h-px flex-1',
            group.toned
              ? 'bg-[linear-gradient(90deg,rgb(var(--tone-rgb)/0.5),transparent)] rtl:bg-[linear-gradient(270deg,rgb(var(--tone-rgb)/0.5),transparent)]'
              : 'bg-[linear-gradient(90deg,rgb(var(--tone2-rgb)/0.6),transparent)] rtl:bg-[linear-gradient(270deg,rgb(var(--tone2-rgb)/0.6),transparent)]',
          )}
          aria-hidden
        />
      </div>
      <p className="mt-4 max-w-md text-[14px] leading-6 text-muted-foreground">{t(group.description)}</p>

      <div
        className={cn(
          'mt-10 grid [&>*+*]:border-t [&>*+*]:border-border sm:[&>*+*]:border-s sm:[&>*+*]:border-t-0',
          group.engines.length === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
        )}
      >
        {group.engines.map((engine, index) => (
          <EngineItem key={engine.id} engine={engine} toned={group.toned} index={startIndex + index} />
        ))}
      </div>
    </div>
  )
}

type DatabasesEnginesCatalogProps = {
  className?: string
}

export function DatabasesEnginesCatalog({ className }: DatabasesEnginesCatalogProps) {
  return (
    <div
      className={cn(
        'grid gap-20 pt-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:gap-16 xl:gap-24',
        className,
      )}
    >
      {ENGINE_GROUPS.map((group, groupIndex) => (
        <EngineGroup
          key={group.id}
          group={group}
          startIndex={ENGINE_GROUPS.slice(0, groupIndex).reduce((sum, item) => sum + item.engines.length, 0)}
        />
      ))}
    </div>
  )
}
