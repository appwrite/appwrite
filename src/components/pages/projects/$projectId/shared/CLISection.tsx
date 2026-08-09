/**
 * Connect modal CLI tab: install, then Interactive (OAuth + init) or CI/CD (API key + client).
 */

import { useEffect, useMemo, useState } from 'react'
import { ExternalLink, Key, Plus, Terminal } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import { useCliShellOptional } from '@/components/global/cli-shell/CliShellProvider'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useUserOs } from '@/hooks/use-user-os'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  canCreateKey,
  canShowProjectTerminal,
} from '@/lib/console-access-checks'
import {
  useCreateApiKey,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getUserOsLabel, type UserOs } from '@/lib/user-os'
import { useT } from '@/lib/i18n/translate'

const CLI_CICD_API_KEY_DEFAULT_NAME = 'CI/CD'

const CLI_INSTALL_URL = '/docs/tooling/command-line/installation'
const CLI_COMMANDS_URL = '/docs/tooling/command-line/commands'
const CLI_NON_INTERACTIVE_URL = '/docs/tooling/command-line/non-interactive'
const CLI_DEVICE_AUTH_BLOG =
  '/blog/post/announcing-cli-device-authorization'

type CliMode = 'interactive' | 'cicd'

function installCommands(os: UserOs): { id: string; label: string; code: string }[] {
  if (os === 'macos') {
    return [
      { id: 'npm', label: 'npm', code: 'npm install -g appwrite-cli' },
      { id: 'brew', label: 'Homebrew', code: 'brew install appwrite' },
      {
        id: 'script',
        label: 'Install script',
        code: 'curl -sL https://appwrite.io/cli/install.sh | bash',
      },
    ]
  }
  if (os === 'windows') {
    return [
      { id: 'npm', label: 'npm', code: 'npm install -g appwrite-cli' },
      {
        id: 'powershell',
        label: 'PowerShell',
        code: 'iwr -useb https://appwrite.io/cli/install.ps1 | iex',
      },
      {
        id: 'scoop',
        label: 'Scoop',
        code: 'scoop install https://raw.githubusercontent.com/appwrite/sdk-for-cli/master/scoop/appwrite.config.json',
      },
    ]
  }
  return [
    { id: 'npm', label: 'npm', code: 'npm install -g appwrite-cli' },
    {
      id: 'script',
      label: 'Install script',
      code: 'curl -sL https://appwrite.io/cli/install.sh | bash',
    },
  ]
}

export interface CLISectionProps {
  endpoint: string
  projectId: string
  onViewApiKeys: () => void
  /** Close the Connect dialog before opening the in-console terminal. */
  onClose?: () => void
}

