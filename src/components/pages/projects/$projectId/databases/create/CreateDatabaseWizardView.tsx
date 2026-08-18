/**
 * Fullscreen create database wizard: single screen with progressive disclosure.
 * DB type → name → specifications → dedicated options → backup policies → create.
 */

import { useState, useMemo, useEffect, useRef } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Table as TableIcon, Braces, Layers } from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '../_components/database-mascot-icons'
import { CreateDatabaseSummary } from '../_components/CreateDatabaseSummary'
import { CreateDatabaseDedicatedOptions } from '../_components/CreateDatabaseDedicatedOptions'
import { ServerlessSpecPrice } from '../_components/ServerlessSpecPrice'
import {
  BACKUP_POLICY_PRESETS,
  CreateDatabaseBackupPolicies,
  backupPolicyNameForSchedule,
  isGenericBackupPolicyName,
  type BackupPolicyPresetId,
} from '../_components/CreateDatabaseBackupPolicies'
import {
  CreateDatabaseSetupProgress,
  type DatabaseSetupPhase,
  type DatabaseSetupProgressState,
} from './CreateDatabaseSetupProgress'
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import {
  getBackupPoliciesPlanLimit,
  getBackupPoliciesRemainingSlots,
  supportsAdvancedBackupPolicies,
} from '@/lib/databases/backup-policy-plan-limits'
import { ID, BackupServices, type Models } from '@appwrite.io/console' // pragma: allowlist secret
import { DatabaseType } from '@/lib/databases/database-type'
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
import { useMutation, useQuery, useQueries, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  createNativeDatabase,
  createProjectDatabase,
  databaseSpecificationsQueryOptions,
  dedicatedDatabaseSourceFromDatabaseType,
  dedicatedDatabaseSourceFromEngine,
  fetchBackupPolicies,
  fetchDedicatedBackupPolicies,
  refetchProjectDatabaseLists,
  seedCreatedDatabaseCaches,
  waitForCreatedDatabaseHaReady,
  waitForCreatedDatabaseLifecycleReady,
  waitForCreatedDatabasePitrReady,
  waitForCreatedDatabaseWorkspaceReady,
  useOrganizationPlan,
  useProject,
  type DedicatedDatabaseSource,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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
  hasEnabledDedicatedComputeOptions,
  hasLockedDatabaseSpecifications,
  isServerlessDatabaseSpecId,
  mapDedicatedDatabaseSpecifications,
} from '@/lib/database-specs'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import {
  getNewDatabaseNameForType,
  isAutoFilledNewDatabaseName,
} from '@/lib/default-new-database-name'
import { useAnalytics } from '@/hooks/use-analytics'
import {
  calculateDedicatedDatabaseMonthlyCost,
  formatDedicatedMonthlyPrice,
  getDedicatedDatabaseCreatePricing,
} from '@/lib/database-create-pricing'
import {
  getDedicatedDatabaseIdError,
  formatDedicatedDatabaseCreateError,
} from '@/lib/dedicated-database-id'
import { postgresDatabaseHome } from '@/lib/postgres-database-routes'
import { mysqlDatabaseHome } from '@/lib/mysql-database-routes'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import {
  getPlanDatabaseComputeCreditUsd,
  planSupportsDedicatedDatabases,
} from '@/lib/databases/dedicated-database-plan'
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
  comingSoon?: boolean
  comingSoonMessage?: string
  requiresUpgrade?: boolean
}

