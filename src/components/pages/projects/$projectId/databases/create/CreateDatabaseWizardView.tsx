/**
 * Fullscreen create database wizard: single screen with progressive disclosure.
 * DB type → specifications (table) → name & create.
 */

import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Table as TableIcon, Braces, Layers } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '../_components/database-mascot-icons'
import { CreateDatabaseSummary } from '../_components/CreateDatabaseSummary'
import { CreateDatabaseDedicatedOptions } from '../_components/CreateDatabaseDedicatedOptions'
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
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  createNativeDatabase,
  createProjectDatabase,
  databaseSpecificationsQueryOptions,
  seedCreatedDatabaseCaches,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { DatabaseType, type Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import {
  DATABASE_HOME_TO,
  databaseRouteKindFromApiType,
} from '@/lib/database-routes'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  SERVERLESS_DATABASE_SPEC_ID,
  TABLE_DB_SPEC_OPTIONS as SPEC_OPTIONS,
  getDefaultEnabledSpecId,
  hasLockedDatabaseSpecifications,
  mapDedicatedDatabaseSpecifications,
} from '@/lib/database-specs'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import {
  getNewDatabaseNameForType,
  isAutoFilledNewDatabaseName,
} from '@/lib/default-new-database-name'
import { useAnalytics } from '@/hooks/use-analytics'
import {
  calculateDedicatedDatabaseMonthlyCost,
  DATABASE_COMPUTE_CREDITS_NOTE,
  getDedicatedDatabaseCreatePricing,
} from '@/lib/database-create-pricing'
import {
  getDedicatedDatabaseIdError,
  formatDedicatedDatabaseCreateError,
} from '@/lib/dedicated-database-id'
import { postgresDatabaseHome } from '@/lib/postgres-database-routes'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import { useT } from '@/lib/i18n/translate'

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

type DbTypeOptionMeta = DbTypeChoice & {
  comingSoonMessage?: string
}

