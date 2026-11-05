// Usage data types and mock data for the Usage page

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

export interface TimeSeriesDataPoint {
  timestamp: number // Unix timestamp in ms
  value: number
}

export interface UsageMetric {
  id: string
  name: string
  description: string
  unit: string
  currentValue: number
  quota: number | null // null means unlimited
  timeSeries: TimeSeriesDataPoint[]
  thresholds?: {
    warning: number // percentage
    critical: number // percentage
  }
}

export interface UsageCategory {
  id: string
  label: string
  icon: string
  description: string
  metrics: UsageMetric[]
}

export interface UsageData {
  categories: UsageCategory[]
  billingCycleStart: number
  billingCycleEnd: number
  plan: 'free' | 'pro' | 'custom'
}

export type UsageState = 'loading' | 'success' | 'error' | 'empty'

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Generate mock time series data for the last N days
 */
function generateTimeSeries(
  days: number,
  baseValue: number,
  variance: number,
  trend: 'up' | 'down' | 'stable' = 'stable',
): TimeSeriesDataPoint[] {
  const now = Date.now()
  const dayMs = 24 * 60 * 60 * 1000
  const data: TimeSeriesDataPoint[] = []

  for (let i = days - 1; i >= 0; i--) {
    const timestamp = now - i * dayMs
    let trendFactor = 1
    if (trend === 'up') trendFactor = 1 + ((days - i) / days) * 0.3
    if (trend === 'down') trendFactor = 1 - ((days - i) / days) * 0.2

    const randomVariance = 1 + (Math.random() - 0.5) * variance
    const value = Math.round(baseValue * trendFactor * randomVariance)
    data.push({ timestamp, value: Math.max(0, value) })
  }

  return data
}

/**
 * Calculate the sum of time series values
 */
export function sumTimeSeries(timeSeries: TimeSeriesDataPoint[]): number {
  return timeSeries.reduce((sum, point) => sum + point.value, 0)
}

/**
 * Calculate the average of time series values
 */
export function avgTimeSeries(timeSeries: TimeSeriesDataPoint[]): number {
  if (timeSeries.length === 0) return 0
  return Math.round(sumTimeSeries(timeSeries) / timeSeries.length)
}

/**
 * Get the maximum value in a time series
 */
export function maxTimeSeries(timeSeries: TimeSeriesDataPoint[]): number {
  if (timeSeries.length === 0) return 0
  return Math.max(...timeSeries.map((p) => p.value))
}

/**
 * Format a number with appropriate suffix (K, M, B)
 */
