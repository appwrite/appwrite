import { describe, expect, test } from 'bun:test'
import { isAppwriteApiRequest } from '@/lib/cli-shell/fetch-bridge'

const endpoints = [
  'https://cloud.appwrite.io/v1',
  'https://fra.cloud.appwrite.io/v1',
]

describe('isAppwriteApiRequest', () => {
  test('bridges API calls on the console endpoints', () => {
    for (const url of [
      'https://cloud.appwrite.io/v1/account',
      'https://fra.cloud.appwrite.io/v1/functions?queries[]=x',
      'https://fra.cloud.appwrite.io/v1',
    ]) {
      expect(isAppwriteApiRequest(url, endpoints)).toBe(true)
    }
  })

  test('leaves every other request alone', () => {
    for (const url of [
      'https://api.example.com/v1/oauth/token',
      'https://mcp.example.com/v1/account',
      'http://cloud.appwrite.io/v1/account',
      'https://cloud.appwrite.io:8443/v1/account',
      'https://nyc.cloud.appwrite.io/v1/account',
      'https://cloud.appwrite.io/assets/app.js',
      'https://cloud.appwrite.io/v10/account',
      '/v1/account',
    ]) {
      expect(isAppwriteApiRequest(url, endpoints)).toBe(false)
    }
  })
})
