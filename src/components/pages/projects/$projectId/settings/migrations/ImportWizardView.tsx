/**
 * Fullscreen import data wizard: provider → credentials → get report → resource selection → create migration.
 */

import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Database, Info, Zap } from 'lucide-react'
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
import {
  AppwriteMigrationResource,
  SupabaseMigrationResource,
  FirebaseMigrationResource,
  type Models,
} from '@appwrite.io/console'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useProject,
  APPWRITE_RESOURCES,
  SUPABASE_NHOST_RESOURCES,
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
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const pid = projectId as string
  const { project } = useProject(pid)
  const region = project?.region
  const { isCloud } = useConsoleProfile()

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

  function resourceFormToResources(): (
    | AppwriteMigrationResource
    | SupabaseMigrationResource
    | FirebaseMigrationResource
  )[] {
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
    return out.filter((r) => allowed.includes(r as (typeof allowed)[number]))
  }

  const selectedResourcesList = useMemo(
    () => resourceFormToResources(),
    [resourceForm, provider, supportsFunctions],
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
          toast.error('Please fill endpoint, project ID, and API key')
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
          toast.error('Please fill required Supabase fields')
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
          toast.error('Please paste the service account JSON')
          return
        }
        try {
          JSON.parse(serviceAccount)
        } catch {
          toast.error('Service account must be valid JSON')
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
          toast.error('Please fill required NHost fields')
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
      const msg = e instanceof Error ? e.message : 'Failed to load report'
      setReportError(msg)
      toast.error(msg)
    } finally {
      setLoadingReport(false)
    }
  }

  const handleCreate = async () => {
    if (!hasSelection) {
      toast.error('Select at least one resource')
      return
    }
    const resources = selectedResourcesList
    try {
      if (provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud') {
        await createAppwrite.mutateAsync({
          resources,
          endpoint: endpoint.trim(),
          projectId: projectID.trim(),
          apiKey: apiKey.trim(),
        })
      } else if (provider === 'Supabase') {
        await createSupabase.mutateAsync({
          resources,
          endpoint: supabaseEndpoint.trim(),
          apiKey: supabaseApiKey.trim(),
          databaseHost: databaseHost.trim(),
          username: supabaseUsername.trim() || 'postgres',
          password: supabasePassword,
          port: parseInt(supabasePort, 10) || 5432,
        })
      } else if (provider === 'Firebase') {
        await createFirebase.mutateAsync({ resources, serviceAccount })
      } else if (provider === 'NHost') {
        await createNHost.mutateAsync({
          resources,
          subdomain: nhostSubdomain.trim(),
          region: nhostRegion.trim(),
          adminSecret: adminSecret.trim(),
          database: nhostDatabase.trim() || undefined,
          username: nhostUsername.trim() || undefined,
          password: nhostPassword,
          port: parseInt(nhostPort, 10) || undefined,
        })
      }
      toast.success('Migration started')
      navigate({
        to: '/projects/$projectId/settings/migrations',
        params: { projectId: pid },
      })
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Failed to start migration')
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
            Migrations copy users, databases, and storage from the source into
            this project. Data is not deleted from the source. Choose the
            platform you want to import from.
          </p>
          <div className="rounded-lg border border-border bg-muted/30 px-4 py-3">
            <p className="text-[12px] text-muted-foreground">
              Moving a project between two organizations you own? Use{' '}
              <Link
                to="/projects/$projectId/settings"
                params={{ projectId: pid }}
                className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
              >
                project transfer
              </Link>{' '}
              in Settings → Overview instead of migration. Transfer completes
              immediately and does not copy data, so it is the recommended
              option when the project already exists in your account.
            </p>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {providers.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => {
                  setProvider(p.id)
                  setStep(2)
                }}
                className="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-border bg-card/50 p-5 text-left transition-all hover:border-border/80 hover:bg-card/60"
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
        </>
      )}

      {step === 2 &&
        (provider === 'AppwriteSelfHosted' || provider === 'AppwriteCloud') && (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Credentials
              </h3>
              <p className="text-[12px] text-muted-foreground mt-1">
                {provider === 'AppwriteSelfHosted'
                  ? 'Import from a self-hosted Appwrite instance. Enter the endpoint, project ID, and a server API key with read scopes for the resources you want to migrate.'
                  : 'Import from Appwrite Cloud. Enter the endpoint (with region), project ID, and a server API key with read scopes for the resources you want to migrate.'}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-4">
              <div className="space-y-2">
                <Label htmlFor="appwrite-endpoint" className="text-[13px]">
                  Endpoint
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
                  Project ID
                </Label>
                <Input
                  id="appwrite-project-id"
                  value={projectID}
                  onChange={(e) => setProjectID(e.target.value)}
                  placeholder="Source project ID"
                  className="h-9 text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="appwrite-api-key" className="text-[13px]">
                    API key
                  </Label>
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-3.5 w-3.5 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent side="top" className="max-w-[240px]">
                        Server API key with read scopes for users, databases,
                        storage, etc. The source project must be reachable from
                        the internet.
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                </div>
                <Input
                  id="appwrite-api-key"
                  type="password"
                  value={apiKey}
                  onChange={(e) => setApiKey(e.target.value)}
                  placeholder="Server API key with read scopes"
                  className="h-9 text-[13px]"
                />
              </div>
              <p className="text-[11px] text-muted-foreground pt-1">
                Migrations are non-destructive. $createdAt and $updatedAt may be
                set to the migration date.
              </p>
            </div>
          </div>
        )}

      {step === 2 && provider === 'Supabase' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Credentials
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              In Supabase: <strong>Project Settings → Database</strong> (Host,
              Port, Username, Password) and{' '}
              <strong>Project Settings → API</strong> (Endpoint and API key).
              Use the <strong>service_role</strong> key for the API key.
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="supabase-endpoint" className="text-[13px]">
                Supabase endpoint
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
                API key
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
                Database host
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
                  Username
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
                  Port
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
                Password
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
              Some PostgreSQL features are not migrated. OAuth users and
              functions are not migrated automatically.
            </p>
          </div>
        </div>
      )}

      {step === 2 && provider === 'Firebase' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Credentials
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              Use a service account JSON key. In Firebase Console: Project
              Settings → Service Accounts → Create service account, then add
              keys and create a new JSON key. Required roles: Firebase Viewer
              (Database and Storage), Identity Toolkit Viewer (users).
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="firebase-service-account" className="text-[13px]">
                Service account JSON
              </Label>
              <Textarea
                id="firebase-service-account"
                className="font-mono text-[12px] min-h-[200px]"
                placeholder="Paste the full service account JSON object..."
                value={serviceAccount}
                onChange={(e) => setServiceAccount(e.target.value)}
              />
            </div>
            <p className="text-[11px] text-muted-foreground pt-1">
              Only Firestore is supported; Realtime Database is not. OAuth users
              and functions are not migrated automatically.
            </p>
          </div>
        </div>
      )}

      {step === 2 && provider === 'NHost' && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Credentials
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              Find these in your NHost project:{' '}
              <strong>Environment variables</strong> (Region, Subdomain, Admin
              Secret) and <strong>Database settings</strong> (Database name,
              Username, Password). Admin Secret is used for files.
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="nhost-subdomain" className="text-[13px]">
                  Subdomain
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
                  Region
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
                Admin secret
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
                Database (optional)
              </Label>
              <Input
                id="nhost-database"
                value={nhostDatabase}
                onChange={(e) => setNhostDatabase(e.target.value)}
                placeholder="Defaults to subdomain"
                className="h-9 text-[13px]"
              />
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="nhost-username" className="text-[13px]">
                  Username
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
                  Port
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
                Password
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
              PostgreSQL-specific features are not migrated. OAuth users and
              functions are not migrated automatically.
            </p>
          </div>
        </div>
      )}

      {step === 3 && (
        <>
          <p className="text-[13px] text-muted-foreground">
            Choose which resources to migrate. You do not need to keep the
            Console open; the migration continues in the background. After
            migrating, add platforms in Overview → Integrations → Platforms and
            set permissions on migrated resources.
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
                  Select all
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={selectNone}
                >
                  Deselect all
                </Button>
              </div>
              <div className="space-y-2">
                {visibleGroups.map((group) => {
                  const count = getReportCount(group)
                  const countLabel =
                    count !== null ? count.toLocaleString() : '—'
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
                          Storage
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
                            <div className="flex items-center gap-3 text-left">
                              <Checkbox
                                checked={resourceForm.users.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('users', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                Users
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0">
                            <div className="flex items-center gap-2 pl-6">
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
                                Include teams
                              </Label>
                            </div>
                            <p className="mt-1 pl-6 text-[11px] text-muted-foreground">
                              Import all teams and the team memberships of your
                              users.
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
                            <div className="flex items-center gap-3 text-left">
                              <Checkbox
                                checked={resourceForm.databases.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('databases', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                Databases
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0">
                            <div className="flex items-center gap-2 pl-6">
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
                                Include rows
                              </Label>
                            </div>
                            <p className="mt-1 pl-6 text-[11px] text-muted-foreground">
                              Import all rows inside tables.
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
                            <div className="flex items-center gap-3 text-left">
                              <Checkbox
                                checked={resourceForm.functions.root}
                                onCheckedChange={(v) =>
                                  setGroupRoot('functions', v === true)
                                }
                                onClick={(e) => e.stopPropagation()}
                              />
                              <span className="text-[13px] font-medium">
                                Functions
                              </span>
                              <span className="text-[12px] text-muted-foreground tabular-nums">
                                {countLabel}
                              </span>
                            </div>
                          </AccordionTrigger>
                          <AccordionContent className="px-4 pb-3 pt-0 space-y-2">
                            <div className="flex items-center gap-2 pl-6">
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
                                Include environment variables
                              </Label>
                            </div>
                            <p className="pl-6 text-[11px] text-muted-foreground">
                              Import all environment variables.
                            </p>
                            <div className="flex items-center gap-2 pl-6">
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
                                Include inactive deployments
                              </Label>
                            </div>
                            <p className="pl-6 text-[11px] text-muted-foreground">
                              Import all deployments that are not currently
                              active.
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
          Back
        </Button>
      )}
      {step === 2 && (
        <Button
          type="button"
          disabled={loadingReport}
          onClick={handleFetchReport}
        >
          Continue
        </Button>
      )}
      {step === 3 && (
        <Button
          type="button"
          disabled={!hasSelection || isCreatePending}
          onClick={handleCreate}
        >
          {isCreatePending ? 'Starting...' : 'Start migration'}
        </Button>
      )}
    </div>
  )

  const wizardTitle =
    step > 1 && provider
      ? `Import from ${PROVIDER_DISPLAY_LABELS[provider]}`
      : 'Import data'

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