export function CLISection({
  endpoint,
  projectId,
  onViewApiKeys,
  onClose,
}: CLISectionProps) {
  const t = useT()
  const cliShell = useCliShellOptional()
  const { project } = useProject(projectId)
  const { features, isSelfHosted } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { os: resolvedOs, orderOptions } = useUserOs()
  const showTerminal =
    Boolean(cliShell) && canShowProjectTerminal(access, features)
  const noCreatePermission = !canCreateKey(access, features)
  const createMutation = useCreateApiKey(projectId)

  const [mode, setMode] = useState<CliMode>('interactive')
  const [cliInstallOs, setCliInstallOs] = useState<UserOs>(resolvedOs)
  const [installMethodId, setInstallMethodId] = useState<string>(() => {
    return installCommands(resolvedOs)[0]?.id ?? 'npm'
  })
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const osOptions = orderOptions()

  useEffect(() => {
    setCliInstallOs(resolvedOs)
    setInstallMethodId(installCommands(resolvedOs)[0]?.id ?? 'npm')
  }, [resolvedOs])

  const installOptions = useMemo(
    () => installCommands(cliInstallOs),
    [cliInstallOs],
  )

  const selectedInstall =
    installOptions.find((option) => option.id === installMethodId) ??
    installOptions[0]!

  const loginCommand = isSelfHosted
    ? `appwrite login --endpoint="${endpoint}"`
    : 'appwrite login'
  const clientCommand = `appwrite client --endpoint="${endpoint}" --project-id="${projectId}" --key="YOUR_API_KEY"`

  const handleOsChange = (os: UserOs) => {
    setCliInstallOs(os)
    const next = installCommands(os)
    setInstallMethodId(next[0]?.id ?? 'npm')
  }

  const handleOpenTerminal = () => {
    onClose?.()
    cliShell?.setOpen(true)
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

  return (
    <>
    <div className="flex flex-col gap-4 pt-4 min-h-0 h-full overflow-hidden">
      <div className="shrink-0 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-muted/30 p-1">
          {(
            [
              { id: 'interactive' as const, label: t('Interactive') },
              { id: 'cicd' as const, label: t('CI/CD') },
            ] as const
          ).map((option) => (
            <button
              key={option.id}
              type="button"
              onClick={() => setMode(option.id)}
              className={cn(
                'cursor-pointer rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors',
                mode === option.id
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {option.label}
            </button>
          ))}
        </div>
        {showTerminal ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 gap-1.5 text-[13px]"
            onClick={handleOpenTerminal}
          >
            <Terminal className="h-3.5 w-3.5" />
            {t('Open Appwrite Terminal')}
          </Button>
        ) : null}
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden flex flex-col min-h-0 flex-1">
        <div className="px-4 py-3 border-b border-border space-y-2 shrink-0">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h4 className="text-[13px] font-semibold text-foreground">
              {t('1. Install')}
            </h4>
            <div className="flex flex-wrap gap-1 rounded-md border border-border bg-muted/30 p-0.5">
              {osOptions.map((os) => (
                <button
                  key={os}
                  type="button"
                  onClick={() => handleOsChange(os)}
                  className={cn(
                    'cursor-pointer rounded px-2 py-0.5 text-[11px] font-medium transition-colors',
                    cliInstallOs === os
                      ? 'bg-background text-foreground shadow-sm'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  {getUserOsLabel(os)}
                </button>
              ))}
            </div>
          </div>
          <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-muted/30 p-1 w-fit">
            {installOptions.map((option) => (
              <button
                key={option.id}
                type="button"
                onClick={() => setInstallMethodId(option.id)}
                className={cn(
                  'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                  selectedInstall.id === option.id
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {option.label === 'Install script'
                  ? t('Install script')
                  : option.label}
              </button>
            ))}
          </div>
          <CodeBlock
            code={selectedInstall.code}
            language="bash"
            label={t('Terminal')}
            showCopy
          />
        </div>

        {mode === 'interactive' ? (
          <div className="grid grid-cols-2 divide-x divide-border flex-1 min-h-0">
            <div className="px-4 py-3 space-y-2 min-w-0">
              <div>
                <h4 className="text-[13px] font-semibold text-foreground">
                  {t('2. Log in')}
                </h4>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t(
                    'Opens your browser for OAuth. No password in the terminal.',
                  )}
                </p>
              </div>
              <CodeBlock
                code={loginCommand}
                language="bash"
                label={t('Terminal')}
                showCopy
                wrapLines
              />
            </div>
            <div className="px-4 py-3 space-y-2 min-w-0">
              <div>
                <h4 className="text-[13px] font-semibold text-foreground">
                  {t('3. Connect to this project')}
                </h4>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('Interactive init. Writes appwrite.config.json.')}
                </p>
              </div>
              <CodeBlock
                code="appwrite init project"
                language="bash"
                label={t('Terminal')}
                showCopy
              />
            </div>
          </div>
        ) : (
          <div className="px-4 py-3 space-y-2 flex-1 min-h-0">
            <div>
              <h4 className="text-[13px] font-semibold text-foreground">
                {t('2. Authenticate with an API key')}
              </h4>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t('Headless CI/CD auth: endpoint, project, and key.')}
              </p>
            </div>
            <CodeBlock
              code={clientCommand}
              language="bash"
              label={t('Terminal')}
              showCopy
              wrapLines
            />
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="h-8 gap-1.5 text-[12px]"
                onClick={() => setCreateDrawerOpen(true)}
                disabled={noCreatePermission}
                title={
                  noCreatePermission
                    ? t("You don't have permission to create API keys.")
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
                onClick={onViewApiKeys}
              >
                <Key className="h-3.5 w-3.5" />
                {t('View API keys')}
              </Button>
            </div>
          </div>
        )}

        <div className="px-4 py-2.5 border-t border-border bg-muted/30 flex flex-wrap items-center gap-x-4 gap-y-1 shrink-0">
          <DocsRouteLink
            href={CLI_INSTALL_URL}
            className="inline-flex items-center gap-1 link-neutral text-[12px]"
          >
            {t('Install guide')}
            <ExternalLink className="h-3 w-3" />
          </DocsRouteLink>
          {mode === 'interactive' ? (
            <DocsRouteLink
              href={CLI_DEVICE_AUTH_BLOG}
              className="inline-flex items-center gap-1 link-neutral text-[12px]"
            >
              {t('Device auth')}
              <ExternalLink className="h-3 w-3" />
            </DocsRouteLink>
          ) : (
            <DocsRouteLink
              href={CLI_NON_INTERACTIVE_URL}
              className="inline-flex items-center gap-1 link-neutral text-[12px]"
            >
              {t('CI docs')}
              <ExternalLink className="h-3 w-3" />
            </DocsRouteLink>
          )}
          <DocsRouteLink
            href={CLI_COMMANDS_URL}
            className="inline-flex items-center gap-1 link-neutral text-[12px]"
          >
            {t('Example commands')}
            <ExternalLink className="h-3 w-3" />
          </DocsRouteLink>
        </div>
      </div>
    </div>

    <ApiKeyDrawer
      open={createDrawerOpen}
      onOpenChange={(open) => {
        setCreateDrawerOpen(open)
        if (!open) setCreatedKeySecret(null)
      }}
      onSubmit={handleCreateApiKey}
      isLoading={createMutation.isPending}
      createdKeySecret={createdKeySecret}
      onCopy={handleCopyKey}
      copiedField={copiedField}
      initialName={t(CLI_CICD_API_KEY_DEFAULT_NAME)}
    />
    </>
  )
}
