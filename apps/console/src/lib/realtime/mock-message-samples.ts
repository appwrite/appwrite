import type { RealtimeMessageLog } from '@/lib/realtime/session-client'

export type MockMessageSampleId =
  | 'table-row-update'
  | 'user-update'
  | 'file-create'
  | 'connected'

export type MockMessageSampleOption = {
  id: MockMessageSampleId
  label: string
  description: string
}

export const MOCK_MESSAGE_SAMPLE_OPTIONS: MockMessageSampleOption[] = [
  {
    id: 'table-row-update',
    label: 'Table row update',
    description: 'Incoming event after a row is updated',
  },
  {
    id: 'user-update',
    label: 'User update',
    description: 'Incoming event after a user record changes',
  },
  {
    id: 'file-create',
    label: 'File created',
    description: 'Incoming event after a storage file is uploaded',
  },
  {
    id: 'connected',
    label: 'Connected frame',
    description: 'Server handshake after the WebSocket opens',
  },
]

export function createMockMessageLog(id: MockMessageSampleId): RealtimeMessageLog {
  switch (id) {
    case 'table-row-update':
      return createTableRowUpdateEvent()
    case 'user-update':
      return createUserUpdateEvent()
    case 'file-create':
      return createFileCreateEvent()
    case 'connected':
      return createConnectedFrame()
    default: {
      const exhaustive: never = id
      return exhaustive
    }
  }
}

function createTableRowUpdateEvent(): RealtimeMessageLog {
  const timestamp = new Date().toISOString()
  const rowId = '674a1c002b8f4e5a9001'
  const tableId = 'tasks'
  const databaseId = 'default'
  const rowChannel = `tablesdb.${databaseId}.tables.${tableId}.rows`

  return {
    direction: 'in',
    timestamp,
    message: {
      type: 'event',
      data: {
        events: [
          `${rowChannel}.${rowId}.update`,
          `${rowChannel}.${rowId}`,
          `${rowChannel}.*.update`,
          'tablesdb.*.tables.*.rows.*.update',
        ],
        channels: ['rows', rowChannel, `${rowChannel}.${rowId}`],
        subscriptions: ['a1b2c3d4e5'],
        timestamp,
        payload: {
          title: 'Review pull request',
          status: 'in_progress',
          priority: 2,
          completed: false,
          dueDate: '2024-03-15T09:00:00.000+00:00',
          $id: rowId,
          $createdAt: '2024-03-01T14:22:11.715+00:00',
          $updatedAt: timestamp,
          $permissions: ['read("any")', 'update("users")'],
          $tableId: tableId,
          $databaseId: databaseId,
        },
      },
    },
  }
}

function createUserUpdateEvent(): RealtimeMessageLog {
  const timestamp = new Date().toISOString()
  const userId = '674a1c002b8f4e5a9002'

  return {
    direction: 'in',
    timestamp,
    message: {
      type: 'event',
      data: {
        events: [
          `users.${userId}.update`,
          `users.${userId}`,
          'users.*.update',
          'users.*',
        ],
        channels: ['users', `users.${userId}`],
        subscriptions: ['f6g7h8i9j0'],
        timestamp,
        payload: {
          $id: userId,
          name: 'Alex Rivera',
          email: 'alex@example.com',
          emailVerification: true,
          phone: '',
          status: true,
          prefs: { theme: 'dark', notifications: true },
          accessedAt: timestamp,
          $createdAt: '2024-01-10T08:15:00.000+00:00',
          $updatedAt: timestamp,
        },
      },
    },
  }
}

function createFileCreateEvent(): RealtimeMessageLog {
  const timestamp = new Date().toISOString()
  const fileId = '674a1c003c9a5f6b0112'
  const bucketId = 'uploads'
  const filesChannel = `buckets.${bucketId}.files`

  return {
    direction: 'in',
    timestamp,
    message: {
      type: 'event',
      data: {
        events: [
          `${filesChannel}.${fileId}.create`,
          `${filesChannel}.${fileId}`,
          `${filesChannel}.*.create`,
          'buckets.*.files.*.create',
        ],
        channels: ['files', filesChannel, `${filesChannel}.${fileId}`],
        subscriptions: ['k1l2m3n4o5'],
        timestamp,
        payload: {
          $id: fileId,
          bucketId,
          name: 'hero-banner.png',
          signature: 'abc123def4567890',
          mimeType: 'image/png',
          sizeOriginal: 245760,
          $createdAt: timestamp,
          $updatedAt: timestamp,
          $permissions: ['read("any")'],
        },
      },
    },
  }
}

function createConnectedFrame(): RealtimeMessageLog {
  return {
    direction: 'in',
    timestamp: new Date().toISOString(),
    message: {
      type: 'connected',
      data: {},
    },
  }
}
