/**
 * Create deployment via CLI – shows copyable commands with syntax highlighting.
 * Supports Unix (bash), Windows CMD, and PowerShell.
 * Line continuation: Unix `\`, CMD `^`, PowerShell `,`. Chain: Unix/CMD `&&`, PowerShell `;`.
 */

import { useEffect, useMemo, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Info } from 'lucide-react'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useUserOs } from '@/hooks/use-user-os'
import {
  orderCliShellTabs,
  resolveDefaultCliShellTab,
  type CliShellTab,
} from '@/lib/user-os'
import { useT } from '@/lib/i18n/translate'

export type CreateCliDeploymentResourceType = 'function' | 'site'

/** When resourceType is 'site', pass build config from the site resource. */
export interface SiteBuildConfig {
  framework?: string
  buildCommand?: string
  installCommand?: string
  startCommand?: string
  outputDirectory?: string
}

export interface CreateCliDeploymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  resourceType: CreateCliDeploymentResourceType
  projectId: string
  resourceId: string
  /** Required for resourceType 'site' to show build/install/output args and code path. */
  siteBuildConfig?: SiteBuildConfig
}

const CLI_INSTALL_URL =
  '/docs/tooling/command-line/installation#install-with-npm'
const CLI_LOGIN_URL =
  '/docs/tooling/command-line/installation#login'

function buildCommands(
  resourceType: CreateCliDeploymentResourceType,
  projectId: string,
  resourceId: string,
  siteBuildConfig?: SiteBuildConfig,
): { unix: string; cmd: string; powershell: string } {
  if (resourceType === 'function') {
    const unix = `appwrite client --project-id="${projectId}" && \\
appwrite functions create-deployment \\
    --function-id=${resourceId} \\
    --code="." \\
    --activate=true`
    const cmd = `appwrite client --project-id="${projectId}" && ^
appwrite functions create-deployment ^
    --function-id=${resourceId} ^
    --code="." ^
    --activate`
    const powershell = `appwrite client --project-id="${projectId}" ;
appwrite functions create-deployment ,
    --function-id=${resourceId} ,
    --code="." ,
    --activate`
    return { unix, cmd, powershell }
  }

  const framework = siteBuildConfig?.framework || 'react'
  const codePath = `./sites/${framework}`
  const buildCommand = siteBuildConfig?.buildCommand?.trim() || ''
  const installCommand = siteBuildConfig?.installCommand?.trim() || ''
  const startCommand = siteBuildConfig?.startCommand?.trim() || ''
  const outputDirectory = siteBuildConfig?.outputDirectory?.trim() || ''

  const buildArgs: string[] = [
    `--site-id="${resourceId}"`,
    `--code="${codePath}"`,
    '--activate',
  ]
  if (buildCommand) buildArgs.push(`--build-command="${buildCommand}"`)
  if (installCommand) buildArgs.push(`--install-command="${installCommand}"`)
  if (startCommand) buildArgs.push(`--start-command="${startCommand}"`)
  if (outputDirectory) buildArgs.push(`--output-directory="${outputDirectory}"`)

  const unixLines = [
    `appwrite client --project-id="${projectId}" && \\`,
    'appwrite sites create-deployment \\',
    ...buildArgs.map((a, i) =>
      i < buildArgs.length - 1 ? `    ${a} \\` : `    ${a}`,
    ),
  ]
  const unixTrimmed = unixLines.join('\n')

  const cmdArgs = [
    `--site-id="${resourceId}"`,
    `--code="${codePath}"`,
    '--activate',
  ]
  if (buildCommand) cmdArgs.push(`--build-command="${buildCommand}"`)
  if (installCommand) cmdArgs.push(`--install-command="${installCommand}"`)
  if (outputDirectory) cmdArgs.push(`--output-directory="${outputDirectory}"`)
  const cmdLines = [
    `appwrite client --project-id="${projectId}" && ^`,
    'appwrite sites create-deployment ^',
    ...cmdArgs.map((a, i) =>
      i < cmdArgs.length - 1 ? `    ${a} ^` : `    ${a}`,
    ),
  ]
  const cmdTrimmed = cmdLines.join('\n')

  const psArgs = [
    `--site-id="${resourceId}"`,
    `--code="${codePath}"`,
    '--activate',
  ]
  if (buildCommand) psArgs.push(`--build-command="${buildCommand}"`)
  if (installCommand) psArgs.push(`--install-command="${installCommand}"`)
  if (outputDirectory) psArgs.push(`--output-directory="${outputDirectory}"`)
  const psLines = [
    `appwrite client --project-id="${projectId}" ;`,
    'appwrite sites create-deployment ,',
    ...psArgs.map((a, i) =>
      i < psArgs.length - 1 ? `    ${a} ,` : `    ${a}`,
    ),
  ]
  const psTrimmed = psLines.join('\n')

  return { unix: unixTrimmed, cmd: cmdTrimmed, powershell: psTrimmed }
}

