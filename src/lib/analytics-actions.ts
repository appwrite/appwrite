/**
 * Curated analytics actions for low-cardinality, specific Plausible event names.
 *
 * Prefer tagging important controls with `analyticsAttrs('…')` instead of deriving
 * names from visible text (resource names, IDs, etc. must never become event names).
 *
 * Add new entries here when instrumenting a control. Keys are stable IDs; values are
 * the exact Plausible event names.
 */
export const ANALYTICS_ACTIONS = {
  // Shell / switchers
  'project-switcher': 'Project Switcher Clicked',
  'organization-switcher': 'Organization Switcher Clicked',
  'command-center': 'Command Center Opened',
  'user-menu': 'User Menu Clicked',
  'theme-toggle': 'Theme Toggle Clicked',
  'sidebar-collapse': 'Sidebar Collapse Clicked',
  'connect-project': 'Connect Project Clicked',
  'copy-connect-sdk-prompt': 'Connect SDK Prompt Copied',
  'header-create-menu': 'Header Create Menu Clicked',
  'feedback-open': 'Feedback Opened',
  'support-open': 'Support Opened',
  'notifications-open': 'Notifications Opened',
  'ai-agent-open': 'AI Agent Opened',
  'upgrade-clicked': 'Upgrade Clicked',
  'auth-sign-in': 'Sign In Clicked',
  'auth-sign-up': 'Sign Up Clicked',
  'header-console': 'Header Console Clicked',
  'header-old-console': 'Header Old Console Clicked',
  'header-home': 'Header Home Clicked',
  'header-docs': 'Header Docs Clicked',
  'create-affiliate-link': 'Create Affiliate Link Clicked',
  'copy-affiliate-link': 'Copy Affiliate Link Clicked',
  'delete-affiliate-link': 'Delete Affiliate Link Clicked',
  'claim-affiliate-reward': 'Claim Affiliate Reward Clicked',

  // Agent (chat page + console right pane)
  'create-agent': 'Create Agent Clicked',
  'create-agent-automation': 'Create Agent Automation Clicked',
  'create-agent-model': 'Create Agent Model Clicked',
  'create-agent-memory': 'Create Agent Memory Clicked',
  'agent-send': 'Agent Send Clicked',
  'agent-stop': 'Agent Stop Clicked',
  'agent-suggestion': 'Agent Suggestion Clicked',
  'agent-attach': 'Agent Attach Clicked',
  'agent-voice': 'Agent Voice Clicked',
  'agent-settings': 'Agent Settings Clicked',
  'agent-open-new-tab': 'Agent Open In New Tab Clicked',
  'agent-close': 'Agent Close Clicked',
  'agent-sidebar-close': 'Agent Sidebar Close Clicked',
  'agent-sidebar-open': 'Agent Sidebar Open Clicked',
  'agent-back': 'Agent Back Clicked',
  'agent-tab-agents': 'Agent Agents Tab Clicked',
  'agent-tab-automations': 'Agent Automations Tab Clicked',
  'agent-model-picker': 'Agent Model Picker Clicked',
  'agent-project-picker': 'Agent Project Picker Clicked',
  'agent-manage-models': 'Agent Manage Models Clicked',
  'agent-mcp-connect': 'Agent MCP Connect Clicked',
  'agent-mcp-disconnect': 'Agent MCP Disconnect Clicked',
  'agent-copy-message': 'Agent Copy Message Clicked',
  'agent-speak-message': 'Agent Speak Message Clicked',
  'agent-thumbs-up': 'Agent Thumbs Up Clicked',
  'agent-thumbs-down': 'Agent Thumbs Down Clicked',
  'agent-retry': 'Agent Retry Clicked',
  'agent-edit-resend': 'Agent Edit Resend Clicked',

  // Marketing page CTAs
  'marketing-get-started': 'Marketing Get Started Clicked',
  'marketing-view-pricing': 'Marketing View Pricing Clicked',
  'home-start-project': 'Home Start Project Clicked',
  'home-request-demo': 'Home Request Demo Clicked',
  'home-start-building': 'Home Start Building Clicked',
  'home-view-pricing': 'Home View Pricing Clicked',
  'home-join-init': 'Home Join Init Clicked',
  'home-pricing-start-free': 'Home Pricing Start Free Clicked',
  'home-pricing-start-pro': 'Home Pricing Start Pro Clicked',
  'home-pricing-contact-enterprise': 'Home Pricing Contact Enterprise Clicked',
  'product-start-building': 'Product Start Building Clicked',
  'product-view-docs': 'Product View Docs Clicked',
  'product-view-pricing': 'Product View Pricing Clicked',
  'enterprise-contact-sales': 'Enterprise Contact Sales Clicked',
  'enterprise-compare-plans': 'Enterprise Compare Plans Clicked',
  'enterprise-form-submit': 'Enterprise Form Submit Clicked',
  'education-sign-up': 'Education Sign Up Clicked',
  'startups-apply-now': 'Startups Apply Now Clicked',
  'startups-form-submit': 'Startups Form Submit Clicked',
  'partners-form-submit': 'Partners Form Submit Clicked',
  'affiliates-join': 'Affiliates Join Clicked',
  'init-claim-ticket': 'Init Claim Ticket Clicked',

  // Pricing page CTAs
  'pricing-start-free': 'Pricing Start Free Clicked',
  'pricing-start-pro': 'Pricing Start Pro Clicked',
  'pricing-contact-enterprise': 'Pricing Contact Enterprise Clicked',
  'pricing-promo-start-free': 'Pricing Promo Start Free Clicked',
  'pricing-promo-start-pro': 'Pricing Promo Start Pro Clicked',
  'pricing-promo-contact-enterprise': 'Pricing Promo Contact Enterprise Clicked',
  'pricing-compare-start-free': 'Pricing Compare Start Free Clicked',
  'pricing-compare-start-pro': 'Pricing Compare Start Pro Clicked',
  'pricing-compare-contact-enterprise': 'Pricing Compare Contact Enterprise Clicked',

  // Docs CTAs
  'docs-get-started': 'Docs Get Started Clicked',
  'docs-api-references': 'Docs API References Clicked',
  'docs-mcp-cta': 'Docs MCP CTA Clicked',
  'docs-all-quick-starts': 'Docs All Quick Starts Clicked',

  // Upgrade / change-plan wizard
  'billing-change-plan': 'Billing Change Plan Clicked',
  'upgrade-select-free': 'Upgrade Select Free Clicked',
  'upgrade-select-pro': 'Upgrade Select Pro Clicked',
  'upgrade-select-scale': 'Upgrade Select Scale Clicked',
  'upgrade-select-enterprise': 'Upgrade Select Enterprise Clicked',
  'upgrade-contact-sales': 'Upgrade Contact Sales Clicked',
  'upgrade-enterprise-learn-more': 'Upgrade Enterprise Learn More Clicked',
  'upgrade-view-pricing': 'Upgrade View Pricing Clicked',
  'upgrade-cancel': 'Upgrade Cancel Clicked',
  'upgrade-submit': 'Upgrade Submit Clicked',
  'upgrade-create-org': 'Upgrade Create Org Clicked',
  'upgrade-add-payment': 'Upgrade Add Payment Clicked',
  'upgrade-apply-coupon': 'Upgrade Apply Coupon Clicked',
  'upgrade-apply-coupon-confirm': 'Upgrade Apply Coupon Confirm Clicked',
  'upgrade-free-conflict-choice': 'Upgrade Free Conflict Choice Selected',

  // Console project sidebar
  'nav-sidebar-overview': 'Sidebar Overview Clicked',
  'nav-sidebar-apps': 'Sidebar Apps Clicked',
  'nav-sidebar-api-keys': 'Sidebar API Keys Clicked',
  'nav-sidebar-explorer': 'Sidebar Explorer Clicked',
  'nav-sidebar-auth': 'Sidebar Auth Clicked',
  'nav-sidebar-databases': 'Sidebar Databases Clicked',
  'nav-sidebar-storage': 'Sidebar Storage Clicked',
  'nav-sidebar-functions': 'Sidebar Functions Clicked',
  'nav-sidebar-messaging': 'Sidebar Messaging Clicked',
  'nav-sidebar-sites': 'Sidebar Sites Clicked',
  'nav-sidebar-stores': 'Sidebar Stores Clicked',
  'nav-sidebar-activity': 'Sidebar Activity Clicked',
  'nav-sidebar-realtime': 'Sidebar Realtime Clicked',
  'nav-sidebar-logs': 'Sidebar Logs Clicked',
  'nav-sidebar-usage': 'Sidebar Usage Clicked',
  'nav-sidebar-analytics': 'Sidebar Analytics Clicked',
  'nav-sidebar-errors': 'Sidebar Errors Clicked',
  'nav-sidebar-firewall': 'Sidebar Firewall Clicked',
  'nav-sidebar-advisor': 'Sidebar Advisor Clicked',
  'nav-sidebar-settings': 'Sidebar Settings Clicked',

  // Organization tabs
  'nav-org-projects': 'Org Projects Tab Clicked',
  'nav-org-members': 'Org Members Tab Clicked',
  'nav-org-domains': 'Org Domains Tab Clicked',
  'nav-org-billing': 'Org Billing Tab Clicked',
  'nav-org-usage': 'Org Usage Tab Clicked',
  'nav-org-settings': 'Org Settings Tab Clicked',
  'nav-org-apps': 'Org Apps Tab Clicked',
  'nav-org-marketplace': 'Org Marketplace Tab Clicked',

  // Marketing / public nav
  'marketing-nav-products': 'Marketing Products Nav Clicked',
  'marketing-nav-docs': 'Marketing Docs Nav Clicked',
  'marketing-nav-pricing': 'Marketing Pricing Nav Clicked',
  'marketing-nav-enterprise': 'Marketing Enterprise Nav Clicked',
  'marketing-nav-customers': 'Marketing Customers Nav Clicked',
  'marketing-nav-blog': 'Marketing Blog Nav Clicked',
  'marketing-nav-changelog': 'Marketing Changelog Nav Clicked',
  'marketing-nav-github': 'Marketing GitHub Nav Clicked',
  'marketing-product-auth': 'Marketing Product Auth Clicked',
  'marketing-product-databases': 'Marketing Product Databases Clicked',
  'marketing-product-storage': 'Marketing Product Storage Clicked',
  'marketing-product-functions': 'Marketing Product Functions Clicked',
  'marketing-product-messaging': 'Marketing Product Messaging Clicked',
  'marketing-product-realtime': 'Marketing Product Realtime Clicked',
  'marketing-product-sites': 'Marketing Product Sites Clicked',
  'marketing-product-domains': 'Marketing Product Domains Clicked',
  'marketing-product-firewall': 'Marketing Product Firewall Clicked',
  'marketing-product-advisor': 'Marketing Product Advisor Clicked',
  'marketing-product-agent': 'Marketing Product Agent Clicked',

  // Docs nav
  'docs-audience-developers': 'Docs Audience Developers Clicked',
  'docs-audience-partners': 'Docs Audience Partners Clicked',
  'docs-sidebar-collapse': 'Docs Sidebar Collapse Clicked',
  'docs-nav-home': 'Docs Home Nav Clicked',
  'docs-nav-quick-start': 'Docs Quick Start Nav Clicked',
  'docs-nav-tutorials': 'Docs Tutorials Nav Clicked',
  'docs-nav-sdks': 'Docs SDKs Nav Clicked',
  'docs-nav-products': 'Docs Products Nav Clicked',
  'docs-nav-apis': 'Docs APIs Nav Clicked',
  'docs-nav-tooling': 'Docs Tooling Nav Clicked',
  'docs-nav-advanced': 'Docs Advanced Nav Clicked',
  'docs-nav-partners': 'Docs Partners Nav Clicked',

  // Resource creation
  'create-project': 'Create Project Clicked',
  'create-organization': 'Create Organization Clicked',
  'create-database': 'Create Database Clicked',
  'create-table': 'Create Table Clicked',
  'create-row': 'Create Row Clicked',
  'create-bucket': 'Create Bucket Clicked',
  'upload-file': 'Upload File Clicked',
  'create-function': 'Create Function Clicked',
  'create-execution': 'Create Execution Clicked',
  'create-site': 'Create Site Clicked',
  'create-deployment': 'Create Deployment Clicked',
  'create-site-domain': 'Create Site Domain Clicked',
  'create-user': 'Create User Clicked',
  'create-team': 'Create Team Clicked',
  'add-team-member': 'Add Team Member Clicked',
  'create-membership': 'Create Membership Clicked',
  'create-message': 'Create Message Clicked',
  'create-topic': 'Create Topic Clicked',
  'create-provider': 'Create Provider Clicked',
  'add-subscriber': 'Add Subscriber Clicked',
  'create-api-key': 'Create API Key Clicked',
  'create-webhook': 'Create Webhook Clicked',
  'add-project-domain': 'Add Project Domain Clicked',
  'add-platform': 'Add Platform Clicked',
  'create-firewall-rule': 'Create Firewall Rule Clicked',
  'buy-domain': 'Buy Domain Clicked',
  'add-org-domain': 'Add Org Domain Clicked',
  'create-dns-record': 'Create DNS Record Clicked',
  'invite-org-member': 'Invite Org Member Clicked',
  'create-backup-policy': 'Create Backup Policy Clicked',
  'import-data': 'Import Data Clicked',
  'create-marketplace-app': 'Create Marketplace App Clicked',
  'add-website': 'Add Website Clicked',
} as const

