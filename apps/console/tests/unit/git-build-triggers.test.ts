import { describe, expect, test } from 'bun:test'
import { describeTriggerBehavior } from '@/lib/git-build-triggers'

describe('Build trigger summary', () => {
  test('no patterns trigger on every branch and change', () => {
    expect(describeTriggerBehavior([], [])).toBe(
      'All branches trigger deployments on all file changes.',
    )
  })

  test('inclusions name what triggers', () => {
    expect(describeTriggerBehavior(['main'], ['src/**'])).toBe(
      'Only branches matching "main" trigger deployments on only paths matching "src/**".',
    )
  })

  test('exclusions alone leave everything else triggering', () => {
    expect(describeTriggerBehavior([], ['!functions/**'])).toBe(
      'All branches trigger deployments on changes outside "functions/**".',
    )
    expect(describeTriggerBehavior(['!dependabot/**'], [])).toBe(
      'All branches except "dependabot/**" trigger deployments on all file changes.',
    )
  })

  test('exclusions narrow inclusions', () => {
    expect(
      describeTriggerBehavior(
        ['release/*', '!release/old'],
        ['src/**', '!src/legacy/**'],
      ),
    ).toBe(
      'Only branches matching "release/*", except "release/old" trigger deployments on only paths matching "src/**", except "src/legacy/**".',
    )
  })
})
