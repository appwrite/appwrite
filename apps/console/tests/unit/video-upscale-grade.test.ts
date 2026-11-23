import { describe, expect, test } from 'bun:test'
import { gradeUpscale } from '@/components/pages/projects/$projectId/videos/_components/player/useQoeTracker'

describe('gradeUpscale', () => {
  test.each([
    [null, null],
    [0.5, 'good'],
    [1.1, 'good'],
    [1.46, 'fair'],
    [1.6, 'fair'],
    [1.61, 'poor'],
    [3, 'poor'],
  ] as const)('grades %p as %p', (upscale, grade) => {
    expect(gradeUpscale(upscale)).toBe(grade)
  })
})
