import { Terminal } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { McpIcon } from '@/components/global/shared/McpIcon'
import {
  canShowConnectSection,
  canShowProjectTerminal,
} from '@/lib/console-access-checks'
import { CLI_SHELL_TOGGLE_SHORTCUT_RAW } from '@/lib/cli-shell/cli-terminal-shortcuts'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const PROJECT_ACTIONS: CommandEntry[] = [
  {
    id: 'project.action.terminal',
    scopes: ['project'],
    kind: 'action',
    group: 'Actions',
    label: 'Open terminal',
    description: 'Toggle the built-in Appwrite CLI terminal',
    icon: Terminal,
    shortcut: CLI_SHELL_TOGGLE_SHORTCUT_RAW,
    keywords: [
      'terminal',
      'cli',
      'shell',
      'command',
      'appwrite',
      'console',
    ],
    available: (ctx) =>
      canShowProjectTerminal(ctx.access, ctx.features) &&
      Boolean(ctx.handlers.onToggleTerminal),
    perform: (ctx) => {
      ctx.handlers.onToggleTerminal?.()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'project.action.install-mcp',
    scopes: ['project'],
    kind: 'action',
    group: 'Actions',
    label: 'Install Appwrite MCP',
    description: 'Connect Cursor, Claude Code, Codex, or VS Code to this project',
    icon: McpIcon as LucideIcon,
    keywords: [
      'mcp',
      'model context protocol',
      'agent',
      'cursor',
      'claude',
      'codex',
      'vscode',
      'ai',
      'install',
      'connect',
    ],
    available: (ctx) =>
      canShowConnectSection(ctx.access, ctx.features) &&
      Boolean(ctx.handlers.onOpenConnectMcp),
    perform: (ctx) => {
      ctx.handlers.onOpenConnectMcp?.()
      ctx.closeCommandCenter()
    },
  },
]

registerCommands(PROJECT_ACTIONS)