export type AnalyticsActionId = keyof typeof ANALYTICS_ACTIONS

const SIDEBAR_NAV_ACTIONS: Record<string, AnalyticsActionId> = {
  overview: 'nav-sidebar-overview',
  apps: 'nav-sidebar-apps',
  'api-keys': 'nav-sidebar-api-keys',
  explorer: 'nav-sidebar-explorer',
  auth: 'nav-sidebar-auth',
  databases: 'nav-sidebar-databases',
  storage: 'nav-sidebar-storage',
  functions: 'nav-sidebar-functions',
  messaging: 'nav-sidebar-messaging',
  sites: 'nav-sidebar-sites',
  stores: 'nav-sidebar-stores',
  activity: 'nav-sidebar-activity',
  realtime: 'nav-sidebar-realtime',
  logs: 'nav-sidebar-logs',
  usage: 'nav-sidebar-usage',
  analytics: 'nav-sidebar-analytics',
  errors: 'nav-sidebar-errors',
  firewall: 'nav-sidebar-firewall',
  advisor: 'nav-sidebar-advisor',
  settings: 'nav-sidebar-settings',
}

const MARKETING_PRODUCT_ACTIONS: Record<string, AnalyticsActionId> = {
  auth: 'marketing-product-auth',
  databases: 'marketing-product-databases',
  storage: 'marketing-product-storage',
  functions: 'marketing-product-functions',
  messaging: 'marketing-product-messaging',
  realtime: 'marketing-product-realtime',
  sites: 'marketing-product-sites',
  domains: 'marketing-product-domains',
  firewall: 'marketing-product-firewall',
  advisor: 'marketing-product-advisor',
  agent: 'marketing-product-agent',
}

