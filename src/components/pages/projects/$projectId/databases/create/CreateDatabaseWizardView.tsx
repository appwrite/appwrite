/**
 * Fullscreen create database wizard: single screen with progressive disclosure.
 * DB type → specifications (table) → name & create.
 */

import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Table as TableIcon, Braces, Layers } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '../_components/database-mascot-icons'
import { CreateDatabaseSummary } from '../_components/CreateDatabaseSummary'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createProjectDatabase } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { DatabaseType } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { TABLE_DB_SPEC_OPTIONS as SPEC_OPTIONS } from '@/lib/database-specs'
import { DEFAULT_NEW_DATABASE_NAME } from '@/lib/default-new-database-name'

export type DatabaseTypeOption =
  | 'TablesDB'
  | 'DocumentsDB'
  | 'VectorsDB'
  | 'Postgres'
  | 'MySQL'

type DbTypeChoice = {
  id: DatabaseTypeOption
  label: string
  description: string
  icon: 'table' | 'braces' | 'layers' | 'elephant' | 'dolphin'
  comingSoon?: boolean
}

const DB_TYPE_GROUPS: {
  title: string
  description: string
  options: DbTypeChoice[]
}[] = [
  {
    title: 'Appwrite databases',
    description:
      'Managed Appwrite-native databases for app data, documents, and AI workloads.',
    options: [
      {
        id: 'TablesDB',
        label: 'TablesDB',
        description:
          'Relational-style database with tables, columns, and indexes. Ideal for structured data and complex queries.',
        icon: 'table',
      },
      {
        id: 'DocumentsDB',
        label: 'DocumentsDB',
        description:
          'Document-based storage with flexible schemas. Store JSON documents and query with filters and full-text search.',
        icon: 'braces',
      },
      {
        id: 'VectorsDB',
        label: 'VectorsDB',
        description:
          'Vector database for embeddings and similarity search. Power AI features like semantic search and recommendations.',
        icon: 'layers',
      },
    ],
  },
  {
    title: 'Raw databases',
    description:
      'Dedicated SQL engines for teams that need direct Postgres or MySQL compatibility.',
    options: [
      {
        id: 'Postgres',
        label: 'Postgres',
        description:
          'A dedicated PostgreSQL database for relational workloads, SQL tooling, and portable schemas.',
        icon: 'elephant',
        comingSoon: true,
      },
      {
        id: 'MySQL',
        label: 'MySQL',
        description:
          'A dedicated MySQL database for common relational workloads and existing MySQL applications.',
        icon: 'dolphin',
        comingSoon: true,
      },
    ],
  },
]

const DB_TYPE_OPTIONS = DB_TYPE_GROUPS.flatMap((group) => group.options)

function validateDatabaseId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

function wizardBackend(t: DatabaseTypeOption): DatabaseType {
  if (t === 'DocumentsDB') return DatabaseType.Documentsdb
  if (t === 'VectorsDB') return DatabaseType.Vectorsdb
  return DatabaseType.Tablesdb
}

