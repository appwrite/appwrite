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
    expect(describeTriggerBehavior(['main', 'feat/*'], [])).toBe(
      'Only branches matching "main", "feat/*" trigger deployments on all file changes.',
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

  test('later patterns take precedence, as the server matches them', () => {
    // An exclusion before any inclusion changes nothing
    expect(describeTriggerBehavior([], ['!src/**', 'src/generated/**'])).toBe(
      'All branches trigger deployments on only paths matching "src/generated/**".',
    )
    expect(
      describeTriggerBehavior(
        [],
        ['src/**', '!src/legacy/**', 'src/legacy/keep/**'],
      ),
    ).toBe(
      'All branches trigger deployments on only paths matching "src/**", except "src/legacy/**", but including "src/legacy/keep/**".',
    )
  })

  test('literal inclusions always trigger', () => {
    expect(
      describeTriggerBehavior(['main', 'release/*', '!release/old'], []),
    ).toBe(
      'Only branches matching "release/*", except "release/old", and always "main" trigger deployments on all file changes.',
    )
  })

  test('an exclusion reads as the server reads it', () => {
    expect(describeTriggerBehavior([], ['! src'])).toBe(
      'All branches trigger deployments on changes outside " src".',
    )
  })
})
