import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  BrainCircuit,
  Check,
  ChevronDown,
  Copy,
  ExternalLink,
  Loader2,
} from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import {
  useProject,
  useCreatePlatform,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { canCreatePlatform } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getAIChatIDEs,
  generateAIChatDeeplink,
  openAIChatDeeplink,
  type IDEConfig,
} from '@/lib/config/ide'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { PROJECT_CHANNELS } from '@/lib/realtime/constants'
import type { AddAppKind, WebFrameworkKey } from '@/lib/add-app-wizard/types'
import {
  ADD_APP_KINDS,
  WEB_FRAMEWORK_KEYS,
} from '@/lib/add-app-wizard/types'
import {
  APPLE_VARIANT_OPTIONS,
  FLUTTER_VARIANT_OPTIONS,
  REACT_NATIVE_VARIANT_OPTIONS,
  WEB_FRAMEWORK_META,
  defaultVariantForKind,
  isValidHostname,
  isValidKeyForVariant,
  variantNeedsHostname,
  variantNeedsKey,
  getWebStarterRepoName,
} from '@/lib/add-app-wizard/platform-map'
import {
  buildNativePromptConfig,
  buildWebPromptConfig,
  generatePromptFromConfig,
} from '@/lib/add-app-wizard/prompts'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  WizardProgress,
  type WizardStage,
} from './_components/WizardProgress'
import { ConfigureWizardAside } from './_components/ConfigureWizardAside'
import { ConnectionAside } from './_components/ConnectionAside'
import { FieldLabelWithInfo } from './_components/FieldLabelWithInfo'
import {
  getHostnameTooltip,
  getKeyTooltip,
  getNameTooltip,
} from './_components/field-hints'
import { PlatformKindCards } from './_components/PlatformKindCards'
import { SelectedPlatformCard } from './_components/SelectedPlatformCard'
import { SetupStep } from './_components/SetupStep'
import { VariantTargetCards } from './_components/VariantTargetCards'
import { WebFrameworkCards } from './_components/WebFrameworkCards'

export type AddAppSearchState = {
  step?: 'configure' | 'setup'
  kind?: AddAppKind
  variant?: string
  framework?: WebFrameworkKey
  platformId?: string
  /** Step within configure: pick platform vs app details form */
  configureStep?: 'platform' | 'details'
}

type ViewProps = {
  projectId: string
  search: AddAppSearchState
}

function normalizeKind(k: string | undefined): AddAppKind {
  if (k && (ADD_APP_KINDS as readonly string[]).includes(k)) {
    return k as AddAppKind
  }
  return 'web'
}

function normalizeFramework(f: string | undefined): WebFrameworkKey {
  if (f && (WEB_FRAMEWORK_KEYS as readonly string[]).includes(f)) {
    return f as WebFrameworkKey
  }
  return 'react'
}

function normalizeConfigureStep(
  raw: string | undefined,
  configurePhase: 'configure' | 'setup',
): 'platform' | 'details' {
  if (configurePhase === 'setup') return 'details'
  return raw === 'details' ? 'details' : 'platform'
}

