import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const AGENT_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'models',
    title: 'Models',
    keywords: ['model', 'llm', 'openai', 'anthropic', 'provider', 'api key'],
  },
  {
    sectionId: 'memory',
    title: 'Memory',
    keywords: [
      'memory',
      'memories',
      'preference',
      'instruction',
      'fact',
      'remember',
    ],
  },
  {
    sectionId: 'mcp',
    title: 'MCP connections',
    keywords: ['mcp', 'server', 'oauth', 'tools', 'connect'],
  },
  {
    sectionId: 'usage',
    title: 'Usage',
    keywords: [
      'usage',
      'runs',
      'messages',
      'conversations',
      'tokens',
      'tool calls',
      'automations',
      'metrics',
    ],
  },
]