const SHELL_TAB_LABELS: Record<CliShellTab, string> = {
  unix: 'Unix',
  cmd: 'CMD',
  powershell: 'PowerShell',
}

const DESCRIPTION = {
  function:
    "Deploy your function using the Appwrite CLI by running the following command inside your function's folder.",
  site: "Deploy your site using the Appwrite CLI by running the following command inside your site's folder.",
} as const

export function CreateCliDeploymentModal({
  open,
  onOpenChange,
  resourceType,
  projectId,
  resourceId,
  siteBuildConfig,
}: CreateCliDeploymentModalProps) {
  const t = useT()
  const { os } = useUserOs()
  const shellTabs = useMemo(() => orderCliShellTabs(os), [os])
  const defaultTab = resolveDefaultCliShellTab(os)
  const [activeTab, setActiveTab] = useState<CliShellTab>(defaultTab)

  useEffect(() => {
    setActiveTab(resolveDefaultCliShellTab(os))
  }, [os])

  const commands = buildCommands(
    resourceType,
    projectId,
    resourceId,
    siteBuildConfig,
  )

  const codeByTab = {
    unix: commands.unix,
    cmd: commands.cmd,
    powershell: commands.powershell,
  } as const
  const languageByTab: Record<CliShellTab, CodeBlockLanguage> = {
    unix: 'bash',
    cmd: 'bash',
    powershell: 'powershell',
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create CLI deployment')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(DESCRIPTION[resourceType])}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4">
          <Tabs
            value={activeTab}
            onValueChange={(value) => setActiveTab(value as CliShellTab)}
          >
            <TabsList className="mb-3 w-full grid grid-cols-3">
              {shellTabs.map((tab) => (
                <TabsTrigger key={tab} value={tab} className="text-[13px]">
                  {SHELL_TAB_LABELS[tab]}
                </TabsTrigger>
              ))}
            </TabsList>
            {/* Fixed-height container with explicit CodeBlock height so all tabs scroll properly */}
            <div className="min-h-0 overflow-hidden" style={{ height: 250 }}>
              {shellTabs.map((tab) => (
                <TabsContent
                  key={tab}
                  value={tab}
                  className="mt-0 h-full data-[state=inactive]:hidden"
                >
                  <CodeBlock
                    code={codeByTab[tab]}
                    language={languageByTab[tab]}
                    copyInside
                    fixedHeight="230px"
                    className="[&>div:last-child]:min-h-0"
                  />
                </TabsContent>
              ))}
            </div>
          </Tabs>
          <div className="mt-4 rounded-lg border border-border bg-muted/30 px-4 py-3 flex gap-3 text-[12px] text-muted-foreground">
            <Info className="h-4 w-4 shrink-0 text-muted-foreground mt-0.5" />
            <p>
              {t("If it's your first time using the CLI, remember to")}{' '}
              <DocsRouteLink className="link-neutral" href={CLI_INSTALL_URL}>
                {t('install the CLI')}
              </DocsRouteLink>{' '}
              {t('and')}{' '}
              <DocsRouteLink className="link-neutral" href={CLI_LOGIN_URL}>
                {t('log in to your account')}
              </DocsRouteLink>{' '}
              {t('before running the deployment command.')}
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            className="h-9 text-[13px]"
          >
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
