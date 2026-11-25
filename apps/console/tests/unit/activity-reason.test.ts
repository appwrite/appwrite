import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { getActivityReason } from '@/components/pages/projects/$projectId/activity/activity-utils'

function event(fields: Record<string, unknown>): Models.ActivityEvent {
  return fields as unknown as Models.ActivityEvent
}

describe('activity reason', () => {
  test('reads the reason the actor sent', () => {
    expect(
      getActivityReason(event({ reason: 'Reported as spam by 25 users' })),
    ).toBe('Reported as spam by 25 users')
  })

  test('trims surrounding whitespace', () => {
    expect(getActivityReason(event({ reason: '  Moved to SSR  ' }))).toBe(
      'Moved to SSR',
    )
  })

  test('is empty when no reason was given', () => {
    expect(getActivityReason(event({}))).toBe('')
    expect(getActivityReason(event({ reason: '' }))).toBe('')
  })
})
