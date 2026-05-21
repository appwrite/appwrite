import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const ORG_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'overview',
    title: 'Organization ID',
    keywords: ['id', 'api', 'webhook', 'sdk', 'copy'],
  },
  {
    sectionId: 'overview',
    title: 'Organization Name',
    keywords: ['rename', 'display name'],
  },
  {
    sectionId: 'overview',
    title: 'Delete Organization',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'billing',
    title: 'Current plan',
    keywords: [
      'plan',
      'subscription',
      'tier',
      'upgrade',
      'downgrade',
      'change plan',
      'pro',
      'scale',
      'free',
      'next payment',
      'charges',
      'billing cycle',
    ],
  },
  {
    sectionId: 'billing',
    title: 'Payment History',
    keywords: ['invoice', 'invoices', 'receipt', 'payment history', 'paid'],
  },
  {
    sectionId: 'billing',
    title: 'Payment Methods',
    keywords: [
      'card',
      'credit card',
      'stripe',
      'backup',
      'default payment',
      'payment method',
    ],
  },
  {
    sectionId: 'billing',
    title: 'Billing Address',
    keywords: ['address', 'country', 'city', 'postal', 'zip', 'street'],
  },
  {
    sectionId: 'billing',
    title: 'Tax ID',
    keywords: ['vat', 'tax', 'ein', 'gst', 'identification'],
  },
  {
    sectionId: 'billing',
    title: 'Budget Cap',
    keywords: ['budget', 'spending limit', 'cap', 'overage', 'usage limit'],
  },
  {
    sectionId: 'billing',
    title: 'Billing Alerts',
    keywords: ['alerts', 'threshold', 'notification', 'usage', 'email alert'],
  },
  {
    sectionId: 'billing',
    title: 'Available Credits',
    keywords: ['credits', 'balance', 'coupon', 'promo', 'prepaid'],
  },
  {
    sectionId: 'billing',
    title: 'Payment Failed',
    keywords: ['failed', 'retry', 'outstanding', 'read-only'],
  },
  {
    sectionId: 'billing',
    title: 'Payment Method Failed',
    keywords: ['expired', 'declined', 'failed card'],
  },
  {
    sectionId: 'billing',
    title: 'Plan Downgrade Scheduled',
    keywords: ['downgrade', 'scheduled', 'end of period'],
  },
  {
    sectionId: 'compliance',
    title: 'Data Processing Agreement (DPA)',
    keywords: ['dpa', 'gdpr', 'legal', 'data processing'],
  },
  {
    sectionId: 'compliance',
    title: 'Business Associate Agreement (BAA)',
    keywords: ['baa', 'hipaa', 'phi', 'healthcare'],
  },
  {
    sectionId: 'compliance',
    title: 'SOC 2 Type II Report',
    keywords: ['soc2', 'audit', 'enterprise', 'security'],
  },
  {
    sectionId: 'api-keys',
    title: 'API key types',
    keywords: ['project keys', 'account keys', 'org keys', 'scopes'],
  },
  {
    sectionId: 'api-keys',
    title: 'Project keys',
    keywords: ['database', 'storage', 'functions'],
  },
  {
    sectionId: 'api-keys',
    title: 'Account keys',
    keywords: ['cli', 'sessions', 'user'],
  },
  {
    sectionId: 'api-keys',
    title: 'Org keys',
    keywords: ['billing', 'team', 'organization'],
  },
]