export function formatMetricValue(value: number, unit: string): string {
  if (unit === 'GB' || unit === 'MB') {
    return `${value.toFixed(2)} ${unit}`
  }
  if (value >= 1_000_000_000) {
    return `${(value / 1_000_000_000).toFixed(2)}B`
  }
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(2)}M`
  }
  if (value >= 1_000) {
    return `${(value / 1_000).toFixed(2)}K`
  }
  return value.toLocaleString()
}

/**
 * Calculate usage percentage against quota
 */
export function getUsagePercentage(
  current: number,
  quota: number | null,
): number | null {
  if (quota === null || quota === 0) return null
  return Math.min(100, (current / quota) * 100)
}

/**
 * Get status color based on usage percentage
 */
export function getUsageStatus(
  percentage: number | null,
): 'normal' | 'warning' | 'critical' {
  if (percentage === null) return 'normal'
  if (percentage >= 90) return 'critical'
  if (percentage >= 75) return 'warning'
  return 'normal'
}

// ============================================================================
// PLAN QUOTAS
// ============================================================================

export const planQuotas = {
  free: {
    compute: { executions: 750_000, gbHours: 100 },
    auth: { mau: 75_000, otp: 10, signUps: null },
    databases: {
      reads: 1_000_000,
      writes: 500_000,
      collections: 100,
      documents: null,
    },
    storage: { bytes: 2 * 1000 * 1000 * 1000, operations: 500_000 }, // 2 GB
    bandwidth: { egress: 10 * 1000 * 1000 * 1000, ingress: null }, // 10 GB
    realtime: { connections: 250 },
    messaging: { messages: 10_000, topics: 100, sms: 0 },
    avatars: { screenshots: 50 },
    webhooks: { webhooks: 5, eventsSent: null, eventsFailed: null },
  },
  pro: {
    compute: { executions: 3_500_000, gbHours: 500 },
    auth: { mau: 200_000, otp: 100, signUps: null },
    databases: {
      reads: 5_000_000,
      writes: 2_500_000,
      collections: 500,
      documents: null,
    },
    storage: { bytes: 150 * 1000 * 1000 * 1000, operations: 2_500_000 }, // 150 GB
    bandwidth: { egress: 300 * 1000 * 1000 * 1000, ingress: null }, // 300 GB
    realtime: { connections: 500 },
    messaging: { messages: 100_000, topics: 500, sms: 100 },
    avatars: { screenshots: 5_000 },
    webhooks: { webhooks: 25, eventsSent: null, eventsFailed: null },
  },
  custom: {
    compute: { executions: null, gbHours: null },
    auth: { mau: null, otp: null, signUps: null },
    databases: {
      reads: null,
      writes: null,
      collections: null,
      documents: null,
    },
    storage: { bytes: null, operations: null },
    bandwidth: { egress: null, ingress: null },
    realtime: { connections: null },
    messaging: { messages: null, topics: null, sms: null },
    avatars: { screenshots: null },
    webhooks: { webhooks: null, eventsSent: null, eventsFailed: null },
  },
}

// ============================================================================
// MOCK USAGE DATA
// ============================================================================

export function generateMockUsageData(
  plan: 'free' | 'pro' | 'custom' = 'pro',
): UsageData {
  const quotas = planQuotas[plan]
  const now = Date.now()
  const dayMs = 24 * 60 * 60 * 1000

  // Billing cycle: 30 days
  const billingCycleStart = now - 15 * dayMs
  const billingCycleEnd = now + 15 * dayMs

  const categories: UsageCategory[] = [
    {
      id: 'compute',
      label: 'Compute',
      icon: 'Cpu',
      description:
        'Function executions and compute resources consumed by your serverless functions.',
      metrics: [
        {
          id: 'executions',
          name: 'Function Executions',
          description:
            'Total number of function invocations during this billing cycle. Each time a function is triggered (via HTTP, schedule, or event), it counts as one execution. Executions beyond your plan limit are billed at $0.50 per 1,000 executions.',
          unit: 'executions',
          currentValue: 1_245_000,
          quota: quotas.compute.executions,
          timeSeries: generateTimeSeries(30, 41_500, 0.4, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'gb-hours',
          name: 'GB-Hours',
          description:
            'Compute time measured in gigabyte-hours. This represents the memory allocated to your functions multiplied by execution duration. A function using 512MB for 2 hours consumes 1 GB-hour. Additional GB-hours are billed at $0.15 per GB-hour.',
          unit: 'GB-hours',
          currentValue: 287.5,
          quota: quotas.compute.gbHours,
          timeSeries: generateTimeSeries(30, 9.6, 0.3, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
    {
      id: 'auth',
      label: 'Auth',
      icon: 'Users',
      description:
        'Authentication metrics including active users, OTP usage, and sign-up activity.',
      metrics: [
        {
          id: 'mau',
          name: 'Monthly Active Users',
          description:
            'Unique users who have authenticated at least once during the billing cycle. This includes all authentication methods (email, OAuth, phone, etc.). MAU beyond your plan limit are billed at $0.02 per user.',
          unit: 'users',
          currentValue: 45_230,
          quota: quotas.auth.mau,
          timeSeries: generateTimeSeries(30, 1_508, 0.2, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'otp',
          name: 'OTP Attempts',
          description:
            'One-time password verification attempts via SMS or email. Each OTP sent counts toward this limit. Additional OTP messages are billed at $0.05 per message for email and $0.10 for SMS.',
          unit: 'attempts',
          currentValue: 2_340,
          quota: quotas.auth.otp,
          timeSeries: generateTimeSeries(30, 78, 0.5, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'signups',
          name: 'Sign-ups',
          description:
            'New user registrations during this billing cycle. This metric helps you track user growth and onboarding patterns. Sign-ups are not directly limited but contribute to your MAU count.',
          unit: 'users',
          currentValue: 3_890,
          quota: quotas.auth.signUps,
          timeSeries: generateTimeSeries(30, 130, 0.4, 'up'),
        },
      ],
    },
    {
      id: 'databases',
      label: 'Databases',
      icon: 'Database',
      description:
        'Database operations including reads, writes, and resource counts.',
      metrics: [
        {
          id: 'reads',
          name: 'Database Reads',
          description:
            'Total document read operations across all databases. Each query that retrieves documents counts as reads (one per document returned). Reads beyond your plan limit are billed at $0.30 per 1,000,000 reads.',
          unit: 'reads',
          currentValue: 2_456_000,
          quota: quotas.databases.reads,
          timeSeries: generateTimeSeries(30, 81_867, 0.35, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'writes',
          name: 'Database Writes',
          description:
            'Total document write operations (create, update, delete) across all databases. Each mutation counts as one write. Writes beyond your plan limit are billed at $1.00 per 1,000,000 writes.',
          unit: 'writes',
          currentValue: 892_000,
          quota: quotas.databases.writes,
          timeSeries: generateTimeSeries(30, 29_733, 0.4, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'collections',
          name: 'Collections',
          description:
            'Total number of collections (tables) across all databases. Collections define your data schema and indexes. Additional collections beyond your plan limit require a plan upgrade.',
          unit: 'collections',
          currentValue: 47,
          quota: quotas.databases.collections,
          timeSeries: generateTimeSeries(30, 47, 0.02, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'documents',
          name: 'Total Documents',
          description:
            'Total number of documents stored across all collections. This represents your data volume. Document storage is not directly limited but contributes to your storage usage.',
          unit: 'documents',
          currentValue: 1_245_890,
          quota: quotas.databases.documents,
          timeSeries: generateTimeSeries(30, 1_245_890, 0.01, 'up'),
        },
      ],
    },
    {
      id: 'storage',
      label: 'Storage',
      icon: 'Folder',
      description:
        'File storage usage and operations for your storage buckets.',
      metrics: [
        {
          id: 'bytes-stored',
          name: 'Storage Used',
          description:
            'Total bytes stored across all buckets. This includes all uploaded files and their versions. Storage beyond your plan limit is billed at $0.03 per GB per month.',
          unit: 'GB',
          currentValue: 45.7,
          quota: quotas.storage.bytes
            ? quotas.storage.bytes / (1000 * 1000 * 1000)
            : null,
          timeSeries: generateTimeSeries(30, 45.7, 0.05, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'file-operations',
          name: 'File Operations',
          description:
            'Total file operations including uploads, downloads, and deletions. Each API call to the storage service counts as one operation. Additional operations are billed at $0.10 per 10,000 operations.',
          unit: 'operations',
          currentValue: 456_000,
          quota: quotas.storage.operations,
          timeSeries: generateTimeSeries(30, 15_200, 0.3, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
    {
      id: 'bandwidth',
      label: 'Bandwidth',
      icon: 'ArrowUpDown',
      description:
        'Network bandwidth consumption for API requests and file transfers.',
      metrics: [
        {
          id: 'egress',
          name: 'Bandwidth Egress',
          description:
            'Data transferred out from Appwrite to your users. This includes API responses, file downloads, and function outputs. Egress beyond your plan limit is billed at $0.09 per GB.',
          unit: 'GB',
          currentValue: 125.8,
          quota: quotas.bandwidth.egress
            ? quotas.bandwidth.egress / (1000 * 1000 * 1000)
            : null,
          timeSeries: generateTimeSeries(30, 4.2, 0.35, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'ingress',
          name: 'Bandwidth Ingress',
          description:
            'Data transferred into Appwrite from your users. This includes API requests, file uploads, and function inputs. Ingress is typically unlimited and not billed separately.',
          unit: 'GB',
          currentValue: 34.2,
          quota: quotas.bandwidth.ingress
            ? quotas.bandwidth.ingress / (1000 * 1000 * 1000)
            : null,
          timeSeries: generateTimeSeries(30, 1.14, 0.4, 'up'),
        },
      ],
    },
    {
      id: 'realtime',
      label: 'Realtime',
      icon: 'Radio',
      description: 'WebSocket connections for real-time data synchronization.',
      metrics: [
        {
          id: 'connections',
          name: 'Concurrent Connections',
          description:
            'Peak number of simultaneous WebSocket connections. This represents users actively subscribed to real-time updates. Connections beyond your plan limit may be queued or rejected.',
          unit: 'connections',
          currentValue: 342,
          quota: quotas.realtime.connections,
          timeSeries: generateTimeSeries(30, 285, 0.25, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
    {
      id: 'messaging',
      label: 'Messaging',
      icon: 'MessageSquare',
      description:
        'Push notifications, emails, and SMS messages sent through the messaging service.',
      metrics: [
        {
          id: 'messages',
          name: 'Messages Sent',
          description:
            'Total messages sent across all channels (push, email, SMS). Each notification or message counts toward this limit. Additional messages are billed based on the channel type.',
          unit: 'messages',
          currentValue: 23_450,
          quota: quotas.messaging.messages,
          timeSeries: generateTimeSeries(30, 782, 0.4, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'topics',
          name: 'Topics',
          description:
            'Number of messaging topics for organizing subscribers. Topics allow you to group users for targeted notifications. Additional topics beyond your plan limit require a plan upgrade.',
          unit: 'topics',
          currentValue: 24,
          quota: quotas.messaging.topics,
          timeSeries: generateTimeSeries(30, 24, 0.02, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'sms',
          name: 'SMS Messages',
          description:
            'SMS messages sent for authentication or notifications. SMS is billed separately at carrier rates. Each SMS segment (160 characters) counts as one message.',
          unit: 'messages',
          currentValue: 45,
          quota: quotas.messaging.sms,
          timeSeries: generateTimeSeries(30, 1.5, 0.6, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
    {
      id: 'avatars',
      label: 'Avatars',
      icon: 'UserCircle',
      description:
        'Avatars API usage for webpage screenshots and other generated assets.',
      metrics: [
        {
          id: 'screenshots',
          name: 'Screenshots Generated',
          description:
            'Webpage screenshots captured through the Avatars Screenshots API. Each successful request counts toward your monthly plan limit. Additional screenshots are billed per capture on paid plans.',
          unit: 'screenshots',
          currentValue: 1_240,
          quota: quotas.avatars.screenshots,
          timeSeries: generateTimeSeries(30, 41, 0.35, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
    {
      id: 'webhooks',
      label: 'Webhooks',
      icon: 'Webhook',
      description:
        'Webhook event deliveries and configured endpoints for Appwrite event notifications.',
      metrics: [
        {
          id: 'events-sent',
          name: 'Events Sent',
          description:
            'Webhook events successfully delivered to your endpoints. Each HTTP request sent counts as one event.',
          unit: 'events',
          currentValue: 12_840,
          quota: quotas.webhooks.eventsSent,
          timeSeries: generateTimeSeries(30, 428, 0.35, 'up'),
          thresholds: { warning: 75, critical: 90 },
        },
        {
          id: 'events-failed',
          name: 'Events Failed',
          description:
            'Webhook delivery failures including non-2xx responses and connection errors.',
          unit: 'events',
          currentValue: 42,
          quota: quotas.webhooks.eventsFailed,
          timeSeries: generateTimeSeries(30, 1.4, 0.5, 'stable'),
        },
        {
          id: 'webhooks',
          name: 'Webhooks',
          description:
            'Number of webhooks configured in your project. Each webhook can subscribe to multiple Appwrite events.',
          unit: 'webhooks',
          currentValue: 6,
          quota: quotas.webhooks.webhooks,
          timeSeries: generateTimeSeries(30, 6, 0.05, 'stable'),
          thresholds: { warning: 75, critical: 90 },
        },
      ],
    },
  ]

  return {
    categories,
    billingCycleStart,
    billingCycleEnd,
    plan,
  }
}

// Export a default instance for immediate use
export const mockUsageData = generateMockUsageData('pro')
