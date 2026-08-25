/**
 * Connect to your project – simplified modal for project credentials and SDK setup.
 * Adapted from Supabase-style connect flow; tailored to Appwrite (endpoint, project ID, API keys).
 */

import { useState, useMemo, useEffect, useCallback } from 'react'
import { Check, Copy, ExternalLink, Key, Plus } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useConnectProjectTab,
  useCreateApiKey,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { getApiEndpoint, getBaseEndpoint } from '@/lib/appwrite/sdk'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PackageManagerIcon } from '@/components/global/shared/PackageManagerIcon'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { CLISection } from '@/components/pages/projects/$projectId/shared/CLISection'
import { S3ConnectSection } from '@/components/pages/projects/$projectId/shared/S3ConnectSection'
import { TerraformConnectSection } from '@/components/pages/projects/$projectId/shared/TerraformConnectSection'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import {
  buildConnectSdkPrompt,
  getCodeFiles,
  getInstallInstructions,
} from '@/components/pages/projects/$projectId/shared/connect-snippets'
import { Tabs, TabsContent } from '@/components/ui/tabs'
import { useT } from '@/lib/i18n/translate'
import { getVcsProvider } from '@/lib/vcs/providers'
import {
  APPWRITE_AGENT_SKILLS_INSTALL,
  APPWRITE_AGENT_SKILLS_REPO,
} from '@/lib/seo/agent-discovery'
import { SKILLS_TRY_IT_PROMPTS } from '@/lib/skills-adoption'
import { analyticsAttrs, ANALYTICS_ACTIONS } from '@/lib/analytics-actions'
import { canCreateKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useAnalytics } from '@/hooks/use-analytics'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const APPWRITE_DOCS_URL = '/docs'
const APPWRITE_SKILLS_DOCS_URL = '/docs/tooling/skills'
const SDK_API_KEY_DEFAULT_NAME = 'SDK'

// This link always points at a GitHub-hosted repo (appwrite/skills),
// not a user-connected VCS installation, so the provider is hardcoded here.
const { Icon: GitHubIcon } = getVcsProvider('github')

/**
 * Client platforms shown in the first dropdown –
 * [docs](https://appwrite.io/docs/sdks#client). Ionic, Capacitor, and Tauri
 * build on web tech, so they resolve to the Web SDK under the hood (`sdkId`,
 * which drives snippets, install steps, and analytics) and register as a Web
 * platform in the console; the other entries are Appwrite SDKs of their own.
 */
const CLIENT_PLATFORM_OPTIONS: {
  id: string
  label: string
  /** Underlying SDK when it differs from the platform id. */
  sdkId?: string
}[] = [
  { id: 'web', label: 'Web' },
  { id: 'flutter', label: 'Flutter' },
  { id: 'react-native', label: 'React Native' },
  { id: 'ionic', label: 'Ionic', sdkId: 'web' },
  { id: 'capacitor', label: 'Capacitor', sdkId: 'web' },
  { id: 'tauri', label: 'Tauri', sdkId: 'web' },
  { id: 'apple', label: 'Apple' },
  { id: 'android', label: 'Android' },
]

/** True for ids selectable in the platform dropdown (client or server). */
function isKnownPlatform(id: string): boolean {
  return (
    CLIENT_PLATFORM_OPTIONS.some((o) => o.id === id) ||
    SERVER_SDK_OPTIONS.some((o) => o.id === id)
  )
}

/** Server SDKs – [docs](https://appwrite.io/docs/sdks#server) */
const SERVER_SDK_OPTIONS: { id: string; platform: string; label: string }[] = [
  { id: 'node', platform: 'web', label: 'Node.js' },
  { id: 'deno', platform: 'web', label: 'Deno' },
  { id: 'bun', platform: 'web', label: 'Bun' },
  { id: 'go', platform: 'web', label: 'Go' },
  { id: 'python', platform: 'web', label: 'Python' },
  { id: 'php', platform: 'web', label: 'PHP' },
  { id: 'ruby', platform: 'web', label: 'Ruby' },
  { id: 'dart', platform: 'web', label: 'Dart' },
  { id: 'swift', platform: 'apple', label: 'Swift' },
  { id: 'dotnet', platform: 'web', label: '.NET' },
  { id: 'kotlin', platform: 'android', label: 'Kotlin' },
  { id: 'java', platform: 'web', label: 'Java' },
  { id: 'rust', platform: 'web', label: 'Rust' },
]