export function CreateDatabaseWizardView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pid = projectId as string
  const { features } = useConsoleProfile()

  const [dbType, setDbType] = useState<DatabaseTypeOption | null>(null)
  const [specId, setSpecId] = useState<string | null>(null)
  const [name, setName] = useState(DEFAULT_NEW_DATABASE_NAME)
  const [databaseId, setDatabaseId] = useState<string | undefined>(undefined)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const isTablesDB = dbType === 'TablesDB'

  /** Show specs section only when the selected DB type has dedicated support enabled. */
  const showSpecsForType =
    (dbType === 'TablesDB' && features.dedicatedDbsTablesDB) ||
    (dbType === 'DocumentsDB' && features.dedicatedDbsDocumentsDB) ||
    (dbType === 'VectorsDB' && features.dedicatedDbsVectorsDB)

  const selectableSpecs = useMemo(() => {
    if (isTablesDB) {
      return SPEC_OPTIONS.map((s) =>
        s.id === 'shared'
          ? { ...s, comingSoon: false }
          : { ...s, comingSoon: true },
      )
    }
    // DocumentsDB / VectorsDB: only dedicated tiers, no Shared DB
    return SPEC_OPTIONS.filter((s) => s.id !== 'shared').map((s) => ({
      ...s,
      comingSoon: false,
    }))
  }, [isTablesDB])

  const selectedSpec = useMemo(
    () => (specId ? selectableSpecs.find((s) => s.id === specId) : null),
    [specId, selectableSpecs],
  )
  const selectedDbType = useMemo(
    () => (dbType ? DB_TYPE_OPTIONS.find((opt) => opt.id === dbType) : null),
    [dbType],
  )

  const createMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) => {
      if (!dbType) {
        throw new Error('Database type is required')
      }
      return createProjectDatabase(pid, data, wizardBackend(dbType))
    },
    onSuccess: async (database) => {
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', pid],
        type: 'all',
      })
      toast.success('Database created')
      navigate({
        to: '/projects/$projectId/databases/$databaseId',
        params: { projectId: pid, databaseId: database.$id },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error) || 'Failed to create database')
    },
  })

  const handleCreate = () => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = 'Name is required'
    if (databaseId?.trim() && !validateDatabaseId(databaseId)) {
      newErrors.databaseId =
        'Database ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.'
    }
    setErrors(newErrors)
    if (Object.keys(newErrors).length > 0) return
    createMutation.mutate({
      databaseId: databaseId?.trim() || undefined,
      name: name.trim(),
    })
  }

  const isCreatePending = createMutation.isPending
  const showNameForm = Boolean(
    dbType &&
    !selectedDbType?.comingSoon &&
    (!showSpecsForType ||
      (selectedSpec != null && (!isTablesDB || selectedSpec.id === 'shared'))),
  )

  const canCreate = Boolean(
    showNameForm &&
    name.trim().length > 0 &&
    dbType &&
    !selectedDbType?.comingSoon,
  )
  const footer = (
    <div className="flex w-full justify-end">
      <Button
        type="button"
        disabled={!canCreate || isCreatePending}
        onClick={handleCreate}
      >
        Create database
      </Button>
    </div>
  )

  return (
    <WizardLayout
      title="Create database"
      fullscreen
      maxWidth="max-w-[1400px]"
      fallbackPath={`/projects/${pid}/databases`}
      footer={footer}
      footerAlign="right"
      sidebar={
        <CreateDatabaseSummary
          name={name}
          databaseId={databaseId}
          dbType={dbType}
          selectedDbType={selectedDbType ?? null}
          showSpecs={Boolean(dbType && showSpecsForType)}
          selectedSpec={selectedSpec ?? null}
          canCreate={canCreate}
        />
      }
    >
      <div className="space-y-10">
        {/* 1. Name & ID */}
        <section>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="db-name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="db-name"
                type="text"
                placeholder="My database"
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) setErrors((prev) => ({ ...prev, name: '' }))
                }}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="db-id">Database ID</Label>
              <IdInput
                id="db-id"
                value={databaseId}
                onChange={setDatabaseId}
                maxLength={36}
                placeholder="Leave blank to auto-generate"
              />
              {errors.databaseId && (
                <p className="text-[12px] text-destructive">
                  {errors.databaseId}
                </p>
              )}
            </div>
          </div>
        </section>

        {/* 2. Database type */}
        <section>
          <div className="mb-8">
            <h2 className="text-[15px] font-semibold text-foreground mb-1">
              Choose database type
            </h2>
            <p className="text-[13px] text-muted-foreground">
              Pick an Appwrite-native database or a raw database engine.
            </p>
          </div>
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {DB_TYPE_GROUPS.map((group, groupIndex) => (
              <div
                key={group.title}
                className={cn(
                  'space-y-3',
                  groupIndex > 0 &&
                    'border-t border-border pt-6 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0',
                )}
              >
                <div className="mb-6 space-y-1">
                  <h3 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {group.title}
                  </h3>
                  <p className="text-[12px] leading-5 text-muted-foreground">
                    {group.description}
                  </p>
                </div>
                <div className="space-y-4">
                  {group.options.map((opt) => (
                    <button
                      key={opt.id}
                      type="button"
                      disabled={opt.comingSoon}
                      onClick={() => {
                        setDbType(opt.id)
                        setSpecId(opt.id === 'TablesDB' ? 'shared' : null)
                      }}
                      className={cn(
                        'flex w-full cursor-pointer items-start gap-4 rounded-xl border border-border bg-card/50 p-4 text-left transition-all hover:border-border/80 hover:bg-card/60 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-border disabled:hover:bg-card/50',
                        dbType === opt.id &&
                          !opt.comingSoon &&
                          'border-primary ring-1 ring-primary/20 hover:border-primary',
                      )}
                    >
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        {opt.icon === 'table' && (
                          <TableIcon className="h-5 w-5" />
                        )}
                        {opt.icon === 'braces' && (
                          <Braces className="h-5 w-5" />
                        )}
                        {opt.icon === 'layers' && (
                          <Layers className="h-5 w-5" />
                        )}
                        {opt.icon === 'elephant' && (
                          <PostgresElephantIcon className="h-5 w-5" />
                        )}
                        {opt.icon === 'dolphin' && (
                          <MySQLDolphinIcon className="h-5 w-5" />
                        )}
                      </div>
                      <div className="min-w-0 flex-1 space-y-2">
                        <span className="flex flex-wrap items-center gap-2">
                          <span className="text-[14px] font-medium text-foreground">
                            {opt.label}
                          </span>
                          {(opt.id === 'DocumentsDB' ||
                            opt.id === 'VectorsDB') && (
                            <Badge
                              variant="info"
                              className="text-[10px] shrink-0"
                            >
                              Beta
                            </Badge>
                          )}
                          {opt.comingSoon && (
                            <Badge
                              variant="inactive"
                              className="text-[10px] shrink-0"
                            >
                              Coming soon
                            </Badge>
                          )}
                        </span>
                        <p className="text-[12px] leading-5 text-muted-foreground">
                          {opt.description}
                        </p>
                      </div>
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* 3. Specifications (table) – revealed when type selected and that type has dedicated support */}
        {dbType && showSpecsForType && (
          <section className="pt-6">
            <h2 className="text-[15px] font-semibold text-foreground mb-1">
              Specifications
            </h2>
            <p className="text-[13px] text-muted-foreground mb-4">
              Select the compute and storage tier for your database.
            </p>
            <div className="rounded-lg border border-border bg-muted/30 px-4 py-3 mb-4">
              <p className="text-[13px] font-medium text-foreground">
                Each organization includes{' '}
                <span className="font-semibold">$10 of compute credits</span>{' '}
                for database usage every month.
              </p>
            </div>
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <RadioGroup
                value={specId ?? ''}
                onValueChange={(value) => setSpecId(value || null)}
                className="w-full"
              >
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
                      <TableHead className="w-[48px] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider" />
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Tier
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        CPU
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Memory
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Connections
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[140px]">
                        Price
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {selectableSpecs.map((spec) => {
                      const locked = isTablesDB
                        ? spec.id !== 'shared'
                        : !!spec.comingSoon
                      const isSelected = selectedSpec?.id === spec.id
                      return (
                        <TableRow
                          key={spec.id}
                          className={cn(
                            'border-b border-border last:border-b-0 transition-colors',
                            locked && 'opacity-60',
                            !locked && 'cursor-pointer',
                            !locked && !isSelected && 'hover:bg-muted/40',
                            isSelected &&
                              !locked &&
                              'bg-primary/5 hover:bg-primary/5',
                          )}
                          onClick={() => !locked && setSpecId(spec.id)}
                        >
                          <TableCell className="w-[48px] px-4 py-3.5">
                            <RadioGroupItem
                              value={spec.id}
                              disabled={locked}
                              className="cursor-pointer"
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3.5">
                            <span className="text-[13px] font-medium text-foreground">
                              {spec.label}
                            </span>
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-[13px] text-muted-foreground">
                            {spec.cpu}
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-[13px] text-muted-foreground">
                            {spec.memory}
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-[13px] tabular-nums text-muted-foreground">
                            {spec.connections}
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-right">
                            {locked ? (
                              <Badge
                                variant="inactive"
                                className="text-[10px] shrink-0"
                              >
                                Coming soon
                              </Badge>
                            ) : (
                              <span className="inline-block text-right text-[13px] font-semibold tabular-nums tracking-tight text-foreground">
                                {spec.price}
                              </span>
                            )}
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </RadioGroup>
            </div>
          </section>
        )}
      </div>
    </WizardLayout>
  )
}
