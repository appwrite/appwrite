import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  productRouteKindQueryOptions,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import {
  dedicatedDatabaseHomeLink,
  needsDedicatedProductTypeLookup,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { useQueries } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useMemo } from 'react'
import { AlertCircle, Cpu, Loader2 } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '../../shared/ResourceCard'
import {
  DedicatedDatabaseRegionUnavailableBadge,
  DedicatedDatabaseRegionUnavailableCard,
} from './DedicatedDatabaseRegionUnavailableCard'

type DedicatedDatabasesSectionProps = {
  projectId: string
  viewMode: 'list' | 'grid'
  regionSupported?: boolean
}

function formatEngineLabel(engine: string): string {
  switch (engine.toLowerCase()) {
    case 'postgres':
      return 'PostgreSQL'
    case 'mysql':
      return 'MySQL'
    case 'mariadb':
      return 'MariaDB'
    case 'mongodb':
      return 'MongoDB'
    default:
      return engine
  }
}

function dedicatedStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  switch (status.toLowerCase()) {
    case 'ready':
      return 'success'
    case 'provisioning':
    case 'restoring':
    case 'scaling':
      return 'info'
    case 'inactive':
    case 'paused':
      return 'warning'
    case 'failed':
    case 'deleted':
      return 'error'
    default:
      return 'info'
  }
}