/** Framework options per platform (id + label). Aligned with https://appwrite.io/docs/quick-starts */
const FRAMEWORK_OPTIONS: Record<
  string,
  { id: string; label: string; icon?: string }[]
> = {
  web: [
    { id: 'tanstack', label: 'TanStack Start' },
    { id: 'next', label: 'Next.js' },
    { id: 'react', label: 'React' },
    { id: 'sveltekit', label: 'SvelteKit' },
    { id: 'svelte', label: 'Svelte' },
    { id: 'nuxt', label: 'Nuxt' },
    { id: 'vue', label: 'Vue.js' },
    { id: 'analog', label: 'Analog' },
    { id: 'angular', label: 'Angular' },
    { id: 'solidstart', label: 'SolidStart' },
    { id: 'solid', label: 'Solid' },
    { id: 'astro', label: 'Astro' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  node: [
    { id: 'hono', label: 'Hono' },
    { id: 'fastify', label: 'Fastify' },
    { id: 'nestjs', label: 'NestJS' },
    { id: 'express', label: 'Express' },
    { id: 'koa', label: 'Koa' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  bun: [
    { id: 'hono', label: 'Hono' },
    { id: 'elysia', label: 'ElysiaJS' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  // Single-framework platforms keep their snippet-specific id (for the code
  // lookups) but read "Vanilla" when the framework would otherwise just
  // repeat the platform name.
  flutter: [{ id: 'flutter', label: 'Vanilla' }],
  'react-native': [{ id: 'expo', label: 'Vanilla', icon: 'react-native' }],
  ionic: [{ id: 'ionic', label: 'Vanilla' }],
  capacitor: [{ id: 'capacitor', label: 'Vanilla' }],
  tauri: [{ id: 'tauri', label: 'Vanilla' }],
  // Android and Apple render these as a "Language" dropdown.
  apple: [{ id: 'swift', label: 'Swift' }],
  android: [
    { id: 'kotlin', label: 'Kotlin' },
    { id: 'java', label: 'Java' },
  ],
  python: [
    { id: 'fastapi', label: 'FastAPI' },
    { id: 'django', label: 'Django' },
    { id: 'flask', label: 'Flask' },
    { id: 'python', label: 'Vanilla' },
  ],
  dart: [
    { id: 'serverpod', label: 'Serverpod' },
    { id: 'frog', label: 'Dart Frog' },
    { id: 'dart', label: 'Vanilla' },
  ],
  php: [
    { id: 'laravel', label: 'Laravel' },
    { id: 'symfony', label: 'Symfony' },
    { id: 'php', label: 'Vanilla' },
  ],
  ruby: [
    { id: 'rails', label: 'Rails' },
    { id: 'ruby', label: 'Vanilla' },
  ],
  dotnet: [
    { id: 'minimal', label: 'Minimal API' },
    { id: 'controllers', label: 'Controllers' },
    { id: 'dotnet', label: 'Vanilla' },
  ],
  go: [
    { id: 'gin', label: 'Gin' },
    { id: 'echo', label: 'Echo' },
    { id: 'fiber', label: 'Fiber' },
    { id: 'go', label: 'Vanilla' },
  ],
  java: [
    { id: 'spring', label: 'Spring Boot' },
    { id: 'quarkus', label: 'Quarkus' },
    { id: 'java', label: 'Vanilla' },
  ],
  rust: [
    { id: 'axum', label: 'Axum' },
    { id: 'actix', label: 'Actix Web' },
    { id: 'rust', label: 'Vanilla' },
  ],
  swift: [
    { id: 'vapor', label: 'Vapor' },
    { id: 'swift', label: 'Vanilla' },
  ],
  kotlin: [
    { id: 'ktor', label: 'Ktor' },
    { id: 'spring', label: 'Spring Boot' },
    { id: 'kotlin', label: 'Vanilla' },
  ],
  deno: [
    { id: 'hono', label: 'Hono' },
    { id: 'fresh', label: 'Fresh' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
}

/**
 * "Using" variants per framework (e.g. React: Vite vs CRA; Next: App Router
 * vs Pages Router). Single-entry lists render as a disabled select showing
 * the implied tooling. Keyed by framework id, so the shared 'vanilla' id
 * (used by web, node, bun, deno) cannot declare one here.
 */
const USING_OPTIONS: Record<string, { id: string; label: string }[]> = {
  react: [
    { id: 'vite', label: 'Vite' },
    { id: 'cra', label: 'Create React App' },
  ],
  next: [
    { id: 'app', label: 'App Router' },
    { id: 'pages', label: 'Pages Router' },
  ],
  tanstack: [{ id: 'vite', label: 'Vite' }],
  sveltekit: [{ id: 'vite', label: 'Vite' }],
  svelte: [{ id: 'vite', label: 'Vite' }],
  nuxt: [{ id: 'vite', label: 'Vite' }],
  vue: [{ id: 'vite', label: 'Vite' }],
  analog: [{ id: 'vite', label: 'Vite' }],
  angular: [{ id: 'cli', label: 'Angular CLI' }],
  solidstart: [{ id: 'vite', label: 'Vite' }],
  solid: [{ id: 'vite', label: 'Vite' }],
  astro: [{ id: 'vite', label: 'Vite' }],
  ionic: [{ id: 'vite', label: 'Ionic React' }],
  capacitor: [{ id: 'vite', label: 'Vite' }],
  tauri: [{ id: 'vite', label: 'Vite' }],
}

/** Package manager options per SDK; null = not applicable (use all in install). */
const PACKAGE_MANAGER_OPTIONS: Record<
  string,
  { id: string; label: string }[] | null
> = {
  web: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  node: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  bun: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  'react-native': [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  deno: [
    { id: 'jsr', label: 'jsr' },
    { id: 'npm', label: 'npm' },
  ],
  flutter: [{ id: 'pub', label: 'pub' }],
  python: [{ id: 'pip', label: 'pip' }],
  dart: [{ id: 'pub', label: 'pub' }],
  php: [{ id: 'composer', label: 'Composer' }],
  ruby: [{ id: 'gem', label: 'gem' }],
  dotnet: [{ id: 'nuget', label: 'NuGet' }],
  go: [{ id: 'go', label: 'go get' }],
  apple: [{ id: 'spm', label: 'Swift Package Manager' }],
  swift: [{ id: 'spm', label: 'Swift Package Manager' }],
  android: [{ id: 'gradle', label: 'Gradle' }],
  kotlin: [{ id: 'gradle', label: 'Gradle' }],
  rust: [{ id: 'cargo', label: 'cargo' }],
  // Java intentionally has no single manager: Gradle and Maven are both
  // shown in the install instructions.
  java: null,
}

export type ConnectProjectTab =
  | 'app'
  | 'cli'
  | 'mcp'
  | 'skills'
  | 'terraform'
  | 's3'

const CONNECT_PROJECT_TAB_IDS = [
  'mcp',
  'app',
  'cli',
  'skills',
  'terraform',
  's3',
] as const satisfies readonly ConnectProjectTab[]

export const DEFAULT_CONNECT_PROJECT_TAB: ConnectProjectTab = 'mcp'

interface ConnectProjectProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Optional initial SDK to preselect (e.g. 'web', 'flutter') */
  initialSdk?: string
  /** Which top-level Connect tab to show when the dialog opens */
  initialConnectTab?: ConnectProjectTab
}

export function ConnectProject({
  open,
  onOpenChange,
  projectId,
  initialSdk = 'web',
  initialConnectTab = DEFAULT_CONNECT_PROJECT_TAB,
}: ConnectProjectProps) {
  const t = useT()
  const navigate = useNavigate()
  const { track } = useAnalytics()
  const [platformId, setPlatformId] = useState(() =>
    isKnownPlatform(initialSdk) ? initialSdk : 'web',
  )
  const [frameworkId, setFrameworkId] = useState('vanilla')
  const [usingId, setUsingId] = useState('vite')
  const [packageManagerId, setPackageManagerId] = useState('npm')

  // The platform resolves to a real SDK (Ionic/Capacitor/Tauri use the Web
  // SDK); snippets, install steps, and analytics all key off the SDK id.
  const sdkId =
    CLIENT_PLATFORM_OPTIONS.find((o) => o.id === platformId)?.sdkId ??
    platformId

  const frameworkOptions =
    FRAMEWORK_OPTIONS[platformId] ?? FRAMEWORK_OPTIONS.web
  // Android and Apple pick a language (Kotlin, Swift), not a framework.
  const isLanguagePlatform = platformId === 'android' || platformId === 'apple'
  const usingVariants = USING_OPTIONS[frameworkId]
  const packageManagers = PACKAGE_MANAGER_OPTIONS[sdkId]
  const isServer = SERVER_SDK_OPTIONS.some((o) => o.id === sdkId)
  const runtime = isServer ? ('server' as const) : ('client' as const)

  useEffect(() => {
    if (!open) return
    if (isKnownPlatform(initialSdk)) setPlatformId(initialSdk)
  }, [open, initialSdk])

  useEffect(() => {
    const nextFrameworks =
      FRAMEWORK_OPTIONS[platformId] ?? FRAMEWORK_OPTIONS.web
    setFrameworkId(nextFrameworks[0]?.id ?? 'vanilla')
  }, [platformId])

  useEffect(() => {
    setUsingId((prev) => {
      const variants = USING_OPTIONS[frameworkId]
      return variants?.some((v) => v.id === prev)
        ? prev
        : (variants?.[0]?.id ?? 'vite')
    })
  }, [frameworkId])

  useEffect(() => {
    setPackageManagerId(PACKAGE_MANAGER_OPTIONS[sdkId]?.[0]?.id ?? 'npm')
  }, [sdkId])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateKey(access, features)
  const createMutation = useCreateApiKey(projectId)
  const endpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const codeFiles = useMemo(
    () =>
      getCodeFiles(
        sdkId,
        frameworkId,
        usingId,
        runtime,
        endpoint ?? '',
        projectId ?? '',
        packageManagerId,
      ),
    [
      sdkId,
      frameworkId,
      usingId,
      runtime,
      endpoint,
      projectId,
      packageManagerId,
    ],
  )
  const [connectTab, setConnectTab] = useState<ConnectProjectTab>(
    DEFAULT_CONNECT_PROJECT_TAB,
  )
  const { account } = useAuth()
  const { setTab: persistConnectTab } = useConnectProjectTab(account)

  const selectConnectTab = useCallback(
    (tab: ConnectProjectTab) => {
      setConnectTab(tab)
      persistConnectTab(tab)
    },
    [persistConnectTab],
  )

  useEffect(() => {
    if (!open) return
    setConnectTab(initialConnectTab)
  }, [open, initialConnectTab])

  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [copiedSkillsPrompt, setCopiedSkillsPrompt] = useState<string | null>(
    null,
  )
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [copiedPrompt, setCopiedPrompt] = useState(false)
  useEffect(() => {
    setSelectedFileIndex(0)
  }, [sdkId, frameworkId, usingId, runtime])
  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]
  const codeFileTabs = useMemo(
    () =>
      codeFiles.map((file, index) => ({
        id: String(index),
        label: file.label,
      })),
    [codeFiles],
  )
  const installInstructions = useMemo(
    () => getInstallInstructions(sdkId, packageManagerId),
    [sdkId, packageManagerId],
  )

  // Label by platform, not SDK: the prompt should say "Ionic", not "Web".
  const sdkLabel =
    [...CLIENT_PLATFORM_OPTIONS, ...SERVER_SDK_OPTIONS].find(
      (o) => o.id === platformId,
    )?.label ?? platformId
  const frameworkLabel = frameworkOptions.find(
    (fw) => fw.id === frameworkId,
  )?.label
  const usingLabel = usingVariants?.find((v) => v.id === usingId)?.label
  const packageManagerLabel = packageManagers?.find(
    (pm) => pm.id === packageManagerId,
  )?.label

  const connectSdkPrompt = useMemo(
    () =>
      buildConnectSdkPrompt({
        projectId,
        projectName: project?.name,
        endpoint: endpoint ?? getBaseEndpoint(),
        sdkLabel,
        runtime,
        frameworkLabel: frameworkOptions.length > 0 ? frameworkLabel : undefined,
        usingLabel:
          usingVariants && usingVariants.length > 0 ? usingLabel : undefined,
        packageManagerLabel:
          packageManagers && packageManagers.length > 0
            ? packageManagerLabel
            : undefined,
        installTitle: installInstructions.title,
        installOptions: installInstructions.options,
        codeFiles,
      }),
    [
      projectId,
      project?.name,
      endpoint,
      sdkLabel,
      runtime,
      frameworkOptions.length,
      frameworkLabel,
      usingVariants,
      usingLabel,
      packageManagers,
      packageManagerLabel,
      installInstructions,
      codeFiles,
    ],
  )

  const handleCopyConnectPrompt = async () => {
    try {
      await navigator.clipboard.writeText(connectSdkPrompt)
      track(ANALYTICS_ACTIONS['copy-connect-sdk-prompt'], {
        platform: sdkId,
      })
      setCopiedPrompt(true)
      toast.success(t('Prompt copied to clipboard'))
      setTimeout(() => setCopiedPrompt(false), 2000)
    } catch {
      toast.error(t('Failed to copy prompt'))
    }
  }

  const handleViewApiKeys = () => {
    onOpenChange(false)
    navigate({ to: '/projects/$projectId/api-keys', params: { projectId } })
  }

  const handleCopyKey = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  const handleCreateApiKey = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    createMutation.mutate(data, {
      onSuccess: (createdKey) => {
        toast.success(t('API key created successfully'))
        if (createdKey?.secret) {
          setCreatedKeySecret(createdKey.secret)
        } else {
          setCreateDrawerOpen(false)
        }
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to create API key'))
      },
    })
  }

  if (!project) return null

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-6xl h-[70dvh] max-h-[70dvh] p-0 gap-0 flex flex-col overflow-hidden">
          <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Connect to your project')}</DialogTitle>
          </DialogHeader>
          <Tabs
            value={connectTab}
            onValueChange={(v) => selectConnectTab(v as ConnectProjectTab)}
            className="min-h-0 flex-1 flex flex-col overflow-hidden"
          >
            <div
              className="shrink-0 flex gap-0 overflow-x-auto border-b border-border px-6"
              role="tablist"
            >
              {CONNECT_PROJECT_TAB_IDS.map((tabId) => {
                const isActive = connectTab === tabId
                const label =
                  tabId === 'app'
                    ? 'SDK'
                    : tabId === 'cli'
                      ? 'CLI'
                      : tabId === 'mcp'
                        ? 'MCP'
                        : tabId === 'skills'
                          ? 'Skills'
                          : tabId === 'terraform'
                            ? 'Terraform'
                            : 'S3'
                return (
                  <button
                    key={tabId}
                    type="button"
                    role="tab"
                    aria-selected={isActive}
                    onClick={() => selectConnectTab(tabId)}
                    className={cn(
                      'relative flex shrink-0 cursor-pointer focus:cursor-pointer focus-visible:cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                      isActive
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground/80',
                    )}
                  >
                    {label}
                    {isActive && (
                      <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
                    )}
                  </button>
                )
              })}
            </div>
            <TabsContent
              value="app"
              className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
            >
              {/* Selectors above grid: SDK (Client + Server), Framework, Package manager */}
              <div className="shrink-0 flex flex-wrap items-end gap-4 pt-4 pb-4 border-b border-border">
                <div className="min-w-[160px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    {t('SDK / Platform')}
                  </label>
                  <Select value={platformId} onValueChange={setPlatformId}>
                    <SelectTrigger className="w-full h-9 text-[13px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup>
                        <SelectLabel className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Client')}
                        </SelectLabel>
                        {CLIENT_PLATFORM_OPTIONS.map((opt) => (
                          <SelectItem
                            key={opt.id}
                            value={opt.id}
                            className="text-[13px]"
                          >
                            <span className="flex items-center gap-1.5">
                              {opt.sdkId ? (
                                <FrameworkIcon framework={opt.id} size="sm" />
                              ) : (
                                <PlatformIcon platform={opt.id} size="sm" />
                              )}
                              {opt.label}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectGroup>
                      <SelectGroup>
                        <SelectLabel className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Server')}
                        </SelectLabel>
                        {SERVER_SDK_OPTIONS.map((opt) => (
                          <SelectItem
                            key={opt.id}
                            value={opt.id}
                            className="text-[13px]"
                          >
                            <span className="flex items-center gap-1.5">
                              <FrameworkIcon framework={opt.id} size="sm" />
                              {opt.label}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                </div>
                {frameworkOptions.length > 0 && (
                  <div className="min-w-[120px]">
                    <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                      {isLanguagePlatform ? t('Language') : t('Framework')}
                    </label>
                    <Select
                      value={frameworkId}
                      onValueChange={setFrameworkId}
                      disabled={frameworkOptions.length === 1}
                    >
                      <SelectTrigger className="w-full h-9 text-[13px]">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {frameworkOptions.map((fw) => (
                          <SelectItem
                            key={fw.id}
                            value={fw.id}
                            className="text-[13px]"
                          >
                            <span className="flex items-center gap-1.5">
                              <FrameworkIcon
                                framework={fw.icon ?? fw.id}
                                size="sm"
                              />
                              {fw.label}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
                {usingVariants && usingVariants.length > 0 && (
                  <div className="min-w-[140px]">
                    <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                      {t('Using')}
                    </label>
                    <Select
                      value={usingId}
                      onValueChange={setUsingId}
                      disabled={usingVariants.length === 1}
                    >
                      <SelectTrigger className="w-full h-9 text-[13px]">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {usingVariants.map((v) => (
                          <SelectItem
                            key={v.id}
                            value={v.id}
                            className="text-[13px]"
                          >
                            {v.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
                {packageManagers && packageManagers.length > 0 && (
                  <div className="min-w-[100px]">
                    <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                      {t('Package manager')}
                    </label>
                    <Select
                      value={packageManagerId}
                      onValueChange={setPackageManagerId}
                      disabled={packageManagers.length === 1}
                    >
                      <SelectTrigger className="w-full h-9 text-[13px]">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {packageManagers.map((pm) => (
                          <SelectItem
                            key={pm.id}
                            value={pm.id}
                            className="text-[13px]"
                          >
                            <span className="flex items-center gap-1.5">
                              <PackageManagerIcon
                                packageManager={pm.id}
                                size="sm"
                              />
                              {pm.label}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
                <div className="ms-auto flex shrink-0 items-end self-end">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 gap-1.5 text-[12px]"
                    data-analytics-track="manual"
                    onClick={() => void handleCopyConnectPrompt()}
                  >
                    {copiedPrompt ? (
                      <Check className="h-3.5 w-3.5" />
                    ) : (
                      <Copy className="h-3.5 w-3.5" />
                    )}
                    {t('Copy prompt')}
                  </Button>
                </div>
              </div>

              <div className="grid grid-cols-[0.9fr_1.4fr] gap-6 pt-4 min-h-0 flex-1">
                {/* Left: install instructions + API keys when server */}
                <div className="space-y-4 min-w-0 min-h-0 overflow-y-auto">
                  <div className="space-y-4">
                    <h4 className="text-[13px] font-semibold text-foreground">
                      {t(installInstructions.title)}
                    </h4>
                    <div className="space-y-4">
                      {installInstructions.options.map((option, i) => (
                        <CodeBlock
                          key={i}
                          code={option.code}
                          language={option.language ?? 'plaintext'}
                          label={t(option.label)}
                          showCopy={true}
                        />
                      ))}
                    </div>
                  </div>
                  {isServer && (
                    <div className="rounded-xl border border-border bg-muted/30 overflow-hidden">
                      <div className="px-4 py-3 border-b border-border">
                        <h4 className="text-[13px] font-semibold text-foreground">
                          {t('API keys')}
                        </h4>
                      </div>
                      <div className="px-4 py-3 space-y-3">
                        <p className="text-[13px] text-muted-foreground">
                          {t(
                            'Server and backend code need an API key with the right scopes. Create and manage keys in your project.',
                          )}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                          <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="h-8 gap-1.5 text-[12px]"
                            onClick={() => setCreateDrawerOpen(true)}
                            disabled={noCreatePermission}
                            title={
                              noCreatePermission
                                ? t(
                                    "You don't have permission to create API keys.",
                                  )
                                : undefined
                            }
                            {...analyticsAttrs('create-api-key')}
                          >
                            <Plus className="h-3.5 w-3.5" />
                            {t('Create API key')}
                          </Button>
                          <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="h-8 gap-1.5 text-[12px]"
                            onClick={handleViewApiKeys}
                          >
                            <Key className="h-3.5 w-3.5" />
                            {t('View API keys')}
                          </Button>
                        </div>
                        <DocsRouteLink
                          href={`${APPWRITE_DOCS_URL}/getting-started-for-server`}
                          className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                        >
                          {t('Server setup guide')}
                          <ExternalLink className="h-3.5 w-3.5" />
                        </DocsRouteLink>
                      </div>
                    </div>
                  )}
                  <DocsRouteLink
                    href={APPWRITE_DOCS_URL}
                    className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                  >
                    {t('Read the docs')}
                    <ExternalLink className="h-3.5 w-3.5" />
                  </DocsRouteLink>
                </div>
                {/* Right: File-based code examples */}
                <div className="min-w-0 min-h-0 flex flex-col flex-1">
                  {selectedFile && (
                    <ConnectCodeExample
                      code={selectedFile.code}
                      language={selectedFile.language ?? 'plaintext'}
                      tabs={codeFileTabs}
                      activeTabId={String(selectedFileIndex)}
                      onTabChange={(id) => setSelectedFileIndex(Number(id))}
                      selectorAriaLabel={t('Select file')}
                      fixedHeight="100%"
                      className="flex-1 min-h-0"
                    />
                  )}
                </div>
              </div>
            </TabsContent>
            <TabsContent
              value="cli"
              className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
            >
              <CLISection
                endpoint={endpoint ?? getBaseEndpoint()}
                projectId={projectId ?? ''}
                onViewApiKeys={handleViewApiKeys}
                onClose={() => onOpenChange(false)}
              />
            </TabsContent>
            <TabsContent
              value="mcp"
              className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden"
            >
              <MCPSection
                compact
                projectId={projectId}
                projectName={project?.name ?? projectId}
              />
            </TabsContent>
            <TabsContent
              value="skills"
              className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
            >
              <div className="flex flex-col gap-4 pt-4 min-h-0 flex-1">
                <div className="shrink-0 space-y-2">
                  <p className="text-[13px] text-muted-foreground">
                    {t(
                      'SDK context for your AI agent: accurate methods, patterns, and best practices. For live project actions like listing users, use MCP.',
                    )}
                  </p>
                  <p className="text-[13px] text-muted-foreground">
                    {t('Skills are available for')}{' '}
                    {[
                      'CLI',
                      'TypeScript',
                      'Dart',
                      '.NET',
                      'Go',
                      'Kotlin',
                      'PHP',
                      'Python',
                      'Ruby',
                      'Swift',
                    ].map((sdk, i) => (
                      <span key={sdk}>
                        <span className="rounded bg-muted/80 px-1.5 py-0.5 text-[12px] font-medium text-muted-foreground">
                          {sdk}
                        </span>
                        {i < 9 ? ', ' : ''}
                      </span>
                    ))}{' '}
                    - {t('pick what you use during setup.')}
                  </p>
                  <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <DocsRouteLink
                      href={APPWRITE_SKILLS_DOCS_URL}
                      className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                    >
                      {t('Docs')}
                      <ExternalLink className="h-3.5 w-3.5" />
                    </DocsRouteLink>
                    <a
                      href={APPWRITE_AGENT_SKILLS_REPO}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                    >
                      <GitHubIcon className="h-3.5 w-3.5" />
                      appwrite/skills
                    </a>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4 min-h-0 flex-1">
                  <div className="min-w-0 rounded-xl border border-border bg-card/50 overflow-hidden flex flex-col">
                    <div className="px-4 py-2.5 border-b border-border shrink-0">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        {t('1. Install')}
                      </h4>
                      <p className="text-[12px] text-muted-foreground mt-0.5">
                        {t('Run in project root.')}
                      </p>
                    </div>
                    <div className="px-4 py-3 shrink-0">
                      <CodeBlock
                        code={APPWRITE_AGENT_SKILLS_INSTALL}
                        language="bash"
                        label={t('Terminal')}
                        showCopy={true}
                      />
                    </div>
                    <div className="px-4 py-2.5 border-t border-border">
                      <p className="text-[12px] font-medium text-foreground mb-1">
                        {t('Then the CLI will ask:')}
                      </p>
                      <ul className="text-[12px] text-muted-foreground space-y-0.5">
                        <li>
                          <span className="text-foreground font-medium">
                            {t('Skills')}
                          </span>{' '}
                          - {t('which SDKs to install (e.g. TypeScript, Go).')}
                        </li>
                        <li>
                          <span className="text-foreground font-medium">
                            {t('Tools')}
                          </span>{' '}
                          -{' '}
                          {t('which AI tools use them (Cursor, Claude, etc.).')}
                        </li>
                        <li>
                          <span className="text-foreground font-medium">
                            {t('Scope')}
                          </span>{' '}
                          - {t('project (this repo) or global.')}
                        </li>
                        <li>
                          <span className="text-foreground font-medium">
                            {t('Method')}
                          </span>{' '}
                          - {t('prefer symlink so skills stay up to date.')}
                        </li>
                      </ul>
                    </div>
                  </div>

                  <div className="min-w-0 flex flex-col gap-2.5 min-h-0">
                    <div className="shrink-0">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        {t('2. Try it')}
                      </h4>
                      <p className="text-[12px] text-muted-foreground mt-0.5">
                        {t('Ask your agent to write Appwrite code:')}
                      </p>
                    </div>
                    <ul className="space-y-1.5 min-h-0">
                      {SKILLS_TRY_IT_PROMPTS.map((prompt) => (
                        <li
                          key={prompt}
                          className="flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-1.5"
                        >
                          <span className="min-w-0 flex-1 text-[12px] font-medium text-foreground leading-snug">
                            {t(prompt)}
                          </span>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
                            onClick={() => {
                              navigator.clipboard.writeText(prompt)
                              setCopiedSkillsPrompt(prompt)
                              toast.success(t('Copied to clipboard'))
                              setTimeout(
                                () => setCopiedSkillsPrompt(null),
                                2000,
                              )
                            }}
                          >
                            {copiedSkillsPrompt === prompt ? (
                              <Check className="h-3.5 w-3.5" />
                            ) : (
                              <Copy className="h-3.5 w-3.5" />
                            )}
                            {t('Copy')}
                          </Button>
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
              </div>
            </TabsContent>
            <TabsContent
              value="terraform"
              className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
            >
              <TerraformConnectSection
                endpoint={endpoint ?? getBaseEndpoint()}
                projectId={projectId ?? ''}
                onViewApiKeys={handleViewApiKeys}
              />
            </TabsContent>
            <TabsContent
              value="s3"
              className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
            >
              <S3ConnectSection
                projectId={projectId ?? ''}
                onViewApiKeys={handleViewApiKeys}
              />
            </TabsContent>
          </Tabs>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenChange(false)}
            >
              {t('Close')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <ApiKeyDrawer
        open={createDrawerOpen}
        onOpenChange={(nextOpen) => {
          setCreateDrawerOpen(nextOpen)
          if (!nextOpen) setCreatedKeySecret(null)
        }}
        onSubmit={handleCreateApiKey}
        isLoading={createMutation.isPending}
        createdKeySecret={createdKeySecret}
        onCopy={handleCopyKey}
        copiedField={copiedField}
        initialName={t(SDK_API_KEY_DEFAULT_NAME)}
      />
    </>
  )
}
