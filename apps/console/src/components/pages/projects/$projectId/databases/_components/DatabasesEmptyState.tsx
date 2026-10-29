import type { ComponentType } from 'react'
import { Link } from '@tanstack/react-router'
import {
  ArrowUpRight,
  Braces,
  Database,
  KeyRound,
  Layers,
  Link2,
  Lock,
  Search,
  Table2,
  Zap,
} from 'lucide-react'
import { MySQLDolphinIcon, PostgresElephantIcon } from './database-mascot-icons'
import { DatabaseTypeBetaBadge } from './DatabaseTypeBetaBadge'
import {
  ProductEmptyStateConnector,
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateTile,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isCloudDedicatedDatabasesEnabled } from '@/lib/database-routes'
import { planSupportsDedicatedDatabases } from '@/lib/databases/dedicated-database-plan'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import { useOrganizationPlan, useProject } from '@/lib/react-query/hooks'
import { isBetaDatabaseType } from '@/lib/databases/database-type-display'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type IconComponent = ComponentType<{ className?: string }>

type DatabaseTypeEntry = {
  id: string
  search: string
  label: string
  description: string
  icon: IconComponent
  dedicated?: boolean
}

type DatabaseTypeAccess = 'available' | 'upgrade' | 'region'

const DATABASE_TYPE_GROUPS: Array<{
  title: string
  description: string
  types: DatabaseTypeEntry[]
}> = [
  {
    title: 'Appwrite databases',
    description:
      'Fully managed and built into Appwrite. Read and write from your app with the SDKs.',
    types: [
      {
        id: 'TablesDB',
        search: 'tablesdb',
        label: 'TablesDB',
        description:
          'Rows and columns with a defined schema. A good fit for most apps: users, orders, posts.',
        icon: Table2,
      },
      {
        id: 'DocumentsDB',
        search: 'documentsdb',
        label: 'DocumentsDB',
        description:
          'JSON documents that can each have a different shape. Good for content and fast-changing data.',
        icon: Braces,
        dedicated: true,
      },
      {
        id: 'VectorsDB',
        search: 'vectorsdb',
        label: 'VectorsDB',
        description:
          'Stores embeddings and finds similar items. Good for AI search and recommendations.',
        icon: Layers,
        dedicated: true,
      },
    ],
  },
  {
    title: 'Native databases',
    description:
      'Your own PostgreSQL or MySQL server, run by Appwrite. Connect with any SQL client or ORM.',
    types: [
      {
        id: 'Postgres',
        search: 'postgres',
        label: 'PostgreSQL',
        description:
          'A dedicated PostgreSQL server with full SQL. Good if you already use Postgres tools.',
        icon: PostgresElephantIcon,
        dedicated: true,
      },
      {
        id: 'MySQL',
        search: 'mysql',
        label: 'MySQL',
        description:
          'A dedicated MySQL server with full SQL. Good for moving an existing MySQL app.',
        icon: MySQLDolphinIcon,
        dedicated: true,
      },
    ],
  },
]

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Database,
    title: 'Create a database',
    description: 'Start with TablesDB, a managed database built into Appwrite.',
  },
  {
    icon: Table2,
    title: 'Define your schema',
    description:
      'Add tables, columns, and indexes, then set permissions for each table.',
  },
  {
    icon: Zap,
    title: 'Query from your app',
    description:
      'Read and write rows with the Appwrite SDKs and subscribe to changes in realtime.',
  },
]

const SCHEMA_TABLES: Array<{
  name: string
  columns: Array<{
    name: string
    type: string
    key?: boolean
    relation?: boolean
  }>
}> = [
  {
    name: 'users',
    columns: [
      { name: '$id', type: 'string', key: true },
      { name: 'name', type: 'string' },
      { name: 'email', type: 'email' },
    ],
  },
  {
    name: 'posts',
    columns: [
      { name: '$id', type: 'string', key: true },
      { name: 'title', type: 'string' },
      { name: 'author', type: 'relation', relation: true },
    ],
  },
]

/** Decorative schema with a relation feeding a query result. */
function SchemaVisual() {
  return (
    <ProductEmptyStateVisual>
      {SCHEMA_TABLES.map((table, index) => (
        <div key={table.name} className="flex items-center gap-1.5">
          {index > 0 ? (
            <ProductEmptyStateConnector variant="link" accent />
          ) : null}
          <ProductEmptyStateTile icon={Table2} label={table.name}>
            <div className="space-y-1.5">
              {table.columns.map((column) => (
                <div
                  key={column.name}
                  className="flex items-center justify-between gap-2 font-mono text-[10px]"
                >
                  <span className="flex items-center gap-1 text-foreground/80">
                    {column.key ? (
                      <KeyRound className="h-2.5 w-2.5 text-muted-foreground" />
                    ) : column.relation ? (
                      <Link2 className="h-2.5 w-2.5 text-[var(--brand-cta)]" />
                    ) : (
                      <span className="h-2.5 w-2.5" />
                    )}
                    {column.name}
                  </span>
                  <span className="text-muted-foreground">{column.type}</span>
                </div>
              ))}
            </div>
          </ProductEmptyStateTile>
        </div>
      ))}
      <ProductEmptyStateConnector variant="link" />
      <ProductEmptyStateTile icon={Search} label="query">
        <div className="space-y-1.5">
          {['w-full', 'w-4/5', 'w-3/5'].map((width) => (
            <div key={width} className="flex items-center gap-1.5">
              <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500/70" />
              <span className="h-1.5 flex-1 rounded-full bg-muted">
                <span
                  className={cn(
                    'block h-full rounded-full bg-muted-foreground/40',
                    width,
                  )}
                />
              </span>
            </div>
          ))}
        </div>
        <div className="mt-2 flex items-center justify-between font-mono text-[9px] text-muted-foreground">
          <span className="rounded border border-border px-1 leading-4">
            200
          </span>
          <span>12 ms</span>
        </div>
      </ProductEmptyStateTile>
    </ProductEmptyStateVisual>
  )
}

