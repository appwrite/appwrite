import { Braces, Layers, Table as TableIcon, type LucideIcon } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type EngineIcon = LucideIcon | typeof PostgresElephantIcon

type DatabaseEngine = {
  id: string
  name: string
  description: string
  icon: EngineIcon
}

type DatabaseEngineGroup = {
  id: string
  title: string
  description: string
  engines: DatabaseEngine[]
}

const ENGINE_GROUPS: DatabaseEngineGroup[] = [
  {
    id: 'appwrite',
    title: 'Appwrite DBs',
    description:
      'Managed databases built into Appwrite for app data, documents, and AI workloads.',
    engines: [
      {
        id: 'tablesdb',
        name: 'TablesDB',
        description:
          'Relational-style tables, columns, and indexes for structured data and complex queries.',
        icon: TableIcon,
      },
      {
        id: 'documentsdb',
        name: 'DocumentsDB',
        description:
          'Flexible JSON documents with filters and full-text search for evolving schemas.',
        icon: Braces,
      },
      {
        id: 'vectorsdb',
        name: 'VectorsDB',
        description:
          'Embeddings and similarity search for semantic retrieval and AI features.',
        icon: Layers,
      },
    ],
  },
  {
    id: 'native',
    title: 'Native DBs',
    description:
      'Dedicated PostgreSQL and MySQL engines for teams that need direct SQL compatibility.',
    engines: [
      {
        id: 'postgresql',
        name: 'PostgreSQL',
        description:
          'Full SQL, extensions, and portable schemas for relational workloads and existing tooling.',
        icon: PostgresElephantIcon,
      },
      {
        id: 'mysql',
        name: 'MySQL',
        description:
          'Familiar MySQL compatibility for common relational apps and migrations.',
        icon: MySQLDolphinIcon,
      },
    ],
  },
]

function EngineIconBadge({ icon: Icon }: { icon: EngineIcon }) {
  return (
    <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
      <Icon className="size-3.5 text-muted-foreground" aria-hidden />
    </span>
  )
}

function EngineCard({ engine }: { engine: DatabaseEngine }) {
  const t = useT()
  return (
    <div className="rounded-xl border border-border bg-background/80 p-4">
      <div className="flex items-start gap-3">
        <EngineIconBadge icon={engine.icon} />
        <div className="min-w-0">
          <h4 className="text-[14px] font-semibold text-foreground">{engine.name}</h4>
          <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
            {t(engine.description)}
          </p>
        </div>
      </div>
    </div>
  )
}

function EngineGroup({ group }: { group: DatabaseEngineGroup }) {
  const t = useT()
  return (
    <div>
      <div className="mb-4 max-w-2xl">
        <h3 className="text-[15px] font-semibold text-foreground">{t(group.title)}</h3>
        <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
          {t(group.description)}
        </p>
      </div>
      <div
        className={cn(
          'grid gap-3',
          group.engines.length === 3
            ? 'sm:grid-cols-2 lg:grid-cols-3'
            : 'sm:grid-cols-2',
        )}
      >
        {group.engines.map((engine) => (
          <EngineCard key={engine.id} engine={engine} />
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
    <div className={cn('space-y-8', className)}>
      {ENGINE_GROUPS.map((group) => (
        <EngineGroup key={group.id} group={group} />
      ))}
    </div>
  )
}
