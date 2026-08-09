/**
 * Fullscreen import data wizard: provider → credentials → get report → resource selection → create migration.
 */

import { useState, useMemo, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { ArrowRightLeft, Database, Info, Zap } from 'lucide-react'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import {
  AppwriteMigrationResource,
  SupabaseMigrationResource,
  FirebaseMigrationResource,
  NHostMigrationResource,
  OnDuplicate,
  type Models,
} from '@appwrite.io/console'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useProject,
  APPWRITE_RESOURCES,
  SUPABASE_NHOST_RESOURCES,
  NHOST_RESOURCES,
  FIREBASE_RESOURCES,
  fetchAppwriteReport,
  fetchSupabaseReport,
  fetchFirebaseReport,
  fetchNHostReport,
  useCreateAppwriteMigration,
  useCreateSupabaseMigration,
  useCreateFirebaseMigration,
  useCreateNHostMigration,
} from '@/lib/react-query/hooks'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'

export type ImportProvider =
  | 'AppwriteSelfHosted'
  | 'AppwriteCloud'
  | 'Supabase'
  | 'Firebase'
  | 'NHost'

type ProviderOption = {
  id: ImportProvider
  label: string
  icon?: string
  lucideIcon?: 'zap'
}

const PROVIDERS_OTHER: ProviderOption[] = [
  { id: 'Supabase', label: 'Supabase', lucideIcon: 'zap' },
  { id: 'Firebase', label: 'Firebase', icon: '/icons/firebase.svg' },
  { id: 'NHost', label: 'NHost' },
]

function getProviderOptions(isCloud: boolean): ProviderOption[] {
  const appwrite: ProviderOption[] = [
    {
      id: 'AppwriteSelfHosted',
      label: 'Appwrite (self-hosted)',
      icon: '/icons/appwrite.svg',
    },
  ]
  if (!isCloud) {
    appwrite.push({
      id: 'AppwriteCloud',
      label: 'Appwrite (Cloud)',
      icon: '/icons/appwrite.svg',
    })
  }
  return [...appwrite, ...PROVIDERS_OTHER]
}

/** Report keys per form group (spec: form group → report key). */
const REPORT_KEYS: Record<ResourceGroupKey, keyof Models.MigrationReport> = {
  users: 'user',
  databases: 'database',
  storage: 'bucket',
  functions: 'function',
}

type ResourceGroupKey = 'users' | 'databases' | 'storage' | 'functions'

interface ResourceFormState {
  users: { root: boolean; teams: boolean }
  databases: { root: boolean; rows: boolean }
  storage: { root: boolean }
  functions: { root: boolean; env: boolean; inactive: boolean }
}

const INITIAL_RESOURCE_FORM: ResourceFormState = {
  users: { root: false, teams: false },
  databases: { root: false, rows: false },
  storage: { root: false },
  functions: { root: false, env: false, inactive: false },
}

const PROVIDER_DISPLAY_LABELS: Record<ImportProvider, string> = {
  AppwriteSelfHosted: 'Appwrite (self-hosted)',
  AppwriteCloud: 'Appwrite (Cloud)',
  Supabase: 'Supabase',
  Firebase: 'Firebase',
  NHost: 'NHost',
}

