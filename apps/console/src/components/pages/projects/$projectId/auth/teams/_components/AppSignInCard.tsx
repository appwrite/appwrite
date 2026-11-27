import { useEffect, useMemo, useRef, useState } from 'react'
import {
  BrainCircuit,
  Braces,
  Check,
  ChevronDown,
  Copy,
  ExternalLink,
} from 'lucide-react'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  getAIChatIDEs,
  generateAIChatDeeplink,
  openAIChatDeeplink,
  type IDEConfig,
} from '@/lib/config/ide'
import { useT } from '@/lib/i18n/translate'
import {
  teamsFor,
  useSSODomains,
  useSSOProviders,
} from '@/lib/react-query/hooks'
import type { SSOTarget } from '@/lib/sso-target'
import {
  SSO_SIGN_IN_PLATFORMS,
  SSO_SIGN_IN_PLATFORM_META,
  buildSSOSignInGuide,
  buildSSOSignInPrompt,
  type SSOSignInPlatform,
} from '@/lib/sso-app-sign-in/snippets'
import { cn } from '@/lib/utils'

/** Short names for the code frame's tabs, by step id. */
const STEP_TABS: Record<string, string> = {
  find: 'Find providers',
  send: 'Redirect',
  finish: 'Create session',
  manifest: 'Callback scheme',
  env: 'API key',
  gradle: 'Build settings',
}

/** Decorative: the tab's label names the platform. */
function PlatformGlyph({ platform }: { platform: SSOSignInPlatform }) {
  const icon = SSO_SIGN_IN_PLATFORM_META[platform].icon
  return (
    <span aria-hidden className="flex shrink-0">
      {icon === 'http' ? (
        <Braces className="h-3.5 w-3.5" />
      ) : icon === 'web' ? (
        <PlatformIcon platform="web" size="sm" />
      ) : (
        <FrameworkIcon framework={icon} size="sm" className="h-3.5 w-3.5" />
      )}
    </span>
  )
}

/**
 * What the app needs to sign its users in through the team's identity
 * providers, in the shape of the analytics install and the Connect modal:
 * a "Copy prompt" for a coding agent first, then platform tabs, the steps on
 * the left and their code on the right. No SDK has the single sign-on methods
 * yet, so the code calls the endpoints and uses the SDK for the session only.
 */
