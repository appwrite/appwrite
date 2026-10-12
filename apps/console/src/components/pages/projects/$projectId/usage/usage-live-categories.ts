import { REQUESTS_BREAKDOWN_SECTIONS } from '@/lib/usage/requests-breakdowns'
import { BANDWIDTH_BREAKDOWN_SECTIONS } from '@/lib/usage/bandwidth-breakdowns'
import { DATABASE_OPERATIONS_BREAKDOWN_SECTIONS } from '@/lib/usage/database-operations-breakdowns'
import type { UsageCategory } from './data'

export type RequestsUsageCategory = {
  id: 'requests'
  label: 'Requests'
  icon: 'Activity'
  description: string
  metrics: { id: string; name: string }[]
}

export const REQUESTS_USAGE_CATEGORY: RequestsUsageCategory = {
  id: 'requests',
  label: 'Requests',
  icon: 'Activity',
  description:
    'API request volume and breakdowns across paths, methods, status codes, and client attributes.',
  metrics: [
    { id: 'api-requests', name: 'Requests over time' },
    ...REQUESTS_BREAKDOWN_SECTIONS.map((section) => ({
      id: section.metricId,
      name: section.title,
    })),
  ],
}

export type BandwidthUsageCategory = {
  id: 'bandwidth'
  label: 'Bandwidth'
  icon: 'ArrowUpDown'
  description: string
  metrics: { id: string; name: string }[]
}

export const BANDWIDTH_USAGE_CATEGORY: BandwidthUsageCategory = {
  id: 'bandwidth',
  label: 'Bandwidth',
  icon: 'ArrowUpDown',
  description:
    'Network bandwidth consumption with breakdowns across paths, services, and client attributes.',
  metrics: [
    { id: 'bandwidth-over-time', name: 'Bandwidth over time' },
    ...BANDWIDTH_BREAKDOWN_SECTIONS.map((section) => ({
      id: section.metricId,
      name: section.title,
    })),
  ],
}

export function buildRequestsUsageCategoryMetrics(): UsageCategory['metrics'] {
  return REQUESTS_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: 'requests',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export function buildBandwidthUsageCategoryMetrics(): UsageCategory['metrics'] {
  return BANDWIDTH_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: 'GB',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type DatabasesUsageCategory = {
  id: 'databases'
  label: 'Databases'
  icon: 'Database'
  description: string
  metrics: { id: string; name: string }[]
}

export const DATABASES_USAGE_CATEGORY: DatabasesUsageCategory = {
  id: 'databases',
  label: 'Databases',
  icon: 'Database',
  description:
    'Database operations including reads, writes, and resource counts.',
  metrics: [
    { id: 'reads', name: 'Database reads' },
    { id: 'writes', name: 'Database writes' },
    ...DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      id: `reads-${section.metricId}`,
      name: `Reads: ${section.title}`,
    })),
    ...DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      id: `writes-${section.metricId}`,
      name: `Writes: ${section.title}`,
    })),
    { id: 'collections', name: 'Collections' },
    { id: 'documents', name: 'Total documents' },
  ],
}

