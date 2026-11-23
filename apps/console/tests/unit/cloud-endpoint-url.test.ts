import { describe, expect, test } from 'bun:test'
import { isCloudEndpointUrl } from '@/lib/debug-endpoint'

describe('isCloudEndpointUrl', () => {
  test('matches Appwrite Cloud and its regional endpoints', () => {
    expect(isCloudEndpointUrl('https://cloud.appwrite.io/v1')).toBe(true)
    expect(isCloudEndpointUrl('https://fra.cloud.appwrite.io/v1')).toBe(true)
  })

  test('does not match hosts that only contain the Cloud hostname', () => {
    expect(isCloudEndpointUrl('https://cloud.appwrite.io.example.com/v1')).toBe(
      false,
    )
    expect(isCloudEndpointUrl('https://example.com/cloud.appwrite.io/v1')).toBe(
      false,
    )
    expect(isCloudEndpointUrl('https://example.com/v1?cloud.appwrite.io')).toBe(
      false,
    )
    expect(isCloudEndpointUrl('https://appwrite.example.com/v1')).toBe(false)
  })
})
