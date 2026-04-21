/**
 * Project-scope tab entries — sub-tabs inside service sections.
 *
 * These are intentionally search-only: they don't appear in the default
 * landing list, but typing "templates", "domains", "smtp" etc. surfaces
 * the right destination instantly.
 *
 * Label convention: `Section · Subsection` (e.g. "Auth · Templates").
 *
 * To add a new tab, drop another object below — registration is automatic.
 */

import {
  Bell,
  Code,
  Globe,
  Layers,
  Mail,
  Megaphone,
  Send,
  Settings,
  Shield,
  ShieldCheck,
  Sliders,
  Users,
  Zap,
} from 'lucide-react'
import {
  canShowAuthSecuritySettings,
  canShowProjectSettings,
} from '@/lib/console-access-checks'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const PROJECT_TABS: CommandEntry[] = [
  // ── Auth ────────────────────────────────────────────────────────────────
  {
    id: 'project.tab.auth.users',
    scopes: ['project'],
    kind: 'tab',
    group: 'Auth',
    label: 'Auth · Users',
    description: 'Browse and manage your project users',
    icon: Users,
    keywords: ['users', 'accounts', 'people'],
    to: (ctx) => `/projects/${ctx.projectId}/auth`,
  },
  {
    id: 'project.tab.auth.teams',
    scopes: ['project'],
    kind: 'tab',
    group: 'Auth',
    label: 'Auth · Teams',
    description: 'Group users into teams with roles',
    icon: Users,
    keywords: ['teams', 'groups', 'roles'],
    to: (ctx) => `/projects/${ctx.projectId}/auth/teams`,
  },
  {
    id: 'project.tab.auth.security',
    scopes: ['project'],
    kind: 'tab',
    group: 'Auth',
    label: 'Auth · Security',
    description: 'Sessions, password history, mock numbers, MFA',
    icon: ShieldCheck,
    keywords: ['security', 'mfa', 'sessions', 'password'],
    available: (ctx) => canShowAuthSecuritySettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/auth/security`,
  },
  {
    id: 'project.tab.auth.templates',
    scopes: ['project'],
    kind: 'tab',
    group: 'Auth',
    label: 'Auth · Templates',
    description: 'Customize verification, recovery and magic URL emails',
    icon: Mail,
    keywords: ['templates', 'emails', 'verification', 'recovery', 'magic url'],
    available: (ctx) => canShowAuthSecuritySettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/auth/templates`,
  },
  {
    id: 'project.tab.auth.settings',
    scopes: ['project'],
    kind: 'tab',
    group: 'Auth',
    label: 'Auth · Settings',
    description: 'Configure auth methods, OAuth providers and limits',
    icon: Settings,
    keywords: ['oauth', 'providers', 'methods', 'settings'],
    available: (ctx) => canShowAuthSecuritySettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/auth/settings`,
  },

  // ── Messaging ───────────────────────────────────────────────────────────
  {
    id: 'project.tab.messaging.messages',
    scopes: ['project'],
    kind: 'tab',
    group: 'Messaging',
    label: 'Messaging · Messages',
    description: 'Sent and scheduled messages',
    icon: Send,
    keywords: ['messages', 'history', 'push', 'sms', 'email'],
    to: (ctx) => `/projects/${ctx.projectId}/messaging`,
  },
  {
    id: 'project.tab.messaging.topics',
    scopes: ['project'],
    kind: 'tab',
    group: 'Messaging',
    label: 'Messaging · Topics',
    description: 'Subscriber topics for fan-out messaging',
    icon: Megaphone,
    keywords: ['topics', 'subscribers', 'channels', 'pubsub'],
    to: (ctx) => `/projects/${ctx.projectId}/messaging/topics`,
  },
  {
    id: 'project.tab.messaging.providers',
    scopes: ['project'],
    kind: 'tab',
    group: 'Messaging',
    label: 'Messaging · Providers',
    description: 'Email, SMS and push providers',
    icon: Bell,
    keywords: ['providers', 'twilio', 'sendgrid', 'fcm', 'apns', 'mailgun'],
    to: (ctx) => `/projects/${ctx.projectId}/messaging/providers`,
  },

  // ── Settings ────────────────────────────────────────────────────────────
  {
    id: 'project.tab.settings.overview',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Overview',
    description: 'Project ID, name, region, API endpoint',
    icon: Sliders,
    keywords: ['overview', 'general', 'project id', 'endpoint', 'region'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings`,
  },
  {
    id: 'project.tab.settings.domains',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Custom domains',
    description: 'Custom domains for your project endpoint',
    icon: Globe,
    keywords: ['domains', 'custom', 'dns', 'cname', 'hosting'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings/domains`,
  },
  {
    id: 'project.tab.settings.variables',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Variables',
    description: 'Project-level environment variables',
    icon: Code,
    keywords: ['variables', 'env', 'environment', 'secrets'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings/variables`,
  },
  {
    id: 'project.tab.settings.webhooks',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Webhooks',
    description: 'HTTP callbacks for project events',
    icon: Zap,
    keywords: ['webhooks', 'events', 'http', 'callbacks'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings/webhooks`,
  },
  {
    id: 'project.tab.settings.migrations',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Migrations',
    description: 'Import data from other backends',
    icon: Layers,
    keywords: ['migrations', 'import', 'transfer', 'firebase', 'supabase'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings/migrations`,
  },
  {
    id: 'project.tab.settings.smtp',
    scopes: ['project'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · SMTP',
    description: 'Custom SMTP server for outgoing emails',
    icon: Mail,
    keywords: ['smtp', 'email', 'mail', 'server'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings/smtp`,
  },

  // ── Security ────────────────────────────────────────────────────────────
  {
    id: 'project.tab.firewall',
    scopes: ['project'],
    kind: 'tab',
    group: 'Security',
    label: 'Security · Firewall rules',
    description: 'IP allow/block lists and request rules',
    icon: Shield,
    keywords: ['firewall', 'rules', 'ip', 'allowlist'],
    to: (ctx) => `/projects/${ctx.projectId}/firewall`,
  },
]

registerCommands(PROJECT_TABS)
