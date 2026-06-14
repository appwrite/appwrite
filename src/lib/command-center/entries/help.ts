/**
 * Help entries available across console scopes (feedback, support).
 */

import { Headphones, MessageSquarePlus } from 'lucide-react'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const HELP: CommandEntry[] = [
  {
    id: 'help.feedback',
    scopes: ['account', 'organization', 'project'],
    kind: 'action',
    group: 'Help',
    label: 'Send feedback',
    description: 'Share feedback to help us improve the console',
    icon: MessageSquarePlus,
    keywords: ['feedback', 'suggestion', 'bug report', 'improve', 'report'],
    perform: (ctx) => ctx.openFeedbackPage?.(),
  },
  {
    id: 'help.support',
    scopes: ['account', 'organization', 'project'],
    kind: 'action',
    group: 'Help',
    label: 'Support',
    description: 'Contact support, Discord, GitHub, and system status',
    icon: Headphones,
    keywords: [
      'support',
      'help',
      'contact',
      'discord',
      'github',
      'status',
      'sales',
    ],
    perform: (ctx) => ctx.openSupportPage?.(),
  },
]

registerCommands(HELP)