export function AppSignInCard({
  projectId,
  teamId,
}: {
  projectId: string
  teamId: string
}) {
  const t = useT()
  const target: SSOTarget = { scope: 'project', projectId, teamId }
  const endpoint = teamsFor(target).client.config.endpoint
  const { providers } = useSSOProviders(target)
  const { domains } = useSSODomains(target)
  const [platform, setPlatform] = useState<SSOSignInPlatform>('web')
  const [stepId, setStepId] = useState('find')

  // Real values where the examples show data: an email on a verified domain
  // and an enabled identity provider.
  const verifiedDomains = useMemo(
    () =>
      domains
        .filter((domain) => domain.status === 'verified')
        .map((domain) => domain.domain),
    [domains],
  )
  const enabledProviders = useMemo(
    () =>
      providers
        .filter((provider) => provider.enabled)
        .map(({ $id, name }) => ({ $id, name })),
    [providers],
  )
  const input = useMemo(
    () => ({
      endpoint,
      projectId,
      exampleEmail: `jane@${verifiedDomains[0] ?? 'example.com'}`,
      exampleProvider: enabledProviders[0],
    }),
    [endpoint, projectId, verifiedDomains, enabledProviders],
  )

  const guide = useMemo(
    () => buildSSOSignInGuide(platform, input),
    [platform, input],
  )
  const step = guide.steps.find((item) => item.id === stepId) ?? guide.steps[0]
  const meta = SSO_SIGN_IN_PLATFORM_META[platform]

  const prompt = useMemo(
    () =>
      buildSSOSignInPrompt(platform, {
        ...input,
        teamId,
        providers: enabledProviders,
        domains: verifiedDomains,
      }),
    [platform, input, teamId, enabledProviders, verifiedDomains],
  )

  const aiChatIDEs = useMemo(() => getAIChatIDEs(), [])
  const [copied, setCopied] = useState(false)
  const copiedTimeout = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(
    () => () => {
      if (copiedTimeout.current) clearTimeout(copiedTimeout.current)
    },
    [],
  )

  const copyPrompt = async () => {
    try {
      await navigator.clipboard.writeText(prompt)
      setCopied(true)
      if (copiedTimeout.current) clearTimeout(copiedTimeout.current)
      copiedTimeout.current = setTimeout(() => setCopied(false), 2000)
    } catch {
      // Silent, as in the analytics wizard: the button keeps its icon.
    }
  }

  const openInTool = (ide: IDEConfig) => {
    const deeplink = generateAIChatDeeplink(ide, prompt)
    if (deeplink) openAIChatDeeplink(deeplink)
  }

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-start justify-between gap-4 px-6 py-4">
        <div className="min-w-0 max-w-2xl">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Add sign-in to your app')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'People type their work email, sign in at their identity provider and come back signed in, as members of this team. Hand the setup to your AI tool, or follow the steps.',
            )}
          </p>
        </div>
        {/* The prompt follows the platform picked below. */}
        <div className="flex shrink-0 flex-wrap gap-2">
          <Button
            type="button"
            size="sm"
            className="h-9 gap-1.5 text-[13px]"
            onClick={() => void copyPrompt()}
          >
            {copied ? <Check /> : <Copy />}
            {copied ? t('Copied') : t('Copy prompt')}
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 gap-1.5 text-[13px]"
              >
                <BrainCircuit />
                {t('Open in tool')}
                <ChevronDown />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
              align="end"
              className="z-[10050] min-w-[200px]"
            >
              {aiChatIDEs.map((ide) => (
                <DropdownMenuItem key={ide.id} onClick={() => openInTool(ide)}>
                  <img src={ide.iconPath} alt={ide.name} className="h-4 w-4" />
                  <span className="ms-2">
                    {t('Prompt')} {ide.name}
                  </span>
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

      {/* Platform tabs: underline style, as in the Connect modal. */}
      <div
        role="tablist"
        aria-label={t('Platform')}
        className="flex gap-0 overflow-x-auto border-y border-border px-6"
      >
        {SSO_SIGN_IN_PLATFORMS.map((id) => {
          const isActive = platform === id
          return (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => {
                setPlatform(id)
                setStepId('find')
              }}
              className={cn(
                'relative flex shrink-0 cursor-pointer items-center gap-1.5 rounded-sm px-3 py-2.5 text-[13px] font-medium transition-colors',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                isActive
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground/80',
              )}
            >
              <PlatformGlyph platform={id} />
              {t(SSO_SIGN_IN_PLATFORM_META[id].label)}
              {isActive ? (
                <span className="absolute inset-x-0 bottom-0 h-[2px] bg-foreground" />
              ) : null}
            </button>
          )
        })}
      </div>

      <div className="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.6fr)]">
        {/* Left: the steps (each opens its code), then what is special here. */}
        <div className="flex min-w-0 flex-col gap-5">
          <ol className="space-y-1">
            {guide.steps.map((item, index) => {
              const isActive = item.id === step.id
              return (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => setStepId(item.id)}
                    aria-current={isActive ? 'step' : undefined}
                    className={cn(
                      'flex w-full cursor-pointer gap-3 rounded-lg px-3 py-2.5 text-start transition-colors',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                      isActive ? 'bg-muted' : 'hover:bg-muted/50',
                    )}
                  >
                    <span
                      className={cn(
                        'mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                        isActive
                          ? 'bg-foreground text-background'
                          : 'bg-muted-foreground/15 text-muted-foreground',
                      )}
                    >
                      {index + 1}
                    </span>
                    <span className="min-w-0">
                      <span className="block text-[13px] font-medium text-foreground">
                        {t(item.title)}
                      </span>
                      <span className="mt-0.5 block text-[12px] leading-relaxed text-muted-foreground">
                        {t(item.description)}
                      </span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ol>

          <p className="px-3 text-[12px] leading-relaxed text-muted-foreground">
            {t(meta.note)}
          </p>

          <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border px-3 pt-4 text-[12px] text-muted-foreground">
            <span className="inline-flex items-center gap-2">
              {t('Project ID')}
              <CopyableId id={projectId} size="xs" />
            </span>
            <span className="inline-flex items-center gap-2">
              {t('Team ID')}
              <CopyableId id={teamId} size="xs" />
            </span>
          </div>
        </div>

        {/* Right: the install command, then the code of the selected step. */}
        <div className="flex min-w-0 flex-col gap-4">
          {guide.install ? (
            <div className="space-y-1.5">
              <h4 className="text-[13px] font-semibold text-foreground">
                {t('Installation')}
              </h4>
              <CodeBlock
                code={guide.install.code}
                language={guide.install.language}
                copyInside
                wrapLines
              />
            </div>
          ) : null}
          <div className="flex min-w-0 flex-col gap-1.5">
            {/* The file the selected step goes in, beside the heading: the
                code frame's toolbar keeps its room for the step tabs. */}
            <div className="flex min-w-0 items-baseline justify-between gap-3">
              <h4 className="shrink-0 text-[13px] font-semibold text-foreground">
                {t('Add to your app')}
              </h4>
              <span
                className="min-w-0 truncate font-mono text-[11px] text-muted-foreground"
                title={step.file}
              >
                {step.file}
              </span>
            </div>
            <ConnectCodeExample
              code={step.code}
              language={step.language}
              tabs={guide.steps.map((item, index) => ({
                id: item.id,
                label: `${index + 1}. ${t(STEP_TABS[item.id] ?? item.title)}`,
              }))}
              activeTabId={step.id}
              onTabChange={setStepId}
              selectorAriaLabel={t('Select step')}
              fixedHeight="460px"
            />
          </div>
        </div>
      </div>
    </div>
  )
}
