import { describe, expect, test } from 'bun:test'
import { isFirewallMovedProject } from '@/lib/console-banners/firewall-moved'

const NOW = new Date('2026-11-20T12:00:00.000Z')

describe('isFirewallMovedProject', () => {
  test('explains the move in projects at least three days old', () => {
    expect(isFirewallMovedProject('2026-11-17T12:00:00.000Z', NOW)).toBe(true)
    expect(isFirewallMovedProject('2025-01-01T00:00:00.000Z', NOW)).toBe(true)
  })

  test('skips newer projects, which only ever had the tabs', () => {
    expect(isFirewallMovedProject('2026-11-17T12:00:00.001Z', NOW)).toBe(false)
    expect(isFirewallMovedProject('2026-11-20T11:00:00.000Z', NOW)).toBe(false)
  })

  test('skips a project whose creation date is unknown', () => {
    expect(isFirewallMovedProject(undefined, NOW)).toBe(false)
    expect(isFirewallMovedProject('not a date', NOW)).toBe(false)
  })
})