const ORG_TAB_ACTIONS: Record<string, AnalyticsActionId> = {
  projects: 'nav-org-projects',
  members: 'nav-org-members',
  domains: 'nav-org-domains',
  billing: 'nav-org-billing',
  usage: 'nav-org-usage',
  settings: 'nav-org-settings',
  apps: 'nav-org-apps',
  marketplace: 'nav-org-marketplace',
}

const PRICING_PLAN_CTA_ACTIONS: Record<string, AnalyticsActionId> = {
  free: 'pricing-start-free',
  pro: 'pricing-start-pro',
  enterprise: 'pricing-contact-enterprise',
}

const UPGRADE_PLAN_SELECT_ACTIONS: Record<string, AnalyticsActionId> = {
  free: 'upgrade-select-free',
  'tier-0': 'upgrade-select-free',
  pro: 'upgrade-select-pro',
  'tier-1': 'upgrade-select-pro',
  scale: 'upgrade-select-scale',
  'tier-2': 'upgrade-select-scale',
  enterprise: 'upgrade-select-enterprise',
  'tier-enterprise': 'upgrade-select-enterprise',
}

/** Exact docs global-nav hrefs → curated actions (top-level section entries only). */
const DOCS_NAV_ACTIONS: Record<string, AnalyticsActionId> = {
  '/docs': 'docs-nav-home',
  '/docs/quick-starts': 'docs-nav-quick-start',
  '/docs/tutorials': 'docs-nav-tutorials',
  '/docs/sdks': 'docs-nav-sdks',
  '/docs/partners': 'docs-nav-partners',
  '/docs/partners/quick-start': 'docs-nav-quick-start',
}