const DB_TYPE_GROUPS: {
  title: string
  description: string
  options: DbTypeChoice[]
}[] = [
  {
    title: 'Appwrite databases',
    description:
      'Managed databases built into Appwrite for app data, documents, and AI workloads.',
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
          'Vector database for embeddings and similarity search. Ideal for semantic search and AI.',
        icon: 'layers',
      },
    ],
  },
  {
    title: 'Native databases',
    description:
      'Dedicated PostgreSQL and MySQL engines for teams that need direct SQL compatibility.',
    options: [
      {
        id: 'Postgres',
        label: 'PostgreSQL',
        description:
          'A dedicated PostgreSQL database for relational workloads, SQL tooling, and portable schemas.',
        icon: 'elephant',
      },
      {
        id: 'MySQL',
        label: 'MySQL',
        description:
          'A dedicated MySQL database for common relational workloads and existing MySQL applications.',
        icon: 'dolphin',
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

function isNativeDatabaseType(t: DatabaseTypeOption | null): t is 'Postgres' | 'MySQL' {
  return t === 'Postgres' || t === 'MySQL'
}

function nativeDatabaseEngine(t: 'Postgres' | 'MySQL'): 'postgres' | 'mysql' {
  return t === 'Postgres' ? 'postgres' : 'mysql'
}

export function CreateDatabaseWizardView() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pid = projectId as string
  const { features } = useConsoleProfile()
  const { track } = useAnalytics()
  const { project } = useProject(pid)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const supportsDedicatedDatabaseCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)

  const { data: specificationsData, isLoading: specificationsLoading } =
    useQuery(databaseSpecificationsQueryOptions(pid))
  const apiSpecOptions = useMemo(
    () => mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )
  const dedicatedPricing = useMemo(
    () =>
      getDedicatedDatabaseCreatePricing(
        organizationPlan,
        specificationsData?.pricing ?? null,
      ),
    [organizationPlan, specificationsData?.pricing],
  )

  const [dbType, setDbType] = useState<DatabaseTypeOption | null>(null)
  const [specId, setSpecId] = useState<string | null>(null)
  const [haReplicaCount, setHaReplicaCount] = useState(0)
  const [pitrEnabled, setPitrEnabled] = useState(false)
  const [name, setName] = useState('')
  const [databaseId, setDatabaseId] = useState<string | undefined>(undefined)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const isTablesDB = dbType === 'TablesDB'
  const isDocumentsDB = dbType === 'DocumentsDB'
  const isVectorsDB = dbType === 'VectorsDB'
  const isNativeDb = isNativeDatabaseType(dbType)
  const usesDedicatedTablesCompute =
    isTablesDB &&
    specId != null &&
    specId !== SERVERLESS_DATABASE_SPEC_ID
  const usesDedicatedCompute =
    isDocumentsDB || isVectorsDB || isNativeDb || usesDedicatedTablesCompute

  const regionUnavailableMessage =
    formatDedicatedDatabaseRegionUnavailableDescription(t)

  const dbTypeOptions = useMemo((): DbTypeOptionMeta[] => {
    return DB_TYPE_OPTIONS.map((opt) => {
      if (opt.id === 'Postgres') {
        if (!features.nativeDbsPostgres) {
          return { ...opt, comingSoon: true }
        }
        if (!supportsDedicatedDatabaseCompute) {
          return {
            ...opt,
            comingSoon: true,
            comingSoonMessage: regionUnavailableMessage,
          }
        }
        return { ...opt, comingSoon: false }
      }
      if (opt.id === 'MySQL') {
        if (!features.nativeDbsMySQL) {
          return { ...opt, comingSoon: true }
        }
        if (!supportsDedicatedDatabaseCompute) {
          return {
            ...opt,
            comingSoon: true,
            comingSoonMessage: regionUnavailableMessage,
          }
        }
        return { ...opt, comingSoon: false }
      }
      if (opt.id === 'DocumentsDB' || opt.id === 'VectorsDB') {
        if (!supportsDedicatedDatabaseCompute) {
          return {
            ...opt,
            comingSoon: true,
            comingSoonMessage: regionUnavailableMessage,
          }
        }
        return { ...opt, comingSoon: opt.comingSoon }
      }
      return { ...opt, comingSoon: opt.comingSoon }
    })
  }, [
    features.nativeDbsPostgres,
    features.nativeDbsMySQL,
    supportsDedicatedDatabaseCompute,
    regionUnavailableMessage,
  ])

  /** Show specs section when type uses dedicated compute (incl. TablesDB). */
  const showSpecsForType =
    supportsDedicatedDatabaseCompute &&
    (isDocumentsDB || isVectorsDB || isTablesDB || isNativeDb)

  const selectableSpecs = useMemo(() => {
    if (isNativeDb || isDocumentsDB || isVectorsDB) {
      return apiSpecOptions
    }
    if (isTablesDB) {
      const serverlessSpec = SPEC_OPTIONS.find(
        (s) => s.id === SERVERLESS_DATABASE_SPEC_ID,
      )
      return [
        ...(serverlessSpec ? [{ ...serverlessSpec, comingSoon: false }] : []),
        ...apiSpecOptions,
      ]
    }
    return apiSpecOptions
  }, [apiSpecOptions, isNativeDb, isTablesDB, isDocumentsDB, isVectorsDB])

  const selectedSpec = useMemo(
    () => (specId ? selectableSpecs.find((s) => s.id === specId) : null),
    [specId, selectableSpecs],
  )
  const selectedDbType = useMemo(
    () => (dbType ? dbTypeOptions.find((opt) => opt.id === dbType) : null),
    [dbType, dbTypeOptions],
  )

  const showDedicatedOptions = Boolean(
    usesDedicatedCompute &&
      selectedSpec &&
      !selectedSpec.comingSoon &&
      typeof selectedSpec.priceUsd === 'number',
  )

  const basePriceUsd = selectedSpec?.priceUsd ?? 0

  const monthlyCost = useMemo(() => {
    if (!showDedicatedOptions) return null
    return calculateDedicatedDatabaseMonthlyCost({
      basePriceUsd,
      replicaCount: haReplicaCount,
      pitrEnabled,
      pricing: dedicatedPricing,
    })
  }, [
    showDedicatedOptions,
    basePriceUsd,
    haReplicaCount,
    pitrEnabled,
    dedicatedPricing,
  ])

  useEffect(() => {
    track('Wizard Opened', {
      surface: 'create_database_wizard',
      resource: 'database',
    })
  }, [track])

  useEffect(() => {
    if (!dbType || !showSpecsForType || specificationsLoading) return
    if (dbType === 'TablesDB' && specId === SERVERLESS_DATABASE_SPEC_ID) return
    if (
      specId &&
      selectableSpecs.some((spec) => spec.id === specId && !spec.comingSoon)
    ) {
      return
    }
    const nextSpecId = getDefaultEnabledSpecId(selectableSpecs)
    if (nextSpecId && nextSpecId !== specId) {
      setSpecId(nextSpecId)
    }
  }, [
    dbType,
    showSpecsForType,
    specificationsLoading,
    selectableSpecs,
    specId,
  ])

  useEffect(() => {
    setHaReplicaCount(0)
    setPitrEnabled(false)
  }, [dbType])

  const handleDbTypeSelect = (option: DbTypeChoice) => {
    setDbType(option.id)
    if (isAutoFilledNewDatabaseName(name)) {
      setName(getNewDatabaseNameForType(option.id))
    }
    if (option.id === 'TablesDB') {
      setSpecId(SERVERLESS_DATABASE_SPEC_ID)
    } else if (option.id === 'DocumentsDB' || option.id === 'VectorsDB') {
      setSpecId(getDefaultEnabledSpecId(apiSpecOptions))
    } else {
      setSpecId(getDefaultEnabledSpecId(apiSpecOptions))
    }
    track('Wizard Option Selected', {
      surface: 'create_database_wizard',
      resource: 'database',
      step: 'database_type',
      option: option.id,
    })
  }

  const handleSpecSelect = (value: string | null) => {
    setSpecId(value)
    if (!value) return
    track('Wizard Option Selected', {
      surface: 'create_database_wizard',
      resource: 'database',
      step: 'specification',
      option: value,
      database_type: dbType ?? 'unknown',
    })
  }

  const createMutation = useMutation<
    Models.Database | Models.DedicatedDatabase,
    Error,
    { databaseId?: string; name: string }
  >({
    mutationFn: (data) => {
      if (!dbType) {
        throw new Error('Database type is required')
      }
      if (isNativeDatabaseType(dbType)) {
        if (!specId) {
          throw new Error('Database specification is required')
        }
        return createNativeDatabase(pid, {
          ...data,
          engine: nativeDatabaseEngine(dbType),
          specification: specId,
          region: project?.region,
          haReplicaCount,
          pitrEnabled,
        })
      }
      return createProjectDatabase(pid, data, wizardBackend(dbType), {
        specification: specId ?? undefined,
        region: project?.region,
        haReplicaCount,
        pitrEnabled,
      })
    },
    onSuccess: async (database) => {
      if (!isNativeDatabaseType(dbType) && database.$id && dbType) {
        seedCreatedDatabaseCaches(
          queryClient,
          pid,
          database.$id,
          wizardBackend(dbType),
          database as Models.Database,
        )
      }
      track('Resource Created', {
        surface: 'create_database_wizard',
        resource: 'database',
        database_type: dbType ?? 'unknown',
        spec: selectedSpec?.id ?? 'none',
        has_custom_id: Boolean(databaseId?.trim()),
      })
      toast.success(t('Database created'))
      if (isNativeDatabaseType(dbType)) {
        if (dbType === 'Postgres') {
          navigate({
            ...postgresDatabaseHome({
              projectId: pid,
              databaseId: database.$id,
              tableId: '-',
            }),
          })
          return
        }
        navigate({
          to: '/projects/$projectId/databases',
          params: { projectId: pid },
        })
        return
      }
      navigate({
        to: DATABASE_HOME_TO,
        params: {
          projectId: pid,
          dbKind: databaseRouteKindFromApiType(
            (database as { type?: DatabaseType }).type ??
              (dbType ? wizardBackend(dbType) : undefined),
          ),
          databaseId: database.$id,
        },
      })
      void Promise.all([
        queryClient.refetchQueries({
          queryKey: ['databases', 'project', pid],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['dedicated-databases', 'project', pid],
          type: 'all',
        }),
      ])
    },
    onError: (error) => {
      track('Resource Creation Failed', {
        surface: 'create_database_wizard',
        resource: 'database',
        database_type: dbType ?? 'unknown',
        spec: selectedSpec?.id ?? 'none',
        error_name: error instanceof Error ? error.name : 'unknown',
      })
      const fallback = t('Failed to create database')
      const message =
        isNativeDatabaseType(dbType) ||
        dbType === 'DocumentsDB' ||
        dbType === 'VectorsDB'
          ? formatDedicatedDatabaseCreateError(error, fallback)
          : getErrorMessage(error) || fallback
      toast.error(t(message))
    },
  })

  const handleCreate = () => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = t('Name is required')
    if (isNativeDatabaseType(dbType)) {
      const dedicatedIdError = databaseId?.trim()
        ? getDedicatedDatabaseIdError(databaseId)
        : null
      if (dedicatedIdError) newErrors.databaseId = t(dedicatedIdError)
    } else if (databaseId?.trim() && !validateDatabaseId(databaseId)) {
      newErrors.databaseId = t(
        'Database ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.',
      )
    }
    setErrors(newErrors)
    const invalidFields = Object.keys(newErrors)
    if (invalidFields.length > 0) {
      track('Form Validation Failed', {
        surface: 'create_database_wizard',
        resource: 'database',
        fields: invalidFields.join(','),
        error_count: invalidFields.length,
      })
      return
    }
    track('Form Submitted', {
      surface: 'create_database_wizard',
      resource: 'database',
      database_type: dbType ?? 'unknown',
      spec: selectedSpec?.id ?? 'none',
      has_custom_id: Boolean(databaseId?.trim()),
    })
    createMutation.mutate({
      databaseId: databaseId?.trim() || undefined,
      name: name.trim(),
    })
  }

  const isSpecSelectionReady =
    !showSpecsForType ||
    (!specificationsLoading &&
      selectedSpec != null &&
      !selectedSpec.comingSoon)

  const showNameForm = Boolean(dbType && !selectedDbType?.comingSoon)

  const canCreate = Boolean(
    showNameForm &&
    name.trim().length > 0 &&
    dbType &&
    !selectedDbType?.comingSoon &&
    isSpecSelectionReady,
  )
  const isCreatePending = createMutation.isPending
  const footer = (
    <div className="flex w-full justify-end">
      <Button
        type="button"
        disabled={!canCreate || isCreatePending}
        onClick={handleCreate}
        data-analytics-track="manual"
      >
        {t('Create database')}
      </Button>
    </div>
  )

  return (
    <WizardLayout
      title={t('Create database')}
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
          showDedicatedOptions={showDedicatedOptions}
          replicaCount={haReplicaCount}
          pitrEnabled={pitrEnabled}
          monthlyCost={monthlyCost}
          canCreate={canCreate}
        />
      }
    >
      <div className="space-y-10">
        {/* 1. Database type */}
        <section>
          <div className="mb-8">
            <h2 className="text-[15px] font-semibold text-foreground mb-1">
              {t('Choose database type')}
            </h2>
            <p className="text-[13px] text-muted-foreground">
              {t('Pick an Appwrite database or a native SQL engine.')} {/* pragma: allowlist secret */}
            </p>
          </div>
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {DB_TYPE_GROUPS.map((group, groupIndex) => (
              <div
                key={group.title}
                className={cn(
                  'space-y-3',
                  groupIndex > 0 &&
                    'border-t border-border pt-6 lg:border-s lg:border-t-0 lg:ps-6 lg:pt-0',
                )}
              >
                <div className="mb-6 space-y-1">
                  <h3 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t(group.title)}
                  </h3>
                  <p className="text-[12px] leading-5 text-muted-foreground">
                    {group.title === 'Native databases' &&
                    !supportsDedicatedDatabaseCompute
                      ? regionUnavailableMessage
                      : t(group.description)}
                  </p>
                </div>
                <div className="space-y-4">
                  {group.options.map((opt) => {
                    const optionMeta = dbTypeOptions.find((item) => item.id === opt.id) ?? opt
                    return (
                    <button
                      key={opt.id}
                      type="button"
                      disabled={optionMeta.comingSoon}
                      onClick={() => handleDbTypeSelect(opt)}
                      data-analytics-track="manual"
                      className={cn(
                        'flex w-full cursor-pointer items-start gap-4 rounded-xl border border-border bg-card/50 p-4 text-start transition-all hover:border-border/80 hover:bg-card/60 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-border disabled:hover:bg-card/50',
                        dbType === opt.id &&
                          !optionMeta.comingSoon &&
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
                              {t('Beta')}
                            </Badge>
                          )}
                          {optionMeta.comingSoon && (
                            <Badge
                              variant="inactive"
                              className="text-[10px] shrink-0"
                            >
                              {t('Coming soon')}
                            </Badge>
                          )}
                        </span>
                        <p className="text-[12px] leading-5 text-muted-foreground">
                          {optionMeta.comingSoonMessage
                            ? optionMeta.comingSoonMessage
                            : t(opt.description)}
                        </p>
                      </div>
                    </button>
                    )
                  })}
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* 2. Name & ID – revealed after type is selected */}
        {showNameForm && (
          <section className="pt-6 border-t border-border">
            <div className="mb-8">
              <h2 className="text-[15px] font-semibold text-foreground mb-1">
                {t('Name your database')}
              </h2>
              <p className="text-[13px] text-muted-foreground">
                {t('Choose a display name and optional custom ID.')}
              </p>
            </div>
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="db-name">
                  {t('Name')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="db-name"
                  type="text"
                  placeholder={t(getNewDatabaseNameForType(dbType))}
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
                <Label htmlFor="db-id">{t('Database ID')}</Label>
                <IdInput
                  id="db-id"
                  value={databaseId}
                  onChange={setDatabaseId}
                  maxLength={36}
                  placeholder={t('Leave blank to auto-generate')}
                  idFormat={
                    isNativeDatabaseType(dbType) ||
                    dbType === 'DocumentsDB' ||
                    dbType === 'VectorsDB'
                      ? 'dedicated'
                      : 'default'
                  }
                />
                {errors.databaseId && (
                  <p className="text-[12px] text-destructive">
                    {errors.databaseId}
                  </p>
                )}
              </div>
            </div>
          </section>
        )}

        {/* 3. Specifications (table) – revealed when type selected and that type has dedicated support */}
        {dbType && showSpecsForType && (
          <section className="pt-6">
            <h2 className="text-[15px] font-semibold text-foreground mb-1">
              {t('Specifications')}
            </h2>
            <p className="text-[13px] text-muted-foreground mb-4">
              {t('Select the compute and storage tier for your database.')}
            </p>
            <div className="rounded-lg border border-border bg-muted/30 px-4 py-3 mb-4">
              <p className="text-[13px] font-medium text-foreground">
                {t(DATABASE_COMPUTE_CREDITS_NOTE)}
              </p>
            </div>
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <RadioGroup
                value={specId ?? ''}
                onValueChange={(value) => handleSpecSelect(value || null)}
                className="w-full"
              >
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
                      <TableHead className="w-[48px] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider" />
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Tier')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        CPU
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Memory')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Connections')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[140px]">
                        {t('Price')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {specificationsLoading && selectableSpecs.length === 0 ? (
                      <TableRow>
                        <TableCell
                          colSpan={6}
                          className="px-4 py-8 text-center text-[13px] text-muted-foreground"
                        >
                          {t('Loading specifications…')}
                        </TableCell>
                      </TableRow>
                    ) : selectableSpecs.length === 0 ? (
                      <TableRow>
                        <TableCell
                          colSpan={6}
                          className="px-4 py-8 text-center text-[13px] text-muted-foreground"
                        >
                          {t('No specifications are available for your plan.')}
                        </TableCell>
                      </TableRow>
                    ) : (
                    selectableSpecs.map((spec) => {
                      const locked = !!spec.comingSoon
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
                          onClick={() => !locked && handleSpecSelect(spec.id)}
                          data-analytics-track="manual"
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
                              {t(spec.label)}
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
                          <TableCell className="px-4 py-3.5 text-end">
                            {locked ? (
                              <Badge
                                variant="inactive"
                                className="text-[10px] shrink-0"
                              >
                                {t('Upgrade')}
                              </Badge>
                            ) : (
                              <span className="inline-block text-end text-[13px] font-semibold tabular-nums tracking-tight text-foreground">
                                {spec.price}
                              </span>
                            )}
                          </TableCell>
                        </TableRow>
                      )
                    })
                    )}
                  </TableBody>
                </Table>
              </RadioGroup>
            </div>
            {hasLockedDatabaseSpecifications(selectableSpecs) && (
              <div className="mt-3">
                <SpecificationsUpgradeNote
                  orgId={project?.teamId}
                  showContactSales
                />
              </div>
            )}
          </section>
        )}

        {showDedicatedOptions && (
          <section className="pt-6 border-t border-border">
            <CreateDatabaseDedicatedOptions
              basePriceUsd={basePriceUsd}
              pricing={dedicatedPricing}
              replicaCount={haReplicaCount}
              onReplicaCountChange={setHaReplicaCount}
              pitrEnabled={pitrEnabled}
              onPitrEnabledChange={setPitrEnabled}
            />
          </section>
        )}
      </div>
    </WizardLayout>
  )
}
