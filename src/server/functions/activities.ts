import { createServerFn } from '@tanstack/react-start'
import { z } from 'zod'
import { Query } from 'node-appwrite'
import { db } from '@/server/lib/db'
import type { Activities } from '@/server/lib/appwrite.types'

// Plan-based time limits in milliseconds
const PLAN_TIME_LIMITS = {
  free: 1 * 60 * 60 * 1000, // 1 hour
  pro: 30 * 24 * 60 * 60 * 1000, // 30 days
  core: 30 * 24 * 60 * 60 * 1000, // 30 days
  custom: 30 * 24 * 60 * 60 * 1000, // 30 days
} as const

export type PlanType = keyof typeof PLAN_TIME_LIMITS

const listActivitiesSchema = z.object({
  plan: z.enum(['free', 'pro', 'core', 'custom']).default('free'),
  action: z.string().optional(),
  resourceType: z.string().optional(),
  userId: z.string().optional(),
  search: z.string().optional(),
  limit: z.number().min(1).max(100).default(50),
  offset: z.number().min(0).default(0),
})

export type ListActivitiesInput = z.infer<typeof listActivitiesSchema>

export interface ActivityWithMeta extends Activities {
  withinLimit: boolean
}

export interface ListActivitiesResponse {
  activities: ActivityWithMeta[]
  total: number
  plan: PlanType
  timeLimit: number
  cutoffDate: string
}

export const listActivitiesFn = createServerFn({ method: 'GET' })
  .inputValidator(listActivitiesSchema)
  .handler(async ({ data }): Promise<ListActivitiesResponse> => {
    const { plan, action, resourceType, userId, search, limit, offset } = data

    // Calculate cutoff date based on plan
    const timeLimit = PLAN_TIME_LIMITS[plan]
    const cutoffDate = new Date(Date.now() - timeLimit)
    const cutoffTimestamp = cutoffDate.toISOString()

    // Build queries
    const queries: string[] = [
      Query.greaterThanEqual('timestamp', cutoffTimestamp),
      Query.orderDesc('timestamp'),
      Query.limit(limit),
      Query.offset(offset),
    ]

    // Add filters
    if (action) {
      queries.push(Query.equal('action', action))
    }

    if (resourceType) {
      queries.push(Query.equal('resourceType', resourceType))
    }

    if (userId) {
      queries.push(Query.equal('userId', userId))
    }

    if (search) {
      queries.push(Query.search('resourceName', search))
    }

    try {
      const result = await db.activities.list(queries)

      const activities: ActivityWithMeta[] = result.rows.map((activity) => ({
        ...activity,
        withinLimit: new Date(activity.timestamp) >= cutoffDate,
      }))

      return {
        activities,
        total: result.total,
        plan,
        timeLimit,
        cutoffDate: cutoffTimestamp,
      }
    } catch (error) {
      // Return empty result if table doesn't exist or other error
      console.error('Error fetching activities:', error)
      return {
        activities: [],
        total: 0,
        plan,
        timeLimit,
        cutoffDate: cutoffTimestamp,
      }
    }
  })

// Get unique filter options
export const getActivityFilterOptionsFn = createServerFn({
  method: 'GET',
}).handler(async () => {
  const actions = [
    { value: 'create', label: 'Created' },
    { value: 'update', label: 'Updated' },
    { value: 'delete', label: 'Deleted' },
    { value: 'execute', label: 'Executed' },
    { value: 'upload', label: 'Uploaded' },
    { value: 'login', label: 'Logged in' },
    { value: 'logout', label: 'Logged out' },
    { value: 'view', label: 'Viewed' },
  ]

  const resourceTypes = [
    { value: 'document', label: 'Document' },
    { value: 'collection', label: 'Collection' },
    { value: 'database', label: 'Database' },
    { value: 'file', label: 'File' },
    { value: 'bucket', label: 'Bucket' },
    { value: 'function', label: 'Function' },
    { value: 'user', label: 'User' },
    { value: 'team', label: 'Team' },
    { value: 'project', label: 'Project' },
  ]

  return { actions, resourceTypes }
})
