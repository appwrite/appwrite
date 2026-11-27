import { describe, expect, test } from 'bun:test'
import {
  isPathInDirectory,
  normalizeRootDirectory,
  reviewTouchesDirectory,
} from '@/lib/review-paths'

const review = (...paths: string[]) => ({
  walkthrough: paths.map((path) => ({ path, change: 'Changes it.' })),
})

describe('normalizeRootDirectory', () => {
  test.each([
    ['./', ''],
    ['.', ''],
    ['', ''],
    ['/', ''],
    [undefined, ''],
    [null, ''],
    ['./functions/api', 'functions/api'],
    ['functions/api/', 'functions/api'],
    ['/apps/web', 'apps/web'],
    ['.//apps/web//', 'apps/web'],
    [' ./apps/web ', 'apps/web'],
  ])('%p is %p', (input, expected) => {
    expect(normalizeRootDirectory(input)).toBe(expected)
  })
})

describe('isPathInDirectory', () => {
  test('everything is in the repository root', () => {
    expect(isPathInDirectory('README.md', '')).toBe(true)
  })

  test('a file under the directory is in it', () => {
    expect(isPathInDirectory('apps/web/src/index.ts', 'apps/web')).toBe(true)
  })

  test('a sibling sharing the prefix is not in it', () => {
    expect(isPathInDirectory('apps/website/index.ts', 'apps/web')).toBe(false)
  })

  test('a file outside the directory is not in it', () => {
    expect(isPathInDirectory('functions/api/index.ts', 'apps/web')).toBe(false)
  })
})

describe('reviewTouchesDirectory', () => {
  test('a review changing a file in the directory touches it', () => {
    expect(
      reviewTouchesDirectory(
        review('README.md', 'functions/api/src/main.js'),
        'functions/api',
      ),
    ).toBe(true)
  })

  test('a review changing only other directories does not', () => {
    expect(
      reviewTouchesDirectory(review('apps/web/src/page.tsx'), 'functions/api'),
    ).toBe(false)
  })

  test('a review without a walkthrough does not belong to a subdirectory', () => {
    expect(reviewTouchesDirectory(review(), 'functions/api')).toBe(false)
  })

  test('a review without a walkthrough belongs to the repository root', () => {
    expect(reviewTouchesDirectory(review(), '')).toBe(true)
  })

  test('every review touches the repository root', () => {
    expect(reviewTouchesDirectory(review('apps/web/a.ts'), '')).toBe(true)
  })
})
