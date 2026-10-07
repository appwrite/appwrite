import { type Models } from 'node-appwrite'

export type Activities = Models.Row & {
  createdBy: string
  userId: string
  userName: string | null
  userEmail: string | null
  action: string
  resourceType: string
  resourceId: string | null
  resourceName: string | null
  description: string | null
  metadata: string | null
  ipAddress: string | null
  timestamp: string
}
