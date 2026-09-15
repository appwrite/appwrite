#!/usr/bin/env bun

import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

type ChangelogTag =
  // Products
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'messaging'
  | 'sites'
  | 'realtime'
  | 'firewall'
  // Developer Tools
  | 'mcp'
  | 'cli'
  | 'sdk'
  | 'api'
  // More
  | 'performance'
  | 'security'
  | 'infrastructure'
  | 'integrations'

const TAG_KEYWORDS: Record<ChangelogTag, string[]> = {
  // Products
  auth: ['auth', 'mfa', 'login', 'authentication', 'oauth', 'session', 'user', 'account', 'password', 'totp', 'factor', 'identity', 'identities', 'sign-in', 'sign-up'],
  databases: ['database', 'tables', 'documents', 'vectors', 'collection', 'mysql', 'postgres', 'mongodb', 'sql', 'query', 'queries', 'attribute', 'index', 'indexes', 'relationship', 'tablesdb', 'documentsdb', 'vectorsdb'],
  storage: ['storage', 'bucket', 'file', 'upload', 'download', 'preview', 's3'],
  functions: ['function', 'execution', 'runtime', 'cron', 'scheduled', 'trigger', 'invoke', 'serverless', 'template'],
  messaging: ['messaging', 'email', 'sms', 'push', 'notification', 'provider', 'topic', 'subscriber', 'message', 'whatsapp'],
  sites: ['site', 'website', 'hosting', 'build', 'static', 'next.js', 'react', 'vue', 'svelte', 'astro'],
  realtime: ['realtime', 'websocket', 'subscription', 'live', 'streaming'],
  firewall: ['firewall', 'ddos', 'waf', 'rate limit', 'abuse'],
  // Developer Tools
  mcp: ['mcp', 'model context protocol'],
  cli: ['cli', 'command-line', 'terminal', 'command'],
  sdk: ['sdk', 'client', 'server sdk', 'typescript', 'python', 'dart', 'flutter', 'swift', 'kotlin', 'java', 'php', 'ruby', 'go', 'dotnet', 'node'],
  api: ['api', 'endpoint', 'rest', 'graphql', 'webhook', 'request', 'response'],
  // More
  performance: ['performance', 'faster', 'speed', 'optimization', 'cache', 'cold start', 'latency', 'memory', 'squashfs', 'compression'],
  security: ['security', 'encryption', 'ssl', 'tls', 'certificate', 'gdpr', 'compliance'],
  infrastructure: ['infrastructure', 'region', 'cloud', 'network', 'edge', 'cdn', 'deployment'],
  integrations: ['integration', 'stripe', 'openai', 'anthropic', 'github', 'gitlab'],
}

function detectTags(content: string, title: string): ChangelogTag[] {
  const textToAnalyze = `${title} ${content}`.toLowerCase()
  const detectedTags = new Set<ChangelogTag>()

  for (const [tag, keywords] of Object.entries(TAG_KEYWORDS)) {
    for (const keyword of keywords) {
      if (textToAnalyze.includes(keyword)) {
        detectedTags.add(tag as ChangelogTag)
        break
      }
    }
  }

  return Array.from(detectedTags).sort()
}

function updateChangelogEntry(filePath: string): void {
  const content = readFileSync(filePath, 'utf-8')
  
  // Check if already has tags
  if (content.includes('\ntags:')) {
    console.log(`Skipping ${filePath} - already has tags`)
    return
  }

  // Parse frontmatter
  const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/)
  if (!match) {
    console.log(`Skipping ${filePath} - no frontmatter found`)
    return
  }

  const [, frontmatter, body] = match
  
  // Extract title
  const titleMatch = frontmatter.match(/^title:\s*['"]?(.+?)['"]?$/m)
  const title = titleMatch ? titleMatch[1] : ''
  
  // Detect tags
  const tags = detectTags(body, title)
  
  if (tags.length === 0) {
    console.log(`No tags detected for ${filePath}`)
    return
  }

  // Add tags to frontmatter
  const updatedFrontmatter = `${frontmatter}\ntags: ${tags.join(', ')}`
  const updatedContent = `---\n${updatedFrontmatter}\n---\n${body}`
  
  writeFileSync(filePath, updatedContent, 'utf-8')
  console.log(`✓ Tagged ${filePath} with: ${tags.join(', ')}`)
}

function processDirectory(dirPath: string): void {
  try {
    const files = readdirSync(dirPath)
    
    for (const file of files) {
      if (file.endsWith('.markdoc')) {
        const filePath = join(dirPath, file)
        try {
          updateChangelogEntry(filePath)
        } catch (error) {
          console.error(`Error processing ${filePath}:`, error)
        }
      }
    }
  } catch (error) {
    console.error(`Error reading directory ${dirPath}:`, error)
  }
}

console.log('Tagging changelog entries...\n')
processDirectory('./src/content/changelog/entries')
console.log('\nDone!')
