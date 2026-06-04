/**
 * Organization-scope navigation, tabs, and settings sub-tabs.
 *
 * To add an org-level destination, append an entry below.
 */

import { SOC2_SETTINGS_KEYWORDS } from '@/lib/settings-search/org-settings-cards'
import {
  AlertOctagon,
  Building2,
  CreditCard,
  FolderOpen,
  Globe,
  Key,
  Store,
  Plus,
  Settings,
  ShieldCheck,
  UserPlus,
  Users,
} from 'lucide-react'
import {
  canAccessOrgSettingsMembers,
  canAccessOrgSettingsOverview,
  canCreateProject,
  canInviteOrgMember,
  canShowOrgApiKeysSettings,
  canShowOrgBillingNav,
  canShowOrgComplianceNav,
  canShowOrgDomainsTab,
  canShowOrgMarketplaceTab,
  canShowOrgOAuthAppsSettings,
  canShowOrgSettingsTab,
} from '@/lib/console-access-checks'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const ORG_NAV: CommandEntry[] = [
  {
    id: 'org.nav.projects',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Projects',
    description: 'All projects in this organization',
    icon: FolderOpen,
    shortcut: 'G P',
    keywords: ['home', 'projects', 'list', 'main'],
    to: (ctx) => `/organizations/${ctx.orgId}`,
  },
  {
    id: 'org.nav.marketplace',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Marketplace',
    description: 'Browse and publish Appwrite marketplace apps',
    icon: Store,
    keywords: ['apps', 'integrations', 'plugins', 'extensions', 'catalog'],
    available: (ctx) => canShowOrgMarketplaceTab(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/marketplace`,
  },
  {
    id: 'org.nav.domains',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Domains',
    description: 'Manage organization-level custom domains',
    icon: Globe,
    shortcut: 'G D',
    keywords: ['dns', 'url', 'hosting', 'domain', 'cname'],
    available: (ctx) => canShowOrgDomainsTab(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/domains`,
  },
  {
    id: 'org.nav.settings',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Settings',
    description: 'Organization settings (members, billing, compliance)',
    icon: Settings,
    shortcut: 'G S',
    keywords: ['config', 'preferences', 'options', 'settings'],
    available: (ctx) => canShowOrgSettingsTab(ctx.access),
    to: (ctx) => `/organizations/${ctx.orgId}/settings`,
  },
  {
    id: 'org.nav.members',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Members',
    description: 'Organization members and roles',
    icon: Users,
    shortcut: 'G M',
    keywords: ['team', 'members', 'roles', 'permissions', 'people'],
    available: (ctx) => canAccessOrgSettingsMembers(ctx.access),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/members`,
  },
  {
    id: 'org.nav.billing',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Billing',
    description: 'Plan, payment methods and invoices',
    icon: CreditCard,
    shortcut: 'G B',
    keywords: ['payment', 'subscription', 'invoice', 'plan', 'billing'],
    available: (ctx) => canShowOrgBillingNav(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/billing`,
  },
  {
    id: 'org.nav.compliance',
    scopes: ['organization'],
    kind: 'navigation',
    label: 'Compliance',
    description: 'DPA, BAA, SOC 2, HIPAA, GDPR',
    icon: ShieldCheck,
    shortcut: 'G C',
    keywords: [
      'compliance',
      'dpa',
      'baa',
      'hipaa',
      'gdpr',
      'legal',
      ...SOC2_SETTINGS_KEYWORDS,
    ],
    available: (ctx) => canShowOrgComplianceNav(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/compliance`,
  },
]

const ORG_SETTINGS_TABS: CommandEntry[] = [
  {
    id: 'org.tab.settings.oauth-apps',
    scopes: ['organization'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · OAuth apps',
    description: 'Third-party OAuth apps with access to this organization',
    icon: Key,
    keywords: ['oauth', 'apps', 'third party', 'integrations'],
    available: (ctx) => canShowOrgOAuthAppsSettings(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/oauth-apps`,
  },
  {
    id: 'org.tab.settings.api-keys',
    scopes: ['organization'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · API keys',
    description: 'Org-level API keys for automation',
    icon: Key,
    keywords: ['api', 'keys', 'tokens', 'automation', 'org'],
    available: (ctx) => canShowOrgApiKeysSettings(ctx.access, ctx.features),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/api-keys`,
  },
  {
    id: 'org.tab.settings.danger-zone',
    scopes: ['organization'],
    kind: 'tab',
    group: 'Settings',
    label: 'Settings · Delete organization',
    description: 'Permanently delete this organization',
    icon: AlertOctagon,
    keywords: ['danger', 'delete', 'remove', 'destroy'],
    available: (ctx) =>
      ctx.features.multiTenancy && canAccessOrgSettingsOverview(ctx.access),
    to: (ctx) => `/organizations/${ctx.orgId}/settings/danger-zone`,
  },
]

const ORG_CREATE: CommandEntry[] = [
  {
    id: 'org.create.project',
    scopes: ['organization'],
    kind: 'create',
    label: 'Create project',
    description: 'Spin up a new project in this organization',
    icon: Plus,
    shortcut: 'C P',
    keywords: ['new', 'project', 'add'],
    disabled: (ctx) => !canCreateProject(ctx.access, ctx.features),
    disabledReason: (ctx) =>
      canCreateProject(ctx.access, ctx.features)
        ? undefined
        : "You don't have permission to create projects.",
    perform: (ctx) => {
      ctx.closeCommandCenter()
      if (ctx.handlers.onOrgCreateProject) {
        ctx.handlers.onOrgCreateProject()
      } else if (ctx.orgId) {
        ctx.navigate(`/organizations/${ctx.orgId}`)
      }
    },
  },
  {
    id: 'org.create.organization',
    scopes: ['organization'],
    kind: 'create',
    label: 'Create organization',
    description: 'Create a new organization',
    icon: Building2,
    shortcut: 'C T',
    keywords: ['new', 'organization', 'team', 'add'],
    available: (ctx) => ctx.features.multiTenancy,
    perform: (ctx) => {
      ctx.closeCommandCenter()
      if (ctx.features.billing) {
        ctx.navigate('/upgrade')
      }
    },
  },
  {
    id: 'org.create.invite-member',
    scopes: ['organization'],
    kind: 'create',
    label: 'Invite member',
    description: 'Invite a new member to this organization',
    icon: UserPlus,
    shortcut: 'C M',
    keywords: ['invite', 'member', 'user', 'add'],
    available: (ctx) =>
      Boolean(ctx.handlers.onOrgInviteMember) &&
      canInviteOrgMember(ctx.access, ctx.features),
    perform: (ctx) => {
      ctx.closeCommandCenter()
      ctx.handlers.onOrgInviteMember?.()
    },
  },
]

registerCommands([...ORG_NAV, ...ORG_SETTINGS_TABS, ...ORG_CREATE])
