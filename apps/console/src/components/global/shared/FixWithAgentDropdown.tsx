import { BrainCircuit, ChevronDown, Copy, ExternalLink } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  generateAIChatDeeplink,
  getAIChatIDEs,
  openAIChatDeeplink,
  type IDEConfig,
} from '@/lib/config/ide'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Popovers/menus must sit above fullscreen overlays and drawers. */
export const FIX_WITH_AGENT_DROPDOWN_Z = 'z-[10050]'

export interface FixWithAgentDropdownProps {
  /** Markdown prompt passed to agent deeplinks and copy action. */
  prompt: string
  align?: 'start' | 'center' | 'end'
  className?: string
  /** Hide the label on small screens (compact toolbar layouts). */
  hideLabelOnSmallScreens?: boolean
}

export function FixWithAgentDropdown({
  prompt,
  align = 'end',
  className,
  hideLabelOnSmallScreens = false,
}: FixWithAgentDropdownProps) {
  const t = useT()
  const aiChatIDEs = getAIChatIDEs()
  const projectConnect = useProjectConnectDialog()

  const handleOpenInIDE = (ide: IDEConfig) => {
    const deeplink = generateAIChatDeeplink(ide, prompt)
    if (deeplink) {
      openAIChatDeeplink(deeplink)
      toast.success(`Opening ${ide.name}...`)
    }
  }

  const handleCopyPrompt = async () => {
    try {
      await navigator.clipboard.writeText(prompt)
      toast.success(t('Prompt copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy prompt'))
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className={cn('gap-1.5', className)}
          disabled={!prompt.trim()}
        >
          <BrainCircuit className="h-4 w-4 shrink-0" />
          <span className={hideLabelOnSmallScreens ? 'hidden sm:inline' : undefined}>
            {t('Fix with an Agent')}
          </span>
          <ChevronDown className="h-3 w-3 shrink-0" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align={align}
        className={cn('min-w-[180px]', FIX_WITH_AGENT_DROPDOWN_Z)}
      >
        <DropdownMenuItem onClick={() => void handleCopyPrompt()}>
          <Copy className="h-4 w-4" />
          <span className="ms-2">{t('Copy prompt')}</span>
        </DropdownMenuItem>
        {projectConnect ? (
          <DropdownMenuItem
            onClick={() => projectConnect.openConnect('mcp')}
          >
            <McpIcon className="h-4 w-4" />
            <span className="ms-2">{t('Install Appwrite MCP')}</span>
          </DropdownMenuItem>
        ) : null}
        <div className="my-1 h-px bg-border" />
        {aiChatIDEs.map((ide) => (
          <DropdownMenuItem key={ide.id} onClick={() => handleOpenInIDE(ide)}>
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
  )
}