export function View({ projectId, search }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const step = search.step ?? 'configure'
  const kind = normalizeKind(search.kind)
  const framework = normalizeFramework(search.framework)
  const configureStep = normalizeConfigureStep(
    search.configureStep,
    step,
  )

  const resolvedVariant = useMemo(() => {
    if (search.variant?.trim()) return search.variant.trim()
    return defaultVariantForKind(kind)
  }, [kind, search.variant])

  const wizardStage: WizardStage = useMemo(() => {
    if (step === 'setup') return 'setup'
    return configureStep === 'details' ? 'details' : 'platform'
  }, [step, configureStep])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const mayCreate = canCreatePlatform(access, features)

  // Default name pre-fill helps devs move faster; updates with the platform until
  // the user manually edits the field.
  const defaultPlatformName = useMemo(() => {
    if (resolvedVariant === 'web') {
      return `My ${WEB_FRAMEWORK_META[framework].label} app`
    }
    return `My ${getPlatformDisplayName(resolvedVariant)} app`
  }, [resolvedVariant, framework])

  const [name, setName] = useState(defaultPlatformName)
  const [nameTouched, setNameTouched] = useState(false)
  const [hostname, setHostname] = useState('localhost')
  const [key, setKey] = useState('')
  const [pingReceived, setPingReceived] = useState(false)
  const [nameError, setNameError] = useState<string | null>(null)
  const [hostnameError, setHostnameError] = useState<string | null>(null)
  const [keyError, setKeyError] = useState<string | null>(null)
  const [createError, setCreateError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const copiedTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Keep the name field in sync with the chosen platform until the user edits it.
  useEffect(() => {
    if (!nameTouched) {
      setName(defaultPlatformName)
    }
  }, [defaultPlatformName, nameTouched])

  useEffect(() => {
    return () => {
      if (copiedTimeoutRef.current) clearTimeout(copiedTimeoutRef.current)
    }
  }, [])

  const createMutation = useCreatePlatform(projectId)

  useEffect(() => {
    if (!mayCreate) {
      navigate({
        to: '/projects/$projectId/apps',
        params: { projectId },
        replace: true,
      })
    }
  }, [mayCreate, navigate, projectId])

  useEffect(() => {
    if (step === 'setup' && !search.platformId) {
      navigate({
        to: '/projects/$projectId/apps/add',
        params: { projectId },
        search: {
          step: 'configure',
          kind,
          variant: search.variant,
          framework: search.framework,
          configureStep: 'platform',
        },
        replace: true,
      })
    }
  }, [
    step,
    search.platformId,
    navigate,
    projectId,
    kind,
    search.variant,
    search.framework,
  ])

  useEffect(() => {
    if (step !== 'setup' || !projectId) return
    let cancelled = false
    let cleanup: (() => Promise<void>) | undefined
    void (async () => {
      const unregister = await registerConsoleRealtimeListener(
        [...PROJECT_CHANNELS],
        (response) => {
          if (cancelled) return
          const events = response.events ?? []
          if (events.includes(`projects.${projectId}.ping`)) {
            setPingReceived(true)
          }
        },
      )
      cleanup = unregister
    })()
    return () => {
      cancelled = true
      void cleanup?.()
    }
  }, [step, projectId])

  const endpoint = useMemo(
    () => getProjectApiEndpoint(projectId),
    [projectId],
  )

  function updateSearch(patch: Partial<AddAppSearchState>) {
    navigate({
      to: '/projects/$projectId/apps/add',
      params: { projectId },
      search: {
        step: search.step,
        kind: search.kind,
        variant: search.variant,
        framework: search.framework,
        platformId: search.platformId,
        configureStep: search.configureStep,
        ...patch,
      },
      replace: true,
    })
  }

  const promptText = useMemo(() => {
    if (!project?.$id) return ''
    if (resolvedVariant === 'web') {
      const cfg = buildWebPromptConfig({
        framework,
        projectId: project.$id,
        projectName: project.name ?? '',
        endpoint,
      })
      return generatePromptFromConfig(cfg)
    }
    const configCode = `APPWRITE_PROJECT_ID="${project.$id}"
APPWRITE_PROJECT_NAME="${project.name ?? ''}"
APPWRITE_ENDPOINT="${endpoint}"`
    const cfg = buildNativePromptConfig(
      resolvedVariant,
      configCode,
      `Install the Appwrite SDK for this target. Configure the client with the endpoint and project ID above. Add a control that calls client.ping() so the user can verify connectivity.`,
    )
    return generatePromptFromConfig(cfg)
  }, [resolvedVariant, framework, project, endpoint])

  const nativeEnvBlock = useMemo(
    () =>
      `APPWRITE_PROJECT_ID="${project?.$id ?? ''}"
APPWRITE_PROJECT_NAME="${project?.name ?? ''}"
APPWRITE_ENDPOINT="${endpoint}"`,
    [project, endpoint],
  )

  const manualBlocks = useMemo(() => {
    if (resolvedVariant === 'web') {
      const meta = WEB_FRAMEWORK_META[framework]
      const repo = getWebStarterRepoName(framework)
      const starter = `git clone https://github.com/appwrite/${repo}
cd ${repo}`
      const env =
        framework === 'angular'
          ? `export const environment = {
  appwriteEndpoint: '${endpoint}',
  appwriteProjectId: '${project?.$id ?? ''}',
  appwriteProjectName: '${project?.name ?? ''}',
};`
          : `APPWRITE_PROJECT_ID="${project?.$id ?? ''}"
APPWRITE_PROJECT_NAME="${project?.name ?? ''}"
APPWRITE_ENDPOINT="${endpoint}"`
      return {
        clone: starter,
        cloneLang: 'bash' as const,
        configLabel:
          framework === 'angular'
            ? 'Update src/environments/environment.ts'
            : 'Copy .env.example to .env and set values',
        config: env,
        configLang: framework === 'angular' ? ('typescript' as const) : ('env' as const),
        installRun: `pnpm install && ${meta.runCommand}`,
        port: meta.port,
      }
    }
    const native = buildNativePromptConfig(
      resolvedVariant,
      nativeEnvBlock,
      `Install the Appwrite SDK for this target and wire endpoint + project ID.`,
    )
    const lang: CodeBlockLanguage =
      native.configLanguage === 'kotlin'
        ? 'kotlin'
        : native.configLanguage === 'dart'
          ? 'dart'
          : native.configLanguage === 'typescript'
            ? 'typescript'
            : 'plaintext'
    const installRun = resolvedVariant.startsWith('flutter')
      ? 'flutter pub get && flutter run'
      : resolvedVariant === 'react-native-ios'
        ? 'pnpm install && pnpm ios'
        : resolvedVariant === 'react-native-android'
          ? 'pnpm install && pnpm android'
          : 'Run from your IDE'
    return {
      clone: native.cloneCommand,
      cloneLang: 'bash' as const,
      configLabel: `Update ${native.configFile}`,
      config: nativeEnvBlock,
      configLang: lang,
      installRun,
      port: resolvedVariant === 'flutter-web' ? 8080 : 0,
    }
  }, [resolvedVariant, framework, endpoint, project, nativeEnvBlock])

  const asidePlatformSlug = resolvedVariant === 'web' ? 'web' : resolvedVariant

  const handleKindChange = (next: AddAppKind) => {
    const v = defaultVariantForKind(next)
    updateSearch({
      kind: next,
      variant: v,
      step: 'configure',
      platformId: undefined,
      configureStep: 'platform',
    })
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!mayCreate || !project?.$id) return

    const trimmedName = name.trim()
    const nextNameError = trimmedName ? null : t('Enter an app name')
    const nextHostnameError =
      variantNeedsHostname(resolvedVariant) && !isValidHostname(hostname)
        ? t('Enter a valid hostname (e.g. localhost or app.example.com)')
        : null
    const nextKeyError =
      variantNeedsKey(resolvedVariant) &&
      !isValidKeyForVariant(resolvedVariant, key)
        ? resolvedVariant.includes('apple')
          ? t('Enter a valid bundle ID (e.g. com.example.app)')
          : t('Enter a valid package name (e.g. com.example.app)')
        : null

    setNameError(nextNameError)
    setHostnameError(nextHostnameError)
    setKeyError(nextKeyError)
    setCreateError(null)

    if (nextNameError || nextHostnameError || nextKeyError) {
      return
    }

    createMutation.mutate(
      {
        variant: resolvedVariant,
        name: trimmedName,
        hostname: variantNeedsHostname(resolvedVariant)
          ? hostname.trim()
          : undefined,
        key: variantNeedsKey(resolvedVariant) ? key.trim() : undefined,
      },
      {
        onSuccess: (created) => {
          navigate({
            to: '/projects/$projectId/apps/add',
            params: { projectId },
            search: {
              step: 'setup',
              kind,
              variant: resolvedVariant,
              framework,
              platformId: created.$id,
            },
            replace: true,
          })
        },
        onError: (err: Error) => {
          setCreateError(getErrorMessage(err) || t('Failed to register app'))
        },
      },
    )
  }

  // IDEs that support an AI-chat deeplink. Mirrors the deployment "Fix with AI"
  // dropdown so devs can hand the prompt off to their preferred tool.
  const aiChatIDEs = useMemo(() => getAIChatIDEs(), [])

  const handleOpenInIDE = (ide: IDEConfig) => {
    if (!promptText) return
    const deeplink = generateAIChatDeeplink(ide, promptText)
    if (deeplink) {
      openAIChatDeeplink(deeplink)
    }
  }

  const handleCopyPrompt = async () => {
    if (!promptText) return
    try {
      await navigator.clipboard.writeText(promptText)
      setCopied(true)
      if (copiedTimeoutRef.current) clearTimeout(copiedTimeoutRef.current)
      copiedTimeoutRef.current = setTimeout(() => setCopied(false), 2000)
    } catch {
      // Silent: surfaced inline via the button label staying as "Copy prompt"
    }
  }

  const variantTargetSection =
    kind === 'flutter' ? (
      <section className="space-y-3">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">{t('Choose target')}</h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('Where this Flutter app runs.')}
          </p>
        </div>
        <VariantTargetCards
          options={FLUTTER_VARIANT_OPTIONS}
          value={resolvedVariant}
          onChange={(v) =>
            updateSearch({ variant: v, step: 'configure', platformId: undefined })
          }
          disabled={createMutation.isPending}
        />
      </section>
    ) : kind === 'apple' ? (
      <section className="space-y-3">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">{t('Choose target')}</h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('Which Apple platform you are building for.')}
          </p>
        </div>
        <VariantTargetCards
          options={APPLE_VARIANT_OPTIONS}
          value={resolvedVariant}
          onChange={(v) =>
            updateSearch({ variant: v, step: 'configure', platformId: undefined })
          }
          disabled={createMutation.isPending}
        />
      </section>
    ) : kind === 'react-native' ? (
      <section className="space-y-3">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">{t('Choose target')}</h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('Android or iOS for this React Native app.')}
          </p>
        </div>
        <VariantTargetCards
          options={REACT_NATIVE_VARIANT_OPTIONS}
          value={resolvedVariant}
          onChange={(v) =>
            updateSearch({ variant: v, step: 'configure', platformId: undefined })
          }
          disabled={createMutation.isPending}
        />
      </section>
    ) : null

  const useWizardSidebar =
    step === 'setup' ||
    (step === 'configure' && configureStep === 'platform')

  const sidebar =
    step === 'setup' ? (
      <ConnectionAside
        webFramework={resolvedVariant === 'web' ? framework : null}
        platformSlug={asidePlatformSlug}
        pingReceived={pingReceived}
      />
    ) : step === 'configure' && configureStep === 'platform' ? (
      <ConfigureWizardAside />
    ) : null

  return (
    <WizardLayout
      title={t('Connect your app')}
      headerBottom={<WizardProgress stage={wizardStage} />}
      fallbackPath={`/projects/${projectId}/apps`}
      fullscreen
      useSidebar={useWizardSidebar}
      sidebar={sidebar}
      constrainWidth
      maxWidth="max-w-7xl"
      footerAlign="right"
      footer={
        step === 'configure' && configureStep === 'platform' ? (
          <Button
            key="wizard-step-platform"
            type="button"
            onClick={() => {
              // Defer navigation to the next animation frame so the click event
              // fully completes before the footer button swaps to type="submit".
              // Without this, the mouseup that lands after the swap can submit
              // the freshly mounted form on step 2.
              requestAnimationFrame(() =>
                updateSearch({
                  configureStep: 'details',
                  kind,
                  variant: resolvedVariant,
                  framework,
                }),
              )
            }}
          >
            {t('Continue')}
          </Button>
        ) : step === 'configure' && configureStep === 'details' ? (
          <Button
            key="wizard-step-details"
            type="submit"
            form="add-app-configure"
            disabled={createMutation.isPending}
          >
            {t('Register and continue')}
          </Button>
        ) : (
          <>
            <Button
              key="wizard-step-setup-add-another"
              type="button"
              variant="outline"
              onClick={() =>
                navigate({
                  to: '/projects/$projectId/apps/add',
                  params: { projectId },
                  search: {
                    step: 'configure',
                    kind,
                    variant: resolvedVariant,
                    framework,
                    configureStep: 'platform',
                  },
                  replace: true,
                })
              }
            >
              {t('Add another app')}
            </Button>
            <Button
              key="wizard-step-setup-done"
              type="button"
              onClick={() =>
                navigate({ to: '/projects/$projectId/apps', params: { projectId } })
              }
            >
              {t('Done')}
            </Button>
          </>
        )
      }
    >
      {step === 'configure' && configureStep === 'platform' ? (
        <div className="w-full space-y-8">
          <section className="space-y-3">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Choose your platform')}
              </h3>
              <p className="mt-1 text-[13px] text-muted-foreground">
                {t('Web, mobile, or desktop - pick what matches your project.')}
              </p>
            </div>
            <PlatformKindCards
              value={kind}
              onChange={handleKindChange}
              disabled={createMutation.isPending}
            />
          </section>

          {kind === 'web' ? (
            <>
              <div className="border-t border-border" />
              <section className="space-y-3">
                <div>
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('Choose a web framework')}
                  </h3>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    {t(
                      'We match starters and AI prompts to the framework you pick.',
                    )}
                  </p>
                </div>
                <WebFrameworkCards
                  value={framework}
                  onChange={(k) =>
                    updateSearch({
                      framework: k,
                      step: 'configure',
                      platformId: undefined,
                      configureStep: 'platform',
                    })
                  }
                  disabled={createMutation.isPending}
                />
              </section>
            </>
          ) : null}

          {variantTargetSection ? (
            <>
              <div className="border-t border-border" />
              {variantTargetSection}
            </>
          ) : null}
        </div>
      ) : step === 'configure' && configureStep === 'details' ? (
        <div className="mx-auto w-full max-w-2xl space-y-6">
          <SelectedPlatformCard
            kind={kind}
            variant={resolvedVariant}
            framework={framework}
            disabled={createMutation.isPending}
            onChange={() => updateSearch({ configureStep: 'platform' })}
          />
          <form id="add-app-configure" onSubmit={handleSubmit} className="space-y-8">
            <section className="space-y-4">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('App details')}
                </h3>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  {t(
                    'These values are sent to Appwrite when you register this app.', // pragma: allowlist secret
                  )}
                </p>
              </div>

              <div className="space-y-2">
                <FieldLabelWithInfo
                  htmlFor="add-app-name"
                  required
                  tooltip={getNameTooltip()}
                >
                  {t('Name')}
                </FieldLabelWithInfo>
                <Input
                  id="add-app-name"
                  value={name}
                  onChange={(e) => {
                    setName(e.target.value)
                    if (!nameTouched) setNameTouched(true)
                    if (nameError) setNameError(null)
                  }}
                  placeholder={defaultPlatformName}
                  autoComplete="off"
                  aria-invalid={nameError ? true : undefined}
                />
                {nameError && (
                  <p className="text-[12px] text-destructive">{nameError}</p>
                )}
              </div>

              {variantNeedsHostname(resolvedVariant) && (
                <div className="space-y-2">
                  <FieldLabelWithInfo
                    htmlFor="add-app-hostname"
                    required
                    tooltip={getHostnameTooltip(resolvedVariant)}
                  >
                    {t('Hostname')}
                  </FieldLabelWithInfo>
                  <Input
                    id="add-app-hostname"
                    value={hostname}
                    onChange={(e) => {
                      setHostname(e.target.value)
                      if (hostnameError) setHostnameError(null)
                    }}
                    placeholder="localhost"
                    autoComplete="off"
                    aria-invalid={hostnameError ? true : undefined}
                  />
                  {hostnameError ? (
                    <p className="text-[12px] text-destructive">{hostnameError}</p>
                  ) : (
                    <p className="text-[12px] text-muted-foreground">
                      {t(
                        'Origin your app will call Appwrite from (no protocol or port). Use localhost for local development.', // pragma: allowlist secret
                      )}
                    </p>
                  )}
                </div>
              )}

              {variantNeedsKey(resolvedVariant) && (
                <div className="space-y-2">
                  <FieldLabelWithInfo
                    htmlFor="add-app-key"
                    required
                    tooltip={getKeyTooltip(resolvedVariant)}
                  >
                    {resolvedVariant.includes('apple')
                      ? t('Bundle ID')
                      : t('Package name')}
                  </FieldLabelWithInfo>
                  <Input
                    id="add-app-key"
                    value={key}
                    onChange={(e) => {
                      setKey(e.target.value)
                      if (keyError) setKeyError(null)
                    }}
                    placeholder="com.example.app"
                    autoComplete="off"
                    aria-invalid={keyError ? true : undefined}
                  />
                  {keyError && (
                    <p className="text-[12px] text-destructive">{keyError}</p>
                  )}
                </div>
              )}

              {createError && (
                <p className="text-[12px] text-destructive">{createError}</p>
              )}
            </section>
          </form>
        </div>
      ) : (
        <div className="w-full space-y-6 pb-2">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="flex items-center gap-3 px-6 py-4">
              <span
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-emerald-500/30 bg-emerald-500/10"
                aria-hidden
              >
                <Check className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
              </span>
              <div className="min-w-0 flex-1">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('App registered')}
                </h3>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  {t('Your project is ready to accept traffic from this app.')}
                </p>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Set up with AI')}
                </h3>
                <Badge variant="success" className="text-[10px]">
                  {t('Recommended')}
                </Badge>
              </div>
              <p className="mt-2 text-[13px] text-muted-foreground">
                {t(
                  'Hand off a ready-made prompt with your endpoint and project ID to your favourite AI tool, or copy it anywhere.',
                )}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="flex flex-wrap gap-2 px-6 py-4">
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                disabled={!promptText}
                onClick={() => void handleCopyPrompt()}
              >
                {copied ? <Check /> : <Copy />}
                {t('Copy prompt')}
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={!promptText}
                  >
                    <BrainCircuit />
                    {t('Open in tool')}
                    <ChevronDown />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                  align="start"
                  className="z-[10050] min-w-[200px]"
                >
                  {aiChatIDEs.map((ide) => (
                    <DropdownMenuItem
                      key={ide.id}
                      onClick={() => handleOpenInIDE(ide)}
                    >
                      <img
                        src={ide.iconPath}
                        alt={ide.name}
                        className="h-4 w-4"
                      />
                      <span className="ms-2">{t('Prompt')} {ide.name}</span>
                      <ExternalLink
                        className="ms-auto h-2.5 w-2.5 shrink-0 text-muted-foreground/30"
                        strokeWidth={1.25}
                      />
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Manual setup')}
              </h3>
              <p className="mt-2 text-[13px] text-muted-foreground">
                {t(
                  'Clone the starter, drop in your credentials, then run the app and send a ping to confirm the link.',
                )}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="space-y-5 px-6 py-5">
              <SetupStep number={1} label={t('Clone starter')}>
                <CodeBlock
                  code={manualBlocks.clone}
                  language={manualBlocks.cloneLang}
                  copyInside
                />
              </SetupStep>
              <SetupStep number={2} label={t(manualBlocks.configLabel)}>
                <CodeBlock
                  code={manualBlocks.config}
                  language={manualBlocks.configLang}
                  copyInside
                />
              </SetupStep>
              <SetupStep number={3} label={t('Install and run')}>
                <CodeBlock
                  code={manualBlocks.installRun}
                  language="bash"
                  copyInside
                />
                {manualBlocks.port > 0 && (
                  <p className="text-[12px] text-muted-foreground">
                    {t('Demo URL')}:{' '}
                    <a
                      href={`http://localhost:${manualBlocks.port}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="link-neutral inline-flex items-center gap-1"
                    >
                      http://localhost:{manualBlocks.port}
                      <ExternalLink
                        className="h-3 w-3 text-muted-foreground"
                        aria-hidden
                      />
                    </a>
                  </p>
                )}
              </SetupStep>
              <SetupStep number={4} label={t('Send a ping')}>
                <div
                  className={cn(
                    'flex items-center gap-2 rounded-md border px-3 py-2 text-[13px]',
                    pingReceived
                      ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                      : 'border-border bg-muted/30 text-muted-foreground',
                  )}
                >
                  {pingReceived ? (
                    <span
                      className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-emerald-500/30 bg-emerald-500/10"
                      aria-hidden
                    >
                      <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                    </span>
                  ) : (
                    <Loader2 className="h-4 w-4 shrink-0 animate-spin" />
                  )}
                  <span className={cn(pingReceived && 'font-medium')}>
                    {pingReceived
                      ? t('Ping received - your SDK reached Appwrite.') // pragma: allowlist secret
                      : t('Waiting for client.ping() from your app...')}
                  </span>
                </div>
              </SetupStep>
            </div>
          </div>
        </div>
      )}
    </WizardLayout>
  )
}
