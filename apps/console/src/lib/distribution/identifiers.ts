import type { DistributionPlatform } from '@/lib/appwrite/distribution'

const IDENTIFIER_MAX_LENGTH = 256

function isLetter(character: string): boolean {
  return (
    (character >= 'a' && character <= 'z') ||
    (character >= 'A' && character <= 'Z')
  )
}

function isDigit(character: string): boolean {
  return character >= '0' && character <= '9'
}

function isBundleSegment(segment: string): boolean {
  return (
    segment.length > 0 &&
    [...segment].every(
      (character) =>
        isLetter(character) || isDigit(character) || character === '-',
    )
  )
}

function isPackageSegment(segment: string): boolean {
  const [first, ...rest] = segment
  return (
    first !== undefined &&
    isLetter(first) &&
    rest.every(
      (character) =>
        isLetter(character) || isDigit(character) || character === '_',
    )
  )
}

export function isValidIdentifier(
  platform: DistributionPlatform,
  identifier: string,
): boolean {
  if (identifier.length === 0 || identifier.length > IDENTIFIER_MAX_LENGTH) {
    return false
  }
  const segments = identifier.split('.')
  if (platform === 'ios') return segments.every(isBundleSegment)
  return segments.length >= 2 && segments.every(isPackageSegment)
}

const IDENTIFIER_LABELS: Record<DistributionPlatform, string> = {
  ios: 'Bundle ID',
  android: 'Package name',
}

const IDENTIFIER_HINTS: Record<DistributionPlatform, string> = {
  ios: 'Letters, numbers, hyphens and periods, as registered in your Apple Developer account.',
  android:
    'At least two parts separated by periods, each starting with a letter, as in your Gradle applicationId.',
}

export function identifierLabel(platform: DistributionPlatform): string {
  return IDENTIFIER_LABELS[platform]
}

export function identifierHint(platform: DistributionPlatform): string {
  return IDENTIFIER_HINTS[platform]
}