export function buildDatabasesUsageCategoryMetrics(): UsageCategory['metrics'] {
  return DATABASES_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: metric.id === 'reads' || metric.id === 'writes' ? 'operations' : 'count',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type RealtimeUsageCategory = {
  id: 'realtime'
  label: 'Realtime'
  icon: 'Radio'
  description: string
  metrics: { id: string; name: string }[]
}

export const REALTIME_USAGE_CATEGORY: RealtimeUsageCategory = {
  id: 'realtime',
  label: 'Realtime',
  icon: 'Radio',
  description:
    'WebSocket connections, messages sent, and bandwidth for live data synchronization.',
  metrics: [
    { id: 'connections', name: 'Concurrent connections' },
    { id: 'messages', name: 'Messages sent' },
    { id: 'bandwidth', name: 'Realtime bandwidth' },
  ],
}

export function buildRealtimeUsageCategoryMetrics(): UsageCategory['metrics'] {
  return REALTIME_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit:
      metric.id === 'bandwidth'
        ? 'bytes'
        : metric.id === 'messages'
          ? 'messages'
          : 'connections',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type AuthUsageCategory = {
  id: 'auth'
  label: 'Auth'
  icon: 'Users'
  description: string
  metrics: { id: string; name: string }[]
}

export const AUTH_USAGE_CATEGORY: AuthUsageCategory = {
  id: 'auth',
  label: 'Auth',
  icon: 'Users',
  description:
    'Authentication metrics including monthly active users, phone OTP usage, and sign-up activity.',
  metrics: [
    { id: 'mau', name: 'Monthly active users' },
    { id: 'otp', name: 'OTP attempts' },
    { id: 'signups', name: 'Sign-ups' },
  ],
}

export function buildAuthUsageCategoryMetrics(): UsageCategory['metrics'] {
  return AUTH_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit:
      metric.id === 'otp'
        ? 'attempts'
        : metric.id === 'signups'
          ? 'users'
          : 'users',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type ComputeUsageCategory = {
  id: 'compute'
  label: 'Compute'
  icon: 'Cpu'
  description: string
  metrics: { id: string; name: string }[]
}

export const COMPUTE_USAGE_CATEGORY: ComputeUsageCategory = {
  id: 'compute',
  label: 'Compute',
  icon: 'Cpu',
  description:
    'Combined function and site executions plus compute time across your project.',
  metrics: [
    { id: 'executions', name: 'Executions' },
    { id: 'gb-hours', name: 'GB-hours' },
  ],
}

export type FunctionsUsageCategory = {
  id: 'functions'
  label: 'Functions'
  icon: 'Zap'
  description: string
  metrics: { id: string; name: string }[]
}

export const FUNCTIONS_USAGE_CATEGORY: FunctionsUsageCategory = {
  id: 'functions',
  label: 'Functions',
  icon: 'Zap',
  description:
    'Function executions and compute time during the selected period.',
  metrics: [
    { id: 'executions', name: 'Executions' },
    { id: 'gb-hours', name: 'GB-hours' },
  ],
}

export type SitesUsageCategory = {
  id: 'sites'
  label: 'Sites'
  icon: 'Globe'
  description: string
  metrics: { id: string; name: string }[]
}

export const SITES_USAGE_CATEGORY: SitesUsageCategory = {
  id: 'sites',
  label: 'Sites',
  icon: 'Globe',
  description: 'Site executions and compute time during the selected period.',
  metrics: [
    { id: 'executions', name: 'Executions' },
    { id: 'gb-hours', name: 'GB-hours' },
  ],
}

export function buildComputeUsageCategoryMetrics(): UsageCategory['metrics'] {
  return COMPUTE_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: metric.id === 'gb-hours' ? 'GB-hours' : 'executions',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export function buildFunctionsUsageCategoryMetrics(): UsageCategory['metrics'] {
  return FUNCTIONS_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: metric.id === 'gb-hours' ? 'GB-hours' : 'executions',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export function buildSitesUsageCategoryMetrics(): UsageCategory['metrics'] {
  return SITES_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: metric.id === 'gb-hours' ? 'GB-hours' : 'executions',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type AvatarsUsageCategory = {
  id: 'avatars'
  label: 'Avatars'
  icon: 'UserCircle'
  description: string
  metrics: { id: string; name: string }[]
}

export const AVATARS_USAGE_CATEGORY: AvatarsUsageCategory = {
  id: 'avatars',
  label: 'Avatars',
  icon: 'UserCircle',
  description:
    'Avatars API usage for webpage screenshots and other generated assets.',
  metrics: [{ id: 'screenshots', name: 'Screenshots generated' }],
}

export function buildAvatarsUsageCategoryMetrics(): UsageCategory['metrics'] {
  return AVATARS_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: 'screenshots',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type MessagingUsageCategory = {
  id: 'messaging'
  label: 'Messaging'
  icon: 'MessageSquare'
  description: string
  metrics: { id: string; name: string }[]
}

export const MESSAGING_USAGE_CATEGORY: MessagingUsageCategory = {
  id: 'messaging',
  label: 'Messaging',
  icon: 'MessageSquare',
  description:
    'Push notifications, emails, and SMS messages sent through the messaging service.',
  metrics: [
    { id: 'messages', name: 'Messages sent' },
    { id: 'topics', name: 'Topics' },
    { id: 'sms', name: 'SMS messages' },
  ],
}

export function buildMessagingUsageCategoryMetrics(): UsageCategory['metrics'] {
  return MESSAGING_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit:
      metric.id === 'topics'
        ? 'topics'
        : metric.id === 'sms'
          ? 'messages'
          : 'messages',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type WebhooksUsageCategory = {
  id: 'webhooks'
  label: 'Webhooks'
  icon: 'Webhook'
  description: string
  metrics: { id: string; name: string }[]
}

export const WEBHOOKS_USAGE_CATEGORY: WebhooksUsageCategory = {
  id: 'webhooks',
  label: 'Webhooks',
  icon: 'Webhook',
  description:
    'Webhook event deliveries and configured endpoints for Appwrite event notifications.',
  metrics: [
    { id: 'events-sent', name: 'Events sent' },
    { id: 'events-failed', name: 'Events failed' },
    { id: 'webhooks', name: 'Webhooks' },
  ],
}

export function buildWebhooksUsageCategoryMetrics(): UsageCategory['metrics'] {
  return WEBHOOKS_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit: metric.id === 'webhooks' ? 'webhooks' : 'events',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}

export type StorageUsageCategory = {
  id: 'storage'
  label: 'Storage'
  icon: 'Folder'
  description: string
  metrics: { id: string; name: string }[]
}

export const STORAGE_USAGE_CATEGORY: StorageUsageCategory = {
  id: 'storage',
  label: 'Storage',
  icon: 'Folder',
  description:
    'File, deployment, and build storage usage, plus billable image transformations.',
  metrics: [
    { id: 'file-storage', name: 'Files' },
    { id: 'deployment-storage', name: 'Deployment storage' },
    { id: 'build-storage', name: 'Build storage' },
    { id: 'image-transformations', name: 'Image transformations' },
  ],
}

export function buildStorageUsageCategoryMetrics(): UsageCategory['metrics'] {
  return STORAGE_USAGE_CATEGORY.metrics.map((metric) => ({
    id: metric.id,
    name: metric.name,
    description: '',
    unit:
      metric.id === 'image-transformations'
        ? 'origin images'
        : 'bytes',
    currentValue: 0,
    quota: null,
    timeSeries: [],
  }))
}