function DatabaseTypeCard({
  projectId,
  type,
  access,
  disabled,
}: {
  projectId: string
  type: DatabaseTypeEntry
  access: DatabaseTypeAccess
  disabled: boolean
}) {
  const t = useT()
  const Icon = type.icon
  const content = (
    <>
      <div className="flex items-center gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/60 text-muted-foreground transition-colors group-hover:text-foreground">
          <Icon className="h-[18px] w-[18px]" />
        </span>
        <span className="flex min-w-0 flex-1 items-center gap-2">
          <span className="truncate text-[14px] font-medium text-foreground">
            {type.label}
          </span>
          {isBetaDatabaseType(type.id) ? <DatabaseTypeBetaBadge /> : null}
        </span>
        {access === 'upgrade' ? (
          <Lock
            aria-label={t('Available on paid plans')}
            className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60"
          />
        ) : disabled || access === 'region' ? null : (
          <ArrowUpRight className="h-4 w-4 shrink-0 text-muted-foreground/40 transition-[color,transform] group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-foreground rtl:-scale-x-100" />
        )}
      </div>
      <p className="mt-2.5 text-[12px] leading-5 text-muted-foreground">
        {t(type.description)}
      </p>
    </>
  )

  const cardClassName =
    'group flex flex-col rounded-xl border border-border bg-card p-4 text-start shadow-xs'

  if (access === 'upgrade') {
    return <div className={cn(cardClassName, 'bg-card/60')}>{content}</div>
  }

  if (disabled || access === 'region') {
    return <div className={cn(cardClassName, 'opacity-60')}>{content}</div>
  }

  return (
    <Link
      to="/projects/$projectId/databases/create"
      params={{ projectId }}
      search={{ type: type.search }}
      className={cn(
        cardClassName,
        'transition-colors hover:border-foreground/20 hover:bg-muted/30 focus-visible:border-foreground/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
      )}
    >
      {content}
    </Link>
  )
}

export function DatabasesEmptyState({
  projectId,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showTypes = isCloudDedicatedDatabasesEnabled()
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const dedicatedAccess: DatabaseTypeAccess = !project
    ? 'available'
    : !projectSupportsDedicatedDatabaseCompute(project.region)
      ? 'region'
      : planSupportsDedicatedDatabases(plan) === false
        ? 'upgrade'
        : 'available'
  const docsUrl = getDocsPageUrl('/docs/products/databases', features.marketing)

  return (
    <div className="mx-auto w-full max-w-4xl py-10 sm:py-14">
      <ProductEmptyStateHero
        visual={<SchemaVisual />}
        icon={Database}
        title={t('Create your first database')}
        description={
          showTypes
            ? t(
                'Store, query, and sync your app data with Appwrite. Not sure which type to pick? Start with TablesDB.',
              )
            : t(
                'Store, query, and sync your app data with tables, columns, and indexes built into Appwrite.',
              )
        }
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create database')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />

      {showTypes ? (
        <div className="mt-12 space-y-8 sm:mt-14">
          {DATABASE_TYPE_GROUPS.map((group) => (
            <section key={group.title}>
              <div className="mb-3 flex flex-col gap-0.5 px-0.5 text-start">
                <h3 className="text-[13px] font-medium text-foreground">
                  {t(group.title)}
                </h3>
                <p className="text-[12px] text-muted-foreground">
                  {t(group.description)}
                </p>
              </div>
              <div
                className={cn(
                  'grid gap-3',
                  group.types.length === 3
                    ? 'sm:grid-cols-3'
                    : 'sm:grid-cols-2',
                )}
              >
                {group.types.map((type) => (
                  <DatabaseTypeCard
                    key={type.id}
                    projectId={projectId}
                    type={type}
                    access={type.dedicated ? dedicatedAccess : 'available'}
                    disabled={createDisabled}
                  />
                ))}
              </div>
            </section>
          ))}
          {dedicatedAccess === 'upgrade' ? (
            <p className="-mt-4 flex flex-wrap items-center gap-x-1.5 gap-y-1 px-0.5 text-start text-[12px] leading-5 text-muted-foreground">
              <Lock className="h-3 w-3 shrink-0 text-muted-foreground/60" />
              <span>
                {t('Available on paid plans, with monthly database credits.')}
              </span>
              <UpgradePlanLink orgId={project?.teamId}>
                Compare plans
              </UpgradePlanLink>
            </p>
          ) : dedicatedAccess === 'region' ? (
            <p className="-mt-4 px-0.5 text-start text-[12px] leading-5 text-muted-foreground">
              {formatDedicatedDatabaseRegionUnavailableDescription(t)}
            </p>
          ) : null}
        </div>
      ) : (
        <ProductEmptyStateSteps steps={STEPS} />
      )}
    </div>
  )
}