export function isAnalyticsActionId(value: string): value is AnalyticsActionId {
  return value in ANALYTICS_ACTIONS
}

export function getAnalyticsActionEventName(
  actionId: string,
): string | undefined {
  if (!isAnalyticsActionId(actionId)) return undefined
  return ANALYTICS_ACTIONS[actionId]
}

export function getSidebarNavAnalyticsAction(
  navItemId: string,
): AnalyticsActionId | undefined {
  return SIDEBAR_NAV_ACTIONS[navItemId]
}

export function getMarketingProductAnalyticsAction(
  productId: string,
): AnalyticsActionId | undefined {
  return MARKETING_PRODUCT_ACTIONS[productId]
}

export function getOrgTabAnalyticsAction(
  tabId: string,
): AnalyticsActionId | undefined {
  return ORG_TAB_ACTIONS[tabId]
}

export function getPricingPlanCtaAnalyticsAction(
  planId: string,
): AnalyticsActionId | undefined {
  return PRICING_PLAN_CTA_ACTIONS[planId]
}

export function getUpgradePlanSelectAnalyticsAction(
  planIdOrName: string,
): AnalyticsActionId | undefined {
  const key = planIdOrName.trim().toLowerCase()
  return (
    UPGRADE_PLAN_SELECT_ACTIONS[key] ??
    UPGRADE_PLAN_SELECT_ACTIONS[key.replace(/\s+/g, '-')]
  )
}

export function getDocsNavAnalyticsAction(
  href: string,
): AnalyticsActionId | undefined {
  const normalized = href.replace(/\/+$/, '') || '/'
  return DOCS_NAV_ACTIONS[normalized]
}

/** Spread onto interactive elements (or a close ancestor) for automatic tracking. */
export function analyticsAttrs(action: AnalyticsActionId): {
  'data-analytics': AnalyticsActionId
} {
  return { 'data-analytics': action }
}