export function ImportWizardView() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const pid = projectId as string
  const { project } = useProject(pid)
  const region = project?.region
  const { isCloud, features } = useConsoleProfile()
  const supportsMultiTenancy = features.multiTenancy

  const [step, setStep] = useState(1)
  const [provider, setProvider] = useState<ImportProvider | null>(null)
  const [reportError, setReportError] = useState<string | null>(null)
  const [loadingReport, setLoadingReport] = useState(false)
  const [reportData, setReportData] = useState<Models.MigrationReport | null>(
    null,
  )
  const [resourceForm, setResourceForm] = useState<ResourceFormState>(() => ({
    ...INITIAL_RESOURCE_FORM,
  }))
  const [onDuplicate, setOnDuplicate] = useState<OnDuplicate>(
    OnDuplicate.Fail,
  )

  const [endpoint, setEndpoint] = useState('')
  const [projectID, setProjectID] = useState('')
  const [apiKey, setApiKey] = useState('')

  const providers = useMemo(() => getProviderOptions(isCloud), [isCloud])

  useEffect(() => {
    if (step === 1) {
      setReportData(null)
      setResourceForm({ ...INITIAL_RESOURCE_FORM })
    }
  }, [step])

  const [supabaseEndpoint, setSupabaseEndpoint] = useState('')
  const [supabaseApiKey, setSupabaseApiKey] = useState('')
  const [databaseHost, setDatabaseHost] = useState('')
  const [supabaseUsername, setSupabaseUsername] = useState('postgres')
  const [supabasePassword, setSupabasePassword] = useState('')
  const [supabasePort, setSupabasePort] = useState('5432')

  const [serviceAccount, setServiceAccount] = useState('')

  const [nhostSubdomain, setNhostSubdomain] = useState('')
  const [nhostRegion, setNhostRegion] = useState('')
  const [adminSecret, setAdminSecret] = useState('')
  const [nhostDatabase, setNhostDatabase] = useState('')
  const [nhostUsername, setNhostUsername] = useState('postgres')
  const [nhostPassword, setNhostPassword] = useState('')
  const [nhostPort, setNhostPort] = useState('5432')

  const createAppwrite = useCreateAppwriteMigration(pid, region)
  const createSupabase = useCreateSupabaseMigration(pid, region)
  const createFirebase = useCreateFirebaseMigration(pid, region)
  const createNHost = useCreateNHostMigration(pid, region)

  const supportsFunctions =
    provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud'

  const visibleGroups = useMemo((): ResourceGroupKey[] => {
    const base: ResourceGroupKey[] = ['users', 'databases', 'storage']
    if (supportsFunctions) base.push('functions')
    return base
  }, [supportsFunctions])

  const getReportCount = (group: ResourceGroupKey): number | null => {
    if (!reportData) return null
    const key = REPORT_KEYS[group]
    const value = reportData[key]
    return typeof value === 'number' ? value : null
  }

  const resourceFormToResources = useCallback((): (
    | AppwriteMigrationResource
    | SupabaseMigrationResource
    | FirebaseMigrationResource
  )[] => {
    const allowed = supportsFunctions
      ? APPWRITE_RESOURCES
      : provider === 'Firebase'
        ? FIREBASE_RESOURCES
        : SUPABASE_NHOST_RESOURCES
    const out: (
      | AppwriteMigrationResource
      | SupabaseMigrationResource
      | FirebaseMigrationResource
    )[] = []
    if (resourceForm.users.root) {
      out.push(
        supportsFunctions
          ? AppwriteMigrationResource.User
          : provider === 'Firebase'
            ? FirebaseMigrationResource.User
            : SupabaseMigrationResource.User,
      )
    }
    if (resourceForm.databases.root) {
      if (supportsFunctions) {
        out.push(
          AppwriteMigrationResource.Database,
          AppwriteMigrationResource.Table,
          AppwriteMigrationResource.Column,
          AppwriteMigrationResource.Index,
        )
        if (resourceForm.databases.rows) out.push(AppwriteMigrationResource.Row)
      } else {
        const dbEnum =
          provider === 'Firebase'
            ? FirebaseMigrationResource
            : SupabaseMigrationResource
        out.push(
          dbEnum.Database,
          dbEnum.Collection,
          dbEnum.Attribute,
          ...(provider === 'Firebase' ? [] : [SupabaseMigrationResource.Index]),
          dbEnum.Document,
        )
      }
    }
    if (resourceForm.storage.root) {
      const storageEnum = supportsFunctions
        ? AppwriteMigrationResource
        : provider === 'Firebase'
          ? FirebaseMigrationResource
          : SupabaseMigrationResource
      out.push(storageEnum.Bucket, storageEnum.File)
    }
    const allowedSet = new Set<string>(allowed as readonly string[])
    return out.filter((r) => allowedSet.has(r))
  }, [resourceForm, provider, supportsFunctions])

  const selectedResourcesList = useMemo(
    () => resourceFormToResources(),
    [resourceFormToResources],
  )
  const hasSelection = selectedResourcesList.length > 0

  const selectAll = () => {
    setResourceForm({
      users: { root: true, teams: true },
      databases: { root: true, rows: true },
      storage: { root: true },
      functions: { root: true, env: true, inactive: true },
    })
  }

  const selectNone = () => {
    setResourceForm({ ...INITIAL_RESOURCE_FORM })
  }

  const setGroupRoot = (group: ResourceGroupKey, value: boolean) => {
    setResourceForm((prev) => {
      const next = { ...prev }
      if (group === 'users')
        next.users = { ...prev.users, root: value, teams: value }
      else if (group === 'databases')
        next.databases = { ...prev.databases, root: value, rows: value }
      else if (group === 'storage') next.storage = { root: value }
      else if (group === 'functions')
        next.functions = {
          ...prev.functions,
          root: value,
          env: value,
          inactive: value,
        }
      return next
    })
  }

  const setGroupChild = (
    group: 'users' | 'databases' | 'functions',
    child:
      | keyof ResourceFormState['users']
      | keyof ResourceFormState['databases']
      | keyof ResourceFormState['functions'],
    value: boolean,
  ) => {
    setResourceForm((prev) => {
      const next = { ...prev }
      if (group === 'users' && (child === 'root' || child === 'teams'))
        next.users = { ...prev.users, [child]: value }
      else if (group === 'databases' && (child === 'root' || child === 'rows'))
        next.databases = { ...prev.databases, [child]: value }
      else if (
        group === 'functions' &&
        (child === 'root' || child === 'env' || child === 'inactive')
      )
        next.functions = { ...prev.functions, [child]: value }
      return next
    })
  }

  const handleFetchReport = async () => {
    if (!provider || !pid) return
    setReportError(null)
    setLoadingReport(true)
    try {
      if (provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud') {
        if (!endpoint.trim() || !projectID.trim() || !apiKey.trim()) {
          toast.error(t('Please fill endpoint, project ID, and API key'))
          return
        }
        const report = await fetchAppwriteReport(
          pid,
          {
            endpoint: endpoint.trim(),
            projectID: projectID.trim(),
            key: apiKey.trim(),
          },
          region,
        )
        setReportData(report)
      } else if (provider === 'Supabase') {
        if (
          !supabaseEndpoint.trim() ||
          !supabaseApiKey.trim() ||
          !databaseHost.trim() ||
          !supabasePassword.trim()
        ) {
          toast.error(t('Please fill required Supabase fields'))
          return
        }
        const report = await fetchSupabaseReport(
          pid,
          {
            endpoint: supabaseEndpoint.trim(),
            apiKey: supabaseApiKey.trim(),
            databaseHost: databaseHost.trim(),
            username: supabaseUsername.trim() || 'postgres',
            password: supabasePassword,
            port: parseInt(supabasePort, 10) || 5432,
          },
          region,
        )
        setReportData(report)
      } else if (provider === 'Firebase') {
        if (!serviceAccount.trim()) {
          toast.error(t('Please paste the service account JSON'))
          return
        }
        try {
          JSON.parse(serviceAccount)
        } catch {
          toast.error(t('Service account must be valid JSON'))
          return
        }
        const report = await fetchFirebaseReport(
          pid,
          { serviceAccount },
          region,
        )
        setReportData(report)
      } else if (provider === 'NHost') {
        if (
          !nhostSubdomain.trim() ||
          !nhostRegion.trim() ||
          !adminSecret.trim() ||
          !nhostPassword.trim()
        ) {
          toast.error(t('Please fill required NHost fields'))
          return
        }
        const report = await fetchNHostReport(
          pid,
          {
            subdomain: nhostSubdomain.trim(),
            region: nhostRegion.trim(),
            adminSecret: adminSecret.trim(),
            database: nhostDatabase.trim() || undefined,
            username: nhostUsername.trim() || 'postgres',
            password: nhostPassword,
            port: parseInt(nhostPort, 10) || undefined,
          },
          region,
        )
        setReportData(report)
      }
      setStep(3)
      selectAll()
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : t('Failed to load report')
      setReportError(msg)
      toast.error(msg)
    } finally {
      setLoadingReport(false)
    }
  }

  const handleCreate = async () => {
    if (!hasSelection) {
      toast.error(t('Select at least one resource'))
      return
    }
    try {
      if (provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud') {
        await createAppwrite.mutateAsync({
          resources: selectedResourcesList as AppwriteMigrationResource[],
          endpoint: endpoint.trim(),
          projectId: projectID.trim(),
          apiKey: apiKey.trim(),
          onDuplicate,
        })
      } else if (provider === 'Supabase') {
        await createSupabase.mutateAsync({
          resources: selectedResourcesList as SupabaseMigrationResource[],
          endpoint: supabaseEndpoint.trim(),
          apiKey: supabaseApiKey.trim(),
          databaseHost: databaseHost.trim(),
          username: supabaseUsername.trim() || 'postgres',
          password: supabasePassword,
          port: parseInt(supabasePort, 10) || 5432,
        })
      } else if (provider === 'Firebase') {
        await createFirebase.mutateAsync({
          resources: selectedResourcesList as FirebaseMigrationResource[],
          serviceAccount,
        })
      } else if (provider === 'NHost') {
        const nhostAllowed = new Set<string>(NHOST_RESOURCES)
        const nhostResources = selectedResourcesList.filter((r) =>
          nhostAllowed.has(r),
        ) as unknown as NHostMigrationResource[]
        await createNHost.mutateAsync({
          resources: nhostResources,
          subdomain: nhostSubdomain.trim(),
          region: nhostRegion.trim(),
          adminSecret: adminSecret.trim(),
          database: nhostDatabase.trim() || undefined,
          username: nhostUsername.trim() || undefined,
          password: nhostPassword,
          port: parseInt(nhostPort, 10) || undefined,
        })
      }
      toast.success(t('Migration started'))
      navigate({
        to: '/projects/$projectId/settings/migrations',
        params: { projectId: pid },
      })
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : t('Failed to start migration'))
    }
  }

  const isCreatePending =
    createAppwrite.isPending ||
    createSupabase.isPending ||
    createFirebase.isPending ||
    createNHost.isPending

  const stepContent = (
    <div className="space-y-6">
      {step === 1 && (
        <>
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Migrations import users, databases, and storage from an external platform into this project. Data is not deleted from the source.',
            )}
          </p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {providers.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => {
                  setProvider(p.id)
                  setStep(2)
                }}
                className="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-border bg-card/50 p-5 text-start transition-all hover:border-border/80 hover:bg-card/60"
              >
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground overflow-hidden">
                  {p.icon ? (
                    <img
                      src={p.icon}
                      alt=""
                      className={`h-5 w-5 object-contain ${PUBLIC_ICON_MUTED_CLASSES}`}
                    />
                  ) : p.lucideIcon === 'zap' ? (
                    <Zap className="h-5 w-5" />
                  ) : (
                    <Database className="h-5 w-5" />
                  )}
                </div>
                <span className="text-[14px] font-medium text-foreground">
                  {p.label}
                </span>
              </button>
            ))}
          </div>
          {supportsMultiTenancy && (
            <>
              <div className="relative py-2">
                <div className="absolute inset-0 flex items-center" aria-hidden>
                  <div className="w-full border-t border-border" />
                </div>
                <div className="relative flex justify-center">
                  <span className="bg-background px-3 text-[12px] font-medium text-muted-foreground">
                    {t('Or')}
                  </span>
                </div>
              </div>
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <ArrowRightLeft className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Transfer between organizations')}
                      </h3>
                      <p className="text-[13px] text-muted-foreground mt-2">
                        {t(
                          'Move this project to another organization in your account. Ownership updates immediately; no data is imported.',
                        )}
                      </p>
                    </div>
                  </div>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4 bg-muted/30">
                  <Button
                    type="button"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() =>
                      navigate({
                        to: '/projects/$projectId/settings',
                        params: { projectId: pid },
                        hash: 'card-transfer-project',
                      })
                    }
                  >
                    {t('Transfer project')}
                  </Button>
                </div>
              </div>
            </>
          )}
        </>
      )}

      {step === 2 &&
        (provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud') && (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Credentials')}
              </h3>
              <p className="text-[12px] text-muted-foreground mt-1">
                {provider === 'AppwriteSelfHosted'
                  ? t(
                      'Import from a self-hosted Appwrite instance. Enter the endpoint, project ID, and a server API key with read scopes for the resources you want to migrate.', // pragma: allowlist secret
                    )
                  : t(
                      'Import from Appwrite Cloud. Enter the endpoint (with region), project ID, and a server API key with read scopes for the resources you want to migrate.', // pragma: allowlist secret
                    )}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-4">
              <div className="space-y-2">
                <Label htmlFor="appwrite-endpoint" className="text-[13px]">
                  {t('Endpoint')}
                </Label>
                <Input
                  id="appwrite-endpoint"
                  placeholder={
                    provider === 'AppwriteSelfHosted'
                      ? 'https://<YOUR_APPWRITE_HOSTNAME>/v1'
                      : 'https://<region>.cloud.appwrite.io/v1'
                  }
                  value={endpoint}
                  onChange={(e) => setEndpoint(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="appwrite-project-id" className="text-[13px]">
                  {t('Project ID')}
                </Label>
                <Input
                  id="appwrite-project-id"
                  value={projectID}
                  onChange={(e) => setProjectID(e.target.value)}
                  placeholder={t('Source project ID')}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="appwrite-api-key" className="text-[13px]">
                    {t('API key')}
                  </Label>
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-3.5 w-3.5 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent side="top" className="max-w-[240px]">
                        {t(
                          'Server API key with read scopes for users, databases, storage, etc. The source project must be reachable from the internet.',
                        )}
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                </div>
                <Input
                  id="appwrite-api-key"
                  type="password"
                  value={apiKey}
                  onChange={(e) => setApiKey(e.target.value)}
                  placeholder={t('Server API key with read scopes')}
                  className="h-9 text-[13px]"
                />
              </div>
              <p className="text-[11px] text-muted-foreground pt-1">
                {t(
                  'Migrations are non-destructive. $createdAt and $updatedAt may be set to the migration date.',
                )}
              </p>
            </div>
          </div>
        )}

      {step === 2 && provider === 'Supabase' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Credentials')}
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t('In Supabase:')} <strong>{t('Project Settings → Database')}</strong>{' '}
              {t('(Host, Port, Username, Password) and')}{' '}
              <strong>{t('Project Settings → API')}</strong>{' '}
              {t('(Endpoint and API key). Use the')}{' '}
              <strong>service_role</strong> {t('key for the API key.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="supabase-endpoint" className="text-[13px]">
                {t('Supabase endpoint')}
              </Label>
              <Input
                id="supabase-endpoint"
                placeholder="https://xxx.supabase.co"
                value={supabaseEndpoint}
                onChange={(e) => setSupabaseEndpoint(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="supabase-api-key" className="text-[13px]">
                {t('API key')}
              </Label>
              <Input
                id="supabase-api-key"
                type="password"
                value={supabaseApiKey}
                onChange={(e) => setSupabaseApiKey(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="supabase-db-host" className="text-[13px]">
                {t('Database host')}
              </Label>
              <Input
                id="supabase-db-host"
                value={databaseHost}
                onChange={(e) => setDatabaseHost(e.target.value)}
                placeholder="db.xxx.supabase.co"
                className="h-9 text-[13px]"
              />
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="supabase-username" className="text-[13px]">
                  {t('Username')}
                </Label>
                <Input
                  id="supabase-username"
                  value={supabaseUsername}
                  onChange={(e) => setSupabaseUsername(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="supabase-port" className="text-[13px]">
                  {t('Port')}
                </Label>
                <Input
                  id="supabase-port"
                  value={supabasePort}
                  onChange={(e) => setSupabasePort(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="supabase-password" className="text-[13px]">
                {t('Password')}
              </Label>
              <Input
                id="supabase-password"
                type="password"
                value={supabasePassword}
                onChange={(e) => setSupabasePassword(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <p className="text-[11px] text-muted-foreground pt-1">
              {t(
                'Some PostgreSQL features are not migrated. OAuth users and functions are not migrated automatically.',
              )}
            </p>
          </div>
        </div>
      )}

      {step === 2 && provider === 'Firebase' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Credentials')}
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t(
                'Use a service account JSON key. In Firebase Console: Project Settings → Service Accounts → Create service account, then add keys and create a new JSON key. Required roles: Firebase Viewer (Database and Storage), Identity Toolkit Viewer (users).',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="firebase-service-account" className="text-[13px]">
                {t('Service account JSON')}
              </Label>
              <Textarea
                id="firebase-service-account"
                className="font-mono text-[12px] min-h-[200px]"
                placeholder={t('Paste the full service account JSON object...')}
                value={serviceAccount}
                onChange={(e) => setServiceAccount(e.target.value)}
              />
            </div>
            <p className="text-[11px] text-muted-foreground pt-1">
              {t(
                'Only Firestore is supported; Realtime Database is not. OAuth users and functions are not migrated automatically.',
              )}
            </p>
          </div>
        </div>
      )}

      {step === 2 && provider === 'NHost' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Credentials')}
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t('Find these in your NHost project:')}{' '}
              <strong>{t('Environment variables')}</strong>{' '}
              {t('(Region, Subdomain, Admin Secret) and')}{' '}
              <strong>{t('Database settings')}</strong>{' '}
              {t('(Database name, Username, Password). Admin Secret is used for files.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="nhost-subdomain" className="text-[13px]">
                  {t('Subdomain')}
                </Label>
                <Input
                  id="nhost-subdomain"
                  value={nhostSubdomain}
                  onChange={(e) => setNhostSubdomain(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="nhost-region" className="text-[13px]">
                  {t('Region')}
                </Label>
                <Input
                  id="nhost-region"
                  value={nhostRegion}
                  onChange={(e) => setNhostRegion(e.target.value)}
                  placeholder="e.g. us-east-1"
                  className="h-9 text-[13px]"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="nhost-admin-secret" className="text-[13px]">
                {t('Admin secret')}
              </Label>
              <Input
                id="nhost-admin-secret"
                type="password"
                value={adminSecret}
                onChange={(e) => setAdminSecret(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="nhost-database" className="text-[13px]">
                {t('Database (optional)')}
              </Label>
              <Input
                id="nhost-database"
                value={nhostDatabase}
                onChange={(e) => setNhostDatabase(e.target.value)}
                placeholder={t('Defaults to subdomain')}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="nhost-username" className="text-[13px]">
                  {t('Username')}
                </Label>
                <Input
                  id="nhost-username"
                  value={nhostUsername}
                  onChange={(e) => setNhostUsername(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="nhost-port" className="text-[13px]">
                  {t('Port')}
                </Label>
                <Input
                  id="nhost-port"
                  value={nhostPort}
                  onChange={(e) => setNhostPort(e.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="nhost-password" className="text-[13px]">
                {t('Password')}
              </Label>
              <Input
                id="nhost-password"
                type="password"
                value={nhostPassword}
                onChange={(e) => setNhostPassword(e.target.value)}
                className="h-9 text-[13px]"
              />
            </div>
            <p className="text-[11px] text-muted-foreground pt-1">
              {t(
                'PostgreSQL-specific features are not migrated. OAuth users and functions are not migrated automatically.',
              )}
            </p>
          </div>
        </div>
      )}

      {step === 3 && (
        <>
          {(provider === 'AppwriteSelfHosted' ||
            provider === 'AppwriteCloud') && (
            <div className="rounded-lg border border-border bg-card/50 px-4 py-3 space-y-2">
              <Label className="text-[13px] font-medium">{t('Duplicate rows')}</Label>
              <p className="text-[12px] text-muted-foreground">
                {t('When a row with an existing ID is encountered during import.')}
              </p>
              <div className="flex flex-wrap gap-2">
                {(
                  [
                    [OnDuplicate.Fail, 'Fail'],
                    [OnDuplicate.Skip, 'Skip'],
                    [OnDuplicate.Overwrite, 'Overwrite'],
                  ] as const
                ).map(([value, label]) => (
                  <Button
                    key={value}
                    type="button"
                    size="sm"
                    variant={onDuplicate === value ? 'default' : 'outline'}
                    className="h-8 text-[13px]"
                    onClick={() => setOnDuplicate(value)}
                  >
                    {t(label)}
                  </Button>
                ))}
              </div>
            </div>
          )}
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Choose which resources to migrate. You do not need to keep the Console open; the migration continues in the background. After migrating, add platforms in Overview → Integrations → Platforms and set permissions on migrated resources.',
            )}
          </p>
          {reportError && (
            <Alert variant="destructive">
              <AlertDescription>{reportError}</AlertDescription>
            </Alert>
          )}
          {!reportError && (
            <>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={selectAll}
                >
                  {t('Select all')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={selectNone}
                >
                  {t('Deselect all')}
                </Button>
              </div>
              <div className="space-y-2">
                {visibleGroups.map((group) => {
                  const count = getReportCount(group)
                  const countLabel =
                    count !== null ? count.toLocaleString() : '-'
                  if (group === 'storage') {
                    return (
                      <div
                        key={group}
                        className="flex items-center gap-3 rounded-lg border border-border bg-card/50 px-4 py-3"
                      >
                        <Checkbox
                          id={`res-${group}-root`}
                          checked={resourceForm.storage.root}
                          onCheckedChange={(v) =>
                            setGroupRoot('storage', v === true)
                          }
                        />
                        <Label
                          htmlFor={`res-${group}-root`}
                          className="flex-1 cursor-pointer text-[13px] font-medium"
                        >
                          {t('Storage')}
                        </Label>
                        <span className="text-[12px] text-muted-foreground tabular-nums">
                          {countLabel}
                        </span>
                      </div>
                    )
                  }
                  if (group === 'users') {
                    return (
                      <Accordion
                        key={group}
                        type="single"
                        collapsible
                        className="rounded-lg border border-border bg-card/50"
                      >
                        <AccordionItem value="users" className="border-none">
                          <AccordionTrigger className="px-4 py-3 hover:no-underline [&[data-state=open]]:rounded-b-none">
                            <div className="flex items-center gap-3 text-start">
                              <Checkbox
                                checked={resourceForm.users.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('users', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                {t('Users')}
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0">
                            <div className="flex items-center gap-2 ps-6">
                              <Checkbox
                                id="users-teams"
                                checked={resourceForm.users.teams}
                                onCheckedChange={(v) =>
                                  setGroupChild('users', 'teams', v === true)
                                }
                              />
                              <Label
                                htmlFor="users-teams"
                                className="cursor-pointer text-[13px] font-normal"
                              >
                                {t('Include teams')}
                              </Label>
                            </div>
                            <p className="mt-1 ps-6 text-[11px] text-muted-foreground">
                              {t(
                                'Import all teams and the team memberships of your users.',
                              )}
                            </p>
                          </AccordionContent>
                        </AccordionItem>
                      </Accordion>
                    )
                  }
                  if (group === 'databases') {
                    return (
                      <Accordion
                        key={group}
                        type="single"
                        collapsible
                        className="rounded-lg border border-border bg-card/50"
                      >
                        <AccordionItem
                          value="databases"
                          className="border-none"
                        >
                          <AccordionTrigger className="px-4 py-3 hover:no-underline [&[data-state=open]]:rounded-b-none">
                            <div className="flex items-center gap-3 text-start">
                              <Checkbox
                                checked={resourceForm.databases.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('databases', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                {t('Databases')}
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0">
                            <div className="flex items-center gap-2 ps-6">
                              <Checkbox
                                id="databases-rows"
                                checked={resourceForm.databases.rows}
                                onCheckedChange={(v) =>
                                  setGroupChild('databases', 'rows', v === true)
                                }
                              />
                              <Label
                                htmlFor="databases-rows"
                                className="cursor-pointer text-[13px] font-normal"
                              >
                                {t('Include rows')}
                              </Label>
                            </div>
                            <p className="mt-1 ps-6 text-[11px] text-muted-foreground">
                              {t('Import all rows inside tables.')}
                            </p>
                          </AccordionContent>
                        </AccordionItem>
                      </Accordion>
                    )
                  }
                  if (group === 'functions') {
                    return (
                      <Accordion
                        key={group}
                        type="single"
                        collapsible
                        className="rounded-lg border border-border bg-card/50"
                      >
                        <AccordionItem
                          value="functions"
                          className="border-none"
                        >
                          <AccordionTrigger className="px-4 py-3 hover:no-underline [&[data-state=open]]:rounded-b-none">
                            <div className="flex items-center gap-3 text-start">
                              <Checkbox
                                checked={resourceForm.functions.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('functions', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                {t('Functions')}
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0 space-y-2">
                            <div className="flex items-center gap-2 ps-6">
                              <Checkbox
                                id="functions-env"
                                checked={resourceForm.functions.env}
                                onCheckedChange={(v) =>
                                  setGroupChild('functions', 'env', v === true)
                                }
                              />
                              <Label
                                htmlFor="functions-env"
                                className="cursor-pointer text-[13px] font-normal"
                              >
                                {t('Include environment variables')}
                              </Label>
                            </div>
                            <p className="ps-6 text-[11px] text-muted-foreground">
                              {t('Import all environment variables.')}
                            </p>
                            <div className="flex items-center gap-2 ps-6">
                              <Checkbox
                                id="functions-inactive"
                                checked={resourceForm.functions.inactive}
                                onCheckedChange={(v) =>
                                  setGroupChild(
                                    'functions',
                                    'inactive',
                                    v === true,
                                  )
                                }
                              />
                              <Label
                                htmlFor="functions-inactive"
                                className="cursor-pointer text-[13px] font-normal"
                              >
                                {t('Include inactive deployments')}
                              </Label>
                            </div>
                            <p className="ps-6 text-[11px] text-muted-foreground">
                              {t(
                                'Import all deployments that are not currently active.',
                              )}
                            </p>
                          </AccordionContent>
                        </AccordionItem>
                      </Accordion>
                    )
                  }
                  return null
                })}
              </div>
            </>
          )}
        </>
      )}
    </div>
  )

  const footer = (
    <div className="flex w-full justify-end gap-2">
      {step > 1 && (
        <Button
          type="button"
          variant="outline"
          onClick={() => setStep((s) => s - 1)}
          disabled={loadingReport || isCreatePending}
        >
          {t('Back')}
        </Button>
      )}
      {step === 2 && (
        <Button
          type="button"
          disabled={loadingReport}
          onClick={handleFetchReport}
        >
          {t('Continue')}
        </Button>
      )}
      {step === 3 && (
        <Button
          type="button"
          disabled={!hasSelection || isCreatePending}
          onClick={handleCreate}
        >
          {t('Start migration')}
        </Button>
      )}
    </div>
  )

  const wizardTitle =
    step > 1 && provider
      ? `${t('Import from')} ${PROVIDER_DISPLAY_LABELS[provider]}`
      : t('Import data')

  return (
    <WizardLayout
      title={wizardTitle}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      fallbackPath={`/projects/${pid}/settings/migrations`}
      footer={footer}
      footerAlign="right"
    >
      {stepContent}
    </WizardLayout>
  )
}