function formatStatusLabel(status: string): string {
  if (!status) return 'Unknown'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function DedicatedDatabaseStatusBadge({ status }: { status: string }) {
  return (
    <Badge
      variant={dedicatedStatusVariant(status)}
      className="text-[11px] font-medium border px-2 py-0.5 capitalize"
    >
      {formatStatusLabel(status)}
    </Badge>
  )
}

function DedicatedDatabaseCard({
  db,
  projectId,
  productRouteKindByDedicatedId,
}: {
  db: Models.DedicatedDatabase
  projectId: string
  productRouteKindByDedicatedId: Map<string, DatabaseRouteKind>
}) {
  const link = dedicatedDatabaseHomeLink(
    projectId,
    db,
    productRouteKindByDedicatedId.get(db.$id),
  )
  const card = (
    <ResourceCard
      interactive={!!link}
      title={db.name}
      resourceId={db.$id}
      icon={Cpu}
      iconColor="bg-muted text-muted-foreground"
      status={dedicatedStatusVariant(db.status)}
      statusLabel={formatStatusLabel(db.status)}
      metadata={[
        {
          label: 'Engine',
          value: formatEngineLabel(db.engine),
        },
        {
          label: 'Tier',
          value: db.specification || 'Not set',
        },
        {
          label: 'Replicas',
          value: db.replicas > 0 ? String(db.replicas) : 'None',
        },
      ]}
    />
  )

  if (!link) return card

  return (
    <Link {...link} className="block">
      {card}
    </Link>
  )
}

export function DedicatedDatabasesSection({
  projectId,
  viewMode,
  regionSupported = true,
}: DedicatedDatabasesSectionProps) {
  if (!regionSupported) {
    return (
      <section className="mt-10">
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <h2 className="text-[15px] font-semibold text-foreground">
            Dedicated databases
          </h2>
          <DedicatedDatabaseRegionUnavailableBadge />
          <p className="w-full text-[13px] text-muted-foreground">
            Always-on dedicated databases for PostgreSQL, MySQL, and product-backed engines.
          </p>
        </div>
        <DedicatedDatabaseRegionUnavailableCard icon={Cpu} />
      </section>
    )
  }

  return (
    <DedicatedDatabasesSectionContent
      projectId={projectId}
      viewMode={viewMode}
    />
  )
}

function DedicatedDatabasesSectionContent({
  projectId,
  viewMode,
}: Omit<DedicatedDatabasesSectionProps, 'regionSupported'>) {
  const {
    databases,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useProjectDedicatedDatabases(projectId)

  const dedicatedDatabasesNeedingProductType = useMemo(
    () => databases.filter(needsDedicatedProductTypeLookup),
    [databases],
  )

  const productRouteKindLookups = useQueries({
    queries: dedicatedDatabasesNeedingProductType.map((db) =>
      productRouteKindQueryOptions(projectId, db.$id),
    ),
  })

  const productRouteKindByDedicatedId = useMemo(() => {
    const map = new Map<string, DatabaseRouteKind>()
    dedicatedDatabasesNeedingProductType.forEach((db, index) => {
      const kind = productRouteKindLookups[index]?.data
      if (kind) map.set(db.$id, kind)
    })
    return map
  }, [dedicatedDatabasesNeedingProductType, productRouteKindLookups])

  const errorMessage = error ? getErrorMessage(error) : null

  return (
    <section className="mt-10">
      <div className="mb-4">
        <h2 className="text-[15px] font-semibold text-foreground">
          Dedicated databases
        </h2>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Always-on dedicated databases for PostgreSQL, MySQL, and product-backed engines.
        </p>
      </div>

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-10 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-3 text-[13px] text-muted-foreground">
            Loading dedicated databases...
          </p>
        </div>
      ) : errorMessage && databases.length === 0 ? (
        <div className="rounded-lg border border-destructive/30 bg-card py-10 px-6 text-center">
          <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
          <h3 className="mt-4 text-[15px] font-semibold text-foreground">
            Failed to load dedicated databases
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">{errorMessage}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-6"
            onClick={() => void refetch()}
            disabled={isFetching}
          >
            Try again
          </Button>
        </div>
      ) : viewMode === 'list' ? (
        databases.length > 0 ? (
          <>
            {errorMessage ? (
              <Alert variant="destructive" className="mb-4">
                <AlertCircle className="h-4 w-4" />
                <AlertTitle>Couldn&apos;t refresh dedicated databases</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-[13px]">{errorMessage}</p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0 border-destructive/40 bg-background"
                    onClick={() => void refetch()}
                    disabled={isFetching}
                  >
                    Try again
                  </Button>
                </AlertDescription>
              </Alert>
            ) : null}
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Database
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Engine
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Tier
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                      Status
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      Replicas
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      Created
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {databases.map((db) => {
                    const link = dedicatedDatabaseHomeLink(
                      projectId,
                      db,
                      productRouteKindByDedicatedId.get(db.$id),
                    )
                    return (
                    <TableRow
                      key={db.$id}
                      className={cn(
                        'border-b border-border/50',
                        link && 'hover:bg-muted/30',
                      )}
                    >
                      <TableCell className="px-4 py-3">
                        {link ? (
                          <Link {...link} className="block min-w-0 group">
                            <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                              {db.name}
                            </p>
                            <div className="mt-0.5">
                              <CopyableId id={db.$id} size="xs" />
                            </div>
                          </Link>
                        ) : (
                          <div className="min-w-0">
                            <p className="truncate text-[13px] font-medium text-foreground">
                              {db.name}
                            </p>
                            <div className="mt-0.5">
                              <CopyableId id={db.$id} size="xs" />
                            </div>
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-[13px] text-foreground">
                        {formatEngineLabel(db.engine)}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                        {db.specification || 'Not set'}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center justify-center">
                          <DedicatedDatabaseStatusBadge status={db.status} />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] text-muted-foreground">
                        {db.replicas > 0 ? db.replicas : 'None'}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={new Date(db.$createdAt)}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
          </>
        ) : (
          <EmptyState
            icon={Cpu}
            title="No dedicated databases yet"
            description="Create a PostgreSQL or MySQL database to get started with dedicated compute."
            isEmpty
            variant="card"
          />
        )
      ) : (
        <>
          {errorMessage ? (
            <Alert variant="destructive" className="mb-4">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>Couldn&apos;t refresh dedicated databases</AlertTitle>
              <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[13px]">{errorMessage}</p>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0 border-destructive/40 bg-background"
                  onClick={() => void refetch()}
                  disabled={isFetching}
                >
                  Try again
                </Button>
              </AlertDescription>
            </Alert>
          ) : null}
          <div className={cn(RESOURCE_CARD_GRID_CLASSNAME)}>
            {databases.map((db) => (
              <DedicatedDatabaseCard
                key={db.$id}
                db={db}
                projectId={projectId}
                productRouteKindByDedicatedId={productRouteKindByDedicatedId}
              />
            ))}
            {databases.length === 0 ? (
              <div className="col-span-full">
                <EmptyState
                  icon={Cpu}
                  title="No dedicated databases yet"
                  description="Create a PostgreSQL or MySQL database to get started with dedicated compute."
                  isEmpty
                  variant="card"
                />
              </div>
            ) : null}
          </div>
        </>
      )}
    </section>
  )
}