const DB_TYPE_GROUPS: {
  title: string
  description: string
  /** Used when MySQL is hidden from the native group. */
  descriptionPostgresOnly?: string
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
    descriptionPostgresOnly:
      'A dedicated PostgreSQL engine for teams that need direct SQL compatibility.',
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

function specificationsSourceForWizardType(
  dbType: DatabaseTypeOption | null,
): DedicatedDatabaseSource | null {
  if (!dbType) return null
  if (dbType === 'Postgres') {
    return dedicatedDatabaseSourceFromEngine('postgresql')
  }
  if (dbType === 'MySQL') {
    return dedicatedDatabaseSourceFromEngine('mysql')
  }
  return dedicatedDatabaseSourceFromDatabaseType(wizardBackend(dbType))
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
  const regionSupportsDedicatedCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const planSupportsDedicatedCompute =
    planSupportsDedicatedDatabases(organizationPlan)
  const databaseComputeCreditUsd =
    getPlanDatabaseComputeCreditUsd(organizationPlan)
  const computeCreditsUsd =
    databaseComputeCreditUsd > 0 ? databaseComputeCreditUsd : null

  const [dbType, setDbType] = useState<DatabaseTypeOption | null>(null)
  const specificationsSource = useMemo(
    () => specificationsSourceForWizardType(dbType),
    [dbType],
  )
  const { data: specificationsData, isLoading: specificationsLoading } =
    useQuery({
      ...databaseSpecificationsQueryOptions(
        pid,
        specificationsSource ??
          dedicatedDatabaseSourceFromDatabaseType(DatabaseType.Tablesdb),
      ),
      enabled: !!pid && !!specificationsSource,
    })
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
  const dedicatedTypeSpecSources = useMemo(() => {
    const items: { id: DatabaseTypeOption; source: DedicatedDatabaseSource }[] =
      []
    if (features.dedicatedDbsDocumentsDB) {
      items.push({
        id: 'DocumentsDB',
        source: dedicatedDatabaseSourceFromDatabaseType(
          DatabaseType.Documentsdb,
        ),
      })
    }
    if (features.dedicatedDbsVectorsDB) {
      items.push({
        id: 'VectorsDB',
        source: dedicatedDatabaseSourceFromDatabaseType(DatabaseType.Vectorsdb),
      })
    }
    if (features.nativeDbsPostgres) {
      items.push({
        id: 'Postgres',
        source: dedicatedDatabaseSourceFromEngine('postgresql'),
      })
    }
    if (features.nativeDbsMySQL) {
      items.push({
        id: 'MySQL',
        source: dedicatedDatabaseSourceFromEngine('mysql'),
      })
    }
    return items
  }, [
    features.dedicatedDbsDocumentsDB,
    features.dedicatedDbsVectorsDB,
    features.nativeDbsPostgres,
    features.nativeDbsMySQL,
  ])
  const dedicatedTypeSpecQueries = useQueries({
    queries: dedicatedTypeSpecSources.map(({ source }) => ({
      ...databaseSpecificationsQueryOptions(pid, source),
      enabled:
        !!pid &&
        regionSupportsDedicatedCompute &&
        databaseSpecificationsQueryOptions(pid, source).enabled,
    })),
  })
  const dedicatedTypesWithoutCompute = useMemo(() => {
    const unavailable = new Set<DatabaseTypeOption>()
    dedicatedTypeSpecSources.forEach((item, index) => {
      const query = dedicatedTypeSpecQueries[index]
      if (!query?.isSuccess) return
      const specs = mapDedicatedDatabaseSpecifications(
        query.data?.specifications,
      )
      if (!hasEnabledDedicatedComputeOptions(specs)) {
        unavailable.add(item.id)
      }
    })
    return unavailable
  }, [dedicatedTypeSpecQueries, dedicatedTypeSpecSources])
  const [specId, setSpecId] = useState<string | null>(null)
  const [haReplicaCount, setHaReplicaCount] = useState(0)
  const [pitrEnabled, setPitrEnabled] = useState(false)
  const [selectedBackupPresets, setSelectedBackupPresets] = useState<
    BackupPolicyPresetId[]
  >([])
  const [backupPresetsInitialized, setBackupPresetsInitialized] =
    useState(false)
  const [name, setName] = useState('')
  const [databaseId, setDatabaseId] = useState<string | undefined>(undefined)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [setupProgress, setSetupProgress] =
    useState<DatabaseSetupProgressState | null>(null)
  const [isCreating, setIsCreating] = useState(false)
  // Sync guard: setState alone can miss a double-click before re-render.
  const createInFlightRef = useRef(false)

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
    const resolveDedicatedType = (
      opt: DbTypeChoice,
      featureEnabled: boolean,
    ): DbTypeOptionMeta => {
      if (!featureEnabled) {
        return { ...opt, comingSoon: true }
      }
      if (!regionSupportsDedicatedCompute) {
        return {
          ...opt,
          comingSoon: true,
          comingSoonMessage: regionUnavailableMessage,
        }
      }
      if (
        planSupportsDedicatedCompute === false ||
        dedicatedTypesWithoutCompute.has(opt.id)
      ) {
        return { ...opt, comingSoon: false, requiresUpgrade: true }
      }
      return { ...opt, comingSoon: false }
    }

    return DB_TYPE_OPTIONS.map((opt) => {
      if (opt.id === 'Postgres') {
        return resolveDedicatedType(opt, features.nativeDbsPostgres)
      }
      if (opt.id === 'MySQL') {
        return resolveDedicatedType(opt, features.nativeDbsMySQL)
      }
      if (opt.id === 'DocumentsDB') {
        return resolveDedicatedType(opt, features.dedicatedDbsDocumentsDB)
      }
      if (opt.id === 'VectorsDB') {
        return resolveDedicatedType(opt, features.dedicatedDbsVectorsDB)
      }
      return { ...opt, comingSoon: opt.comingSoon }
    })
  }, [
    features.nativeDbsPostgres,
    features.nativeDbsMySQL,
    features.dedicatedDbsDocumentsDB,
    features.dedicatedDbsVectorsDB,
    regionSupportsDedicatedCompute,
    planSupportsDedicatedCompute,
    dedicatedTypesWithoutCompute,
    regionUnavailableMessage,
  ])

  const visibleDbTypeGroups = useMemo(() => {
    return DB_TYPE_GROUPS.map((group) => {
      const options = group.options.filter((opt) => {
        // MySQL is fully gated behind the flag (no "coming soon" teaser).
        if (opt.id === 'MySQL') return features.nativeDbsMySQL
        return true
      })
      if (options.length === 0) return null
      const description =
        group.title === 'Native databases' &&
        !features.nativeDbsMySQL &&
        group.descriptionPostgresOnly
          ? group.descriptionPostgresOnly
          : group.description
      return { ...group, description, options }
    }).filter(
      (group): group is NonNullable<typeof group> => group != null,
    )
  }, [features.nativeDbsMySQL])

  /** Show specs when the region can list dedicated tiers. Locked rows stay visible. */
  const showSpecsForType =
    regionSupportsDedicatedCompute &&
    (isDocumentsDB || isVectorsDB || isTablesDB || isNativeDb)

  const selectableSpecs = useMemo(() => {
    const dedicatedSpecs =
      planSupportsDedicatedCompute === true
        ? apiSpecOptions
        : apiSpecOptions.map((spec) => ({ ...spec, comingSoon: true }))
    const visibleDedicatedSpecs =
      dedicatedSpecs.length > 0
        ? dedicatedSpecs
        : SPEC_OPTIONS.filter(
            (spec) => spec.id !== SERVERLESS_DATABASE_SPEC_ID,
          ).map((spec) => ({ ...spec, comingSoon: true }))

    if (isNativeDb || isDocumentsDB || isVectorsDB) {
      return visibleDedicatedSpecs
    }
    if (isTablesDB) {
      const serverlessSpec = SPEC_OPTIONS.find(
        (s) => s.id === SERVERLESS_DATABASE_SPEC_ID,
      )
      return [
        ...(serverlessSpec ? [{ ...serverlessSpec, comingSoon: false }] : []),
        ...visibleDedicatedSpecs,
      ]
    }
    return visibleDedicatedSpecs
  }, [
    apiSpecOptions,
    isNativeDb,
    isTablesDB,
    isDocumentsDB,
    isVectorsDB,
    planSupportsDedicatedCompute,
  ])

  const selectedSpec = useMemo(
    () => (specId ? selectableSpecs.find((s) => s.id === specId) : null),
    [specId, selectableSpecs],
  )
  const selectedDbType = useMemo(
    () => (dbType ? dbTypeOptions.find((opt) => opt.id === dbType) : null),
    [dbType, dbTypeOptions],
  )

  const isDbTypeUnavailable = Boolean(
    selectedDbType?.comingSoon || selectedDbType?.requiresUpgrade,
  )

  useEffect(() => {
    if (isDbTypeUnavailable) {
      setDbType(null)
    }
  }, [isDbTypeUnavailable])

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

  useEffect(() => {
    if (!features.databaseBackups) {
      setSelectedBackupPresets([])
      setBackupPresetsInitialized(false)
      return
    }
    if (backupPresetsInitialized) return
    // Wait until organization plan has loaded so we can default correctly.
    if (organizationPlan == null) return

    setSelectedBackupPresets(
      organizationPlan.backupsEnabled ? ['daily'] : [],
    )
    setBackupPresetsInitialized(true)
  }, [
    backupPresetsInitialized,
    features.databaseBackups,
    organizationPlan,
  ])

  // Profile ∩ plan: undefined while plan is loading (when profile supports backups).
  const planBackupsEnabled = features.databaseBackups
    ? organizationPlan?.backupsEnabled
    : undefined
  // Same convention as databases/functions/buckets/firewall: 0 = unlimited.
  const backupPoliciesLimit = features.databaseBackups
    ? getBackupPoliciesPlanLimit(organizationPlan)
    : 0
  const showBackupPoliciesSection = Boolean(features.databaseBackups)
  const backupPoliciesSummaryLabel =
    selectedBackupPresets.length > 0
      ? selectedBackupPresets
          .map((id) => t(BACKUP_POLICY_PRESETS[id].label))
          .join(', ')
      : null

  // Keep selection within plan caps (e.g. Pro = 1 → daily only).
  useEffect(() => {
    if (!features.databaseBackups) return
    if (!supportsAdvancedBackupPolicies(backupPoliciesLimit)) {
      setSelectedBackupPresets((prev) => {
        const next = prev.filter((id) => id !== 'hourly')
        if (next.length === prev.length) return prev
        return next.length > 0 ? next : prev.includes('daily') ? ['daily'] : next
      })
    }
    if (backupPoliciesLimit <= 0) return
    setSelectedBackupPresets((prev) => {
      if (prev.length <= backupPoliciesLimit) return prev
      return prev.slice(0, backupPoliciesLimit)
    })
  }, [backupPoliciesLimit, features.databaseBackups])

  const createBackupPoliciesForDatabase = async (
    database: Models.Database | Models.DedicatedDatabase,
  ) => {
    if (
      !features.databaseBackups ||
      planBackupsEnabled !== true ||
      selectedBackupPresets.length === 0
    ) {
      return
    }

    const projectSdk = sdk.forProject(pid)
    const isNative = isNativeDatabaseType(dbType)
    const engineService = isNative
      ? dedicatedEngineService(projectSdk, nativeDatabaseEngine(dbType))
      : null

    const existing = isNative
      ? await fetchDedicatedBackupPolicies(
          pid,
          database.$id,
          nativeDatabaseEngine(dbType),
        ).catch(() => ({
          policies: [] as Models.BackupPolicy[],
          total: 0,
        }))
      : await fetchBackupPolicies(pid, database.$id).catch(() => ({
          policies: [] as Models.BackupPolicy[],
          total: 0,
        }))

    // Platform may auto-create a policy named "Default". Rename it to the
    // matching preset (e.g. Daily backup) before creating additional ones.
    for (const policy of existing.policies) {
      if (!isGenericBackupPolicyName(policy.name)) continue
      const meaningfulName =
        backupPolicyNameForSchedule(policy.schedule) ??
        (selectedBackupPresets.includes('daily')
          ? BACKUP_POLICY_PRESETS.daily.name
          : selectedBackupPresets.includes('hourly')
            ? BACKUP_POLICY_PRESETS.hourly.name
            : null)
      if (!meaningfulName) continue
      try {
        if (engineService) {
          await engineService.updateBackupPolicy({
            databaseId: database.$id,
            policyId: policy.$id,
            name: meaningfulName,
          })
        } else {
          await projectSdk.backups.updatePolicy({
            policyId: policy.$id,
            name: meaningfulName,
          })
        }
        policy.name = meaningfulName
      } catch {
        // Non-blocking: still try to create any missing presets below.
      }
    }

    const existingSchedules = new Set(
      existing.policies
        .map((policy) => policy.schedule?.trim())
        .filter((schedule): schedule is string => Boolean(schedule)),
    )

    const remainingSlots =
      getBackupPoliciesRemainingSlots(
        existing.policies.length,
        backupPoliciesLimit,
      ) ?? selectedBackupPresets.length

    if (remainingSlots === 0) {
      return
    }

    const presetsToCreate = selectedBackupPresets
      .filter((presetId) => {
        const schedule = BACKUP_POLICY_PRESETS[presetId].schedule
        return !existingSchedules.has(schedule)
      })
      .slice(0, remainingSlots)

    if (presetsToCreate.length === 0) {
      return
    }

    // Create one-by-one so a plan limit on the second policy does not race
    // the first create (Promise.all used to surface a false failure toast).
    for (const presetId of presetsToCreate) {
      const preset = BACKUP_POLICY_PRESETS[presetId]
      const policy = {
        policyId: ID.unique(),
        name: preset.name,
        schedule: preset.schedule,
        retention: preset.retention,
        enabled: true as const,
      }

      try {
        if (engineService) {
          await engineService.createBackupPolicy({
            databaseId: database.$id,
            policyId: policy.policyId,
            name: policy.name,
            schedule: policy.schedule,
            retention: policy.retention,
            enabled: policy.enabled,
          })
        } else {
          await projectSdk.backups.createPolicy({
            policyId: policy.policyId,
            services: [BackupServices.Databases],
            retention: policy.retention,
            schedule: policy.schedule,
            name: policy.name,
            resourceId: database.$id,
            enabled: policy.enabled,
          })
        }
      } catch (error) {
        const message = getErrorMessage(error).toLowerCase()
        const isLimitError =
          message.includes('limit') && message.includes('polic')
        // First policy may have succeeded; ignore limit noise for extras.
        if (isLimitError) {
          return
        }
        throw error
      }
    }
  }

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
      })
    },
  })

  const setSetupPhase = (phase: DatabaseSetupPhase) => {
    setSetupProgress((prev) => (prev ? { ...prev, phase } : prev))
  }

  const finishDatabaseCreation = async (
    database: Models.Database | Models.DedicatedDatabase,
  ) => {
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
    await refetchProjectDatabaseLists(queryClient, pid)
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
      if (dbType === 'MySQL') {
        navigate({
          ...mysqlDatabaseHome({
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
  }

  const handleCreateDatabase = async () => {
    if (createInFlightRef.current) return

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

    const trimmedName = name.trim()
    const showProvisioningStep = usesDedicatedCompute
    const showHaStep = haReplicaCount > 0
    // Product APIs have no PITR mutation; never enable/wait via engine for product IDs.
    const showPitrStep = pitrEnabled && isNativeDatabaseType(dbType)
    const shouldCreateBackupPolicies =
      features.databaseBackups &&
      planBackupsEnabled === true &&
      selectedBackupPresets.length > 0

    track('Form Submitted', {
      surface: 'create_database_wizard',
      resource: 'database',
      database_type: dbType ?? 'unknown',
      spec: selectedSpec?.id ?? 'none',
      has_custom_id: Boolean(databaseId?.trim()),
    })

    createInFlightRef.current = true
    setSetupProgress({
      phase: 'creating',
      databaseName: trimmedName,
      showProvisioningStep,
      showHaStep,
      showPitrStep,
      showWorkspaceStep: true,
      showBackupsStep: shouldCreateBackupPolicies,
    })
    setIsCreating(true)

    try {
      const database = await createMutation.mutateAsync({
        databaseId: databaseId?.trim() || undefined,
        name: trimmedName,
      })

      const workspaceKind = isNativeDatabaseType(dbType)
        ? { type: 'native' as const, engine: nativeDatabaseEngine(dbType) }
        : {
            type: 'product' as const,
            backend: wizardBackend(dbType!),
          }

      if (showProvisioningStep) {
        setSetupPhase('provisioning')
        const lifecycleReady = await waitForCreatedDatabaseLifecycleReady(
          pid,
          database.$id,
          workspaceKind,
        )
        if (!lifecycleReady) {
          throw new Error(
            'Database provisioning failed or timed out. Try again in a moment.',
          )
        }
      }

      if (showHaStep) {
        setSetupPhase('configuring-ha')
        const haReady = await waitForCreatedDatabaseHaReady(
          pid,
          database.$id,
          workspaceKind,
          haReplicaCount,
        )
        if (!haReady) {
          throw new Error(
            'High availability setup failed or timed out. Try again in a moment.',
          )
        }
      }

      if (showPitrStep) {
        setSetupPhase('enabling-pitr')
        const pitrReady = await waitForCreatedDatabasePitrReady(
          pid,
          database.$id,
          workspaceKind,
        )
        if (!pitrReady) {
          throw new Error(
            'Point-in-time recovery setup failed or timed out. Try again in a moment.',
          )
        }
      }

      setSetupPhase('preparing-workspace')
      const workspaceReady = await waitForCreatedDatabaseWorkspaceReady(
        pid,
        database.$id,
        workspaceKind,
      )
      if (!workspaceReady) {
        throw new Error(
          'Database workspace is not ready yet. Try again in a moment.',
        )
      }

      if (shouldCreateBackupPolicies) {
        setSetupPhase('enabling-backups')
        try {
          await createBackupPoliciesForDatabase(database)
        } catch (policyError) {
          toast.error(
            getErrorMessage(policyError) ||
              t('Database created, but failed to create backup policies'),
          )
        }
      }

      setSetupPhase('complete')
      await finishDatabaseCreation(database)
    } catch (error) {
      setSetupProgress(null)
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
    } finally {
      createInFlightRef.current = false
      setIsCreating(false)
    }
  }

  const isSpecSelectionReady =
    !showSpecsForType ||
    (!specificationsLoading &&
      selectedSpec != null &&
      !selectedSpec.comingSoon)

  const showNameForm = Boolean(dbType && !selectedDbType?.comingSoon && !selectedDbType?.requiresUpgrade)

  const canCreate = Boolean(
    showNameForm &&
    name.trim().length > 0 &&
    dbType &&
    !selectedDbType?.comingSoon &&
    !selectedDbType?.requiresUpgrade &&
    isSpecSelectionReady,
  )
  const isCreatePending = isCreating || createMutation.isPending
  const footer = (
    <div className="flex w-full justify-end">
      <Button
        type="button"
        disabled={!canCreate || isCreatePending}
        onClick={() => void handleCreateDatabase()}
        data-analytics-track="manual"
      >
        {t('Create database')}
      </Button>
    </div>
  )

  if (setupProgress) {
    return (
      <WizardLayout
        title={t('Create database')}
        fullscreen
        useSidebar={false}
        skipInitialFieldFocus
        fallbackPath={`/projects/${pid}/databases`}
      >
        <CreateDatabaseSetupProgress progress={setupProgress} />
      </WizardLayout>
    )
  }

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
          showBackupPolicies={showBackupPoliciesSection && showNameForm}
          backupPoliciesLabel={backupPoliciesSummaryLabel}
          backupsEnabled={planBackupsEnabled}
          computeCreditsUsd={computeCreditsUsd}
          organizationPlan={organizationPlan}
          canCreate={canCreate}
        />
      }
    >
      <div className="space-y-10">
        {/* 1. Database type */}
        <section>
          <div className="mb-8">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t('Choose database type')}
              <span className="ms-2 text-[13px] font-normal text-muted-foreground">
                {t('Pick an Appwrite database or a native SQL engine.')} {/* pragma: allowlist secret */}
              </span>
            </h2>
          </div>
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {visibleDbTypeGroups.map((group, groupIndex) => (
              <div
                key={group.title}
                className={cn(
                  'space-y-3',
                  groupIndex > 0 &&
                    'border-t border-border pt-6 lg:border-s lg:border-t-0 lg:ps-6 lg:pt-0',
                )}
              >
                <div className="mb-6 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <h3 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t(group.title)}
                  </h3>
                  <p className="text-[12px] text-muted-foreground">
                    {group.title === 'Native databases' &&
                    !regionSupportsDedicatedCompute
                      ? regionUnavailableMessage
                      : t(group.description)}
                  </p>
                </div>
                <div className="space-y-4">
                  {group.options.map((opt) => {
                    const optionMeta: DbTypeOptionMeta =
                      dbTypeOptions.find((item) => item.id === opt.id) ?? opt
                    const requiresUpgrade = optionMeta.requiresUpgrade === true
                    const isUnavailable =
                      Boolean(optionMeta.comingSoon) || requiresUpgrade
                    const cardClassName = cn(
                      'flex w-full items-start gap-4 rounded-xl border border-border bg-card/50 p-4 text-start transition-all',
                      isUnavailable
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer hover:border-border/80 hover:bg-card/60',
                      dbType === opt.id &&
                        !isUnavailable &&
                        'border-primary ring-1 ring-primary/20 hover:border-primary',
                    )
                    const cardContent = (
                      <>
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
                          {optionMeta.comingSoon ? (
                            <Badge
                              variant="inactive"
                              className="text-[10px] shrink-0"
                            >
                              {t('Coming soon')}
                            </Badge>
                          ) : null}
                          {requiresUpgrade ? (
                            <Badge
                              variant="inactive"
                              className="text-[10px] shrink-0"
                            >
                              {t('Upgrade')}
                            </Badge>
                          ) : null}
                        </span>
                        {requiresUpgrade ? (
                          <p className="text-[12px] leading-5 text-muted-foreground">
                            {t('Not available on your current plan.')}{' '}
                            <UpgradePlanLink orgId={project?.teamId} />{' '}
                            {t('to unlock this database type.')}
                          </p>
                        ) : (
                          <p className="text-[12px] leading-5 text-muted-foreground">
                            {optionMeta.comingSoonMessage
                              ? optionMeta.comingSoonMessage
                              : t(opt.description)}
                          </p>
                        )}
                      </div>
                      </>
                    )
                    if (isUnavailable) {
                      return (
                        <div key={opt.id} className={cardClassName}>
                          {cardContent}
                        </div>
                      )
                    }
                    return (
                    <button
                      key={opt.id}
                      type="button"
                      onClick={() => handleDbTypeSelect(opt)}
                      data-analytics-track="manual"
                      className={cardClassName}
                    >
                      {cardContent}
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
              <h2 className="text-[15px] font-semibold text-foreground">
                {t('Name your database')}
                <span className="ms-2 text-[13px] font-normal text-muted-foreground">
                  {t('Choose a display name and optional custom ID.')}
                </span>
              </h2>
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
            <h2 className="mb-4 text-[15px] font-semibold text-foreground">
              {t('Specifications')}
              <span className="ms-2 text-[13px] font-normal text-muted-foreground">
                {t('Select the compute and storage tier for your database.')}
              </span>
            </h2>
            {computeCreditsUsd != null ? (
              <div className="mb-4 flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3">
                <span className="text-[13px] text-muted-foreground">
                  {t('Compute credits')}
                </span>
                <span className="text-[13px] font-semibold tabular-nums text-foreground">
                  {formatDedicatedMonthlyPrice(computeCreditsUsd)}{' '}
                  {t('included')}
                </span>
              </div>
            ) : null}
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
                          {t('Not available on your current plan.')}{' '}
                          <UpgradePlanLink orgId={project?.teamId} />{' '}
                          {t('to unlock this database type.')}
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
                            ) : isServerlessDatabaseSpecId(spec.id) ? (
                              <ServerlessSpecPrice plan={organizationPlan} />
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

        {showNameForm && showBackupPoliciesSection && (
          <section className="pt-6 border-t border-border">
            <CreateDatabaseBackupPolicies
              planBackupsEnabled={planBackupsEnabled}
              backupPoliciesLimit={backupPoliciesLimit}
              selectedPresets={selectedBackupPresets}
              onSelectedPresetsChange={setSelectedBackupPresets}
              orgId={project?.teamId}
            />
          </section>
        )}
      </div>
    </WizardLayout>
  )
}
