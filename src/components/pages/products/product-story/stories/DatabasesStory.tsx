import { Braces, Layers, Search, Table as TableIcon, Zap } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { StoryAnimated } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StoryCodeBlock } from '@/components/pages/products/product-story/StoryCodeBlock'
import { StoryField, StoryOptionCard } from '@/components/pages/products/product-story/shared'

const SDK_CODE = [
  { text: "import { Client, Databases, Query } from 'appwrite';", tone: 'plain' as const },
  { text: 'const orders = await databases.listDocuments(', tone: 'keyword' as const },
  { text: "  'analytics', 'orders',", tone: 'string' as const },
  { text: '  [Query.equal("status", "paid")],', tone: 'plain' as const },
  { text: ');', tone: 'plain' as const },
]

const REALTIME_CODE = [
  { text: "client.subscribe(", tone: 'plain' as const },
  { text: "  'databases.analytics.collections.orders.documents',", tone: 'string' as const },
  { text: '  (response) => { /* live update */ }', tone: 'plain' as const },
  { text: ');', tone: 'plain' as const },
]

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'engines',
    title: 'Database engines',
    description:
      'TablesDB, DocumentsDB, VectorsDB, and managed Postgres or MySQL in one project.',
    icon: TableIcon,
    className: 'lg:col-span-6',
    tall: true,
    content: (
      <div className="grid gap-2 sm:grid-cols-2">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="TablesDB"
            description="SDK-native tables, rows, and permissions."
            icon={<TableIcon className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={80}>
          <StoryOptionCard
            title="DocumentsDB"
            description="Document collections with attributes and indexes."
            icon={<Braces className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={160}>
          <StoryOptionCard
            title="VectorsDB"
            description="Embedding collections for semantic search."
            icon={<Layers className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={240}>
          <StoryOptionCard
            title="Postgres"
            description="Full SQL, extensions, and pgvector."
            icon={<PostgresElephantIcon className="size-4" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={320}>
          <StoryOptionCard
            title="MySQL"
            description="Managed MySQL with console SQL tools."
            icon={<MySQLDolphinIcon className="size-4" aria-hidden />}
          />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'schema',
    title: 'Tables & columns',
    description: 'Define schemas, indexes, and relationships from the console table browser.',
    icon: Braces,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="orders"
            description="12 columns, 3 indexes, 2 relationships"
            icon={<TableIcon className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <div className="overflow-hidden rounded-lg border border-border">
            <div className="grid grid-cols-3 border-b border-border bg-muted/15 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              <span>Column</span>
              <span>Type</span>
              <span>Required</span>
            </div>
            {[
              ['$id', 'string', 'yes'],
              ['customer_id', 'string', 'yes'],
              ['total', 'integer', 'yes'],
              ['status', 'enum', 'no'],
            ].map(([name, type, required]) => (
              <div
                key={name}
                className="grid grid-cols-3 border-b border-border px-3 py-2 text-[11px] last:border-b-0"
              >
                <span className="font-mono text-foreground">{name}</span>
                <span className="text-muted-foreground">{type}</span>
                <span className="text-muted-foreground">{required}</span>
              </div>
            ))}
          </div>
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'sql',
    title: 'SQL editor',
    description: 'Run queries against Postgres and MySQL directly in the project console.',
    icon: Search,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <div className="rounded-lg border border-border bg-muted/10 p-3 font-mono text-[10px] leading-5 sm:text-[11px]">
            <p>
              <span className="text-blue-600 dark:text-blue-400">SELECT</span> customer_id,{' '}
              <span className="text-cyan-700 dark:text-cyan-400">SUM</span>(total)
            </p>
            <p>
              <span className="text-blue-600 dark:text-blue-400">FROM</span> orders
            </p>
            <p>
              <span className="text-blue-600 dark:text-blue-400">GROUP BY</span> customer_id;
            </p>
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <div className="rounded-lg border border-border bg-background p-2 text-[11px] text-muted-foreground">
            128 rows returned in 24ms
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={300}>
          <StoryField label="Backups" value="Enabled" active />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'sdk',
    title: 'SDK queries',
    description: 'Filter, sort, and paginate rows with a consistent query API across engines.',
    icon: Layers,
    className: 'lg:col-span-6',
    content: <StoryCodeBlock title="Client app" language="TypeScript" lines={SDK_CODE} lineDelayMs={35} />,
  },
  {
    id: 'realtime',
    title: 'Realtime & vectors',
    description:
      'Subscribe to document changes and run semantic search with VectorsDB or pgvector.',
    icon: Zap,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryCodeBlock lines={REALTIME_CODE} lineDelayMs={60} />
        <StoryAnimated delayMs={300}>
          <p className="flex items-center gap-2 rounded-md border border-border bg-muted/10 px-3 py-2 text-[11px] text-muted-foreground">
            <Search className="size-3.5 shrink-0" aria-hidden />
            Semantic search with VectorsDB and pgvector
          </p>
        </StoryAnimated>
        <StoryAnimated delayMs={400}>
          <p className="flex items-center gap-2 rounded-md border border-border bg-muted/10 px-3 py-2 text-[11px] text-muted-foreground">
            <Layers className="size-3.5 shrink-0" aria-hidden />
            CSV import and export for migrations
          </p>
        </StoryAnimated>
      </div>
    ),
  },
]

export function DatabasesStory() {
  return <ProductStoryGrid features={FEATURES} />
}
