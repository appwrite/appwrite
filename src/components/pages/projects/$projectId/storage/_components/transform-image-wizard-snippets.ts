import { ImageFormat, ImageGravity } from '@appwrite.io/console'
import type { CodeEditorLanguage } from '@/components/global/shared/CodeEditor'
import type { ImageTransformState } from './transform-image-wizard-state'

export type TransformImageCodeSdkId =
  | 'web'
  | 'flutter'
  | 'react_native'
  | 'apple'
  | 'android'
  | 'deno'
  | 'node'
  | 'python'
  | 'php'
  | 'ruby'
  | 'dotnet'
  | 'go'

export type TransformImageCodeSdkOption = {
  id: TransformImageCodeSdkId
  label: string
  language: CodeEditorLanguage
  modelPath: string
}

export const TRANSFORM_IMAGE_CODE_SDK_OPTIONS: readonly TransformImageCodeSdkOption[] =
  [
    {
      id: 'web',
      label: 'Web SDK',
      language: 'javascript',
      modelPath: 'inmemory://transform-image-wizard/web.js',
    },
    {
      id: 'flutter',
      label: 'Flutter SDK',
      language: 'dart',
      modelPath: 'inmemory://transform-image-wizard/flutter.dart',
    },
    {
      id: 'react_native',
      label: 'React Native',
      language: 'typescript',
      modelPath: 'inmemory://transform-image-wizard/react-native.ts',
    },
    {
      id: 'apple',
      label: 'Apple',
      language: 'swift',
      modelPath: 'inmemory://transform-image-wizard/apple.swift',
    },
    {
      id: 'android',
      label: 'Android',
      language: 'kotlin',
      modelPath: 'inmemory://transform-image-wizard/android.kt',
    },
    {
      id: 'deno',
      label: 'Deno',
      language: 'typescript',
      modelPath: 'inmemory://transform-image-wizard/deno.ts',
    },
    {
      id: 'node',
      label: 'Node.js',
      language: 'typescript',
      modelPath: 'inmemory://transform-image-wizard/node.ts',
    },
    {
      id: 'python',
      label: 'Python',
      language: 'python',
      modelPath: 'inmemory://transform-image-wizard/preview.py',
    },
    {
      id: 'php',
      label: 'PHP',
      language: 'php',
      modelPath: 'inmemory://transform-image-wizard/preview.php',
    },
    {
      id: 'ruby',
      label: 'Ruby',
      language: 'ruby',
      modelPath: 'inmemory://transform-image-wizard/preview.rb',
    },
    {
      id: 'dotnet',
      label: '.NET',
      language: 'csharp',
      modelPath: 'inmemory://transform-image-wizard/preview.cs',
    },
    {
      id: 'go',
      label: 'Go',
      language: 'go',
      modelPath: 'inmemory://transform-image-wizard/preview.go',
    },
  ] as const

function gravityEnumKeyForWeb(g: ImageGravity): string {
  const entry = (
    Object.keys(ImageGravity) as (keyof typeof ImageGravity)[]
  ).find((k) => ImageGravity[k] === g)
  return entry ?? 'Center'
}

function imageFormatEnumKeyForSdk(v: ImageFormat): string | null {
  const entry = (
    Object.keys(ImageFormat) as (keyof typeof ImageFormat)[]
  ).find((k) => ImageFormat[k] === v)
  return entry ?? null
}

function gravityRestString(g: ImageGravity): string {
  return String(g)
}

function gravityPhpRubyMethodSuffix(g: ImageGravity): string {
  const k = gravityEnumKeyForWeb(g)
  const map: Record<string, string> = {
    Center: 'CENTER',
    Topleft: 'TOP_LEFT',
    Top: 'TOP',
    Topright: 'TOP_RIGHT',
    Left: 'LEFT',
    Right: 'RIGHT',
    Bottomleft: 'BOTTOM_LEFT',
    Bottom: 'BOTTOM',
    Bottomright: 'BOTTOM_RIGHT',
  }
  return map[k] ?? 'CENTER'
}

function pythonImageFormatRef(s: ImageTransformState): string | null {
  if (!s.output) return null
  const k = imageFormatEnumKeyForSdk(s.output)
  if (!k) return null
  const upper: Record<string, string> = {
    Jpg: 'JPG',
    Jpeg: 'JPG',
    Png: 'PNG',
    Gif: 'GIF',
    Webp: 'WEBP',
    Avif: 'AVIF',
    Heic: 'HEIC',
  }
  return `ImageFormat.${upper[k] ?? k.toUpperCase()}`
}

function pythonGravityRef(g: ImageGravity): string {
  return `ImageGravity.${gravityPhpRubyMethodSuffix(g)}`
}

function dotnetImageFormatMember(s: ImageTransformState): string | null {
  if (!s.output) return null
  const k = imageFormatEnumKeyForSdk(s.output)
  if (!k) return null
  return `ImageFormat.${k}`
}

function goOutputString(s: ImageTransformState): string | null {
  if (!s.output) return null
  return String(s.output).toLowerCase()
}

function escapeDartString(s: string): string {
  return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")
}

function pushCommonJsPreviewFields(
  lines: string[],
  s: ImageTransformState,
  bucketId: string,
  fileId: string,
): void {
  const gKey = gravityEnumKeyForWeb(s.gravity)
  lines.push(`  bucketId: ${JSON.stringify(bucketId)},`)
  lines.push(`  fileId: ${JSON.stringify(fileId)},`)
  if (s.width !== null) lines.push(`  width: ${s.width},`)
  if (s.height !== null) lines.push(`  height: ${s.height},`)
  lines.push(`  gravity: ImageGravity.${gKey},`)
  lines.push(`  quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`  borderWidth: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `  borderColor: ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`  borderRadius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`  opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`  rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `  background: ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  if (s.output) {
    const outKey = imageFormatEnumKeyForSdk(s.output)
    if (outKey) lines.push(`  output: ImageFormat.${outKey},`)
  }
}

export function buildWebSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const lines: string[] = [
    `import { Client, Storage, ImageFormat, ImageGravity } from 'appwrite'`,
    ``,
    `// Full example: Appwrite client + Storage.getFilePreview for this project and file.`,
    `// - Endpoint is the regional API URL for this console session (adjust if self-hosted).`,
    `// - Browser: authenticate first (e.g. Account.createEmailPasswordSession, OAuth, etc.).`,
    `// - Server: client.setKey(process.env.APPWRITE_API_KEY) - never expose API keys in client code.`,
    ``,
    `const client = new Client()`,
    `  .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `  .setProject(${JSON.stringify(projectId)})`,
    ``,
    `const storage = new Storage(client)`,
    ``,
    `const previewUrl = storage.getFilePreview({`,
  ]
  pushCommonJsPreviewFields(lines, s, bucketId, fileId)
  lines.push(`})`, ``)
  lines.push(`// Example (React): <img src={previewUrl} alt="" />`)
  lines.push(
    `// Example (fetch, cookies/session): await fetch(previewUrl, { credentials: 'include' })`,
  )
  return lines.join('\n')
}

export function buildNodeSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const lines: string[] = [
    `import { Client, Storage, ImageFormat, ImageGravity } from 'node-appwrite'`,
    ``,
    `// Node.js (server): same preview URL builder as the Web SDK, using node-appwrite.`,
    `// Use setKey with your API key (or setSession for user context). Never ship keys to clients.`,
    ``,
    `const client = new Client()`,
    `  .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `  .setProject(${JSON.stringify(projectId)})`,
    `  .setKey(process.env.APPWRITE_API_KEY ?? '')`,
    ``,
    `const storage = new Storage(client)`,
    ``,
    `const previewUrl = storage.getFilePreview({`,
  ]
  pushCommonJsPreviewFields(lines, s, bucketId, fileId)
  lines.push(`})`, ``, `// previewUrl is a string URL; use fetch(previewUrl) or pass to clients as needed.`)
  return lines.join('\n')
}

export function buildDenoSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const lines: string[] = [
    `import { Client, Storage, ImageFormat, ImageGravity } from 'npm:node-appwrite'`,
    ``,
    `// Deno: import from npm specifiers. Run with env access, e.g. deno run --allow-env --allow-net main.ts`,
    ``,
    `const client = new Client()`,
    `  .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `  .setProject(${JSON.stringify(projectId)})`,
    `  .setKey(Deno.env.get('APPWRITE_API_KEY') ?? '')`,
    ``,
    `const storage = new Storage(client)`,
    ``,
    `const previewUrl = storage.getFilePreview({`,
  ]
  pushCommonJsPreviewFields(lines, s, bucketId, fileId)
  lines.push(`})`)
  return lines.join('\n')
}

export function buildFlutterSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const ep = escapeDartString(apiEndpoint)
  const pid = escapeDartString(projectId)
  const bid = escapeDartString(bucketId)
  const fid = escapeDartString(fileId)
  const lines: string[] = [
    `import 'package:appwrite/appwrite.dart';`,
    ``,
    `// Add appwrite to pubspec.yaml, then run dart pub get.`,
    `// Browser / Flutter app: sign in first (e.g. Account.createEmailSession).`,
    `// Server / isolate: client.setKey(Platform.environment['APPWRITE_API_KEY']!) - never in client apps.`,
    ``,
    `Future<void> example() async {`,
    `  final client = Client()`,
    `    ..setEndpoint('${ep}')`,
    `    ..setProject('${pid}');`,
    ``,
    `  final storage = Storage(client);`,
    ``,
    `  final String previewUrl = storage.getFilePreview(`,
    `    bucketId: '${bid}',`,
    `    fileId: '${fid}',`,
  ]
  if (s.width !== null) lines.push(`    width: ${s.width},`)
  if (s.height !== null) lines.push(`    height: ${s.height},`)
  lines.push(`    gravity: '${escapeDartString(String(s.gravity))}',`)
  lines.push(`    quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    borderWidth: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    borderColor: '${escapeDartString(s.borderColor.replace(/^#/, ''))}',`,
    )
  if (s.borderRadius > 0) lines.push(`    borderRadius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background: '${escapeDartString(s.background.replace(/^#/, ''))}',`,
    )
  if (s.output) {
    const outKey = imageFormatEnumKeyForSdk(s.output)
    if (outKey) lines.push(`    output: ImageFormat.${outKey},`)
  }
  lines.push(
    `  );`,
    ``,
    `  // Example: Image.network(previewUrl)`,
    `  // ignore: avoid_print`,
    `  print(previewUrl);`,
    `}`,
  )
  return lines.join('\n')
}

export function buildReactNativeSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const gKey = gravityEnumKeyForWeb(s.gravity)
  const outStr = s.output ? String(s.output).toLowerCase() : 'jpg'
  const lines: string[] = [
    `import { Client, Storage, ImageFormat, ImageGravity } from 'react-native-appwrite';`,
    `import { Image } from 'react-native';`,
    ``,
    `// react-native-appwrite uses positional arguments for getFilePreview (see Appwrite docs).`,
    ``,
    `const client = new Client()`,
    `  .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `  .setProject(${JSON.stringify(projectId)});`,
    ``,
    `const storage = new Storage(client);`,
    ``,
    `// bucketId, fileId, width, height, gravity, quality, borderWidth, borderColor, borderRadius, opacity, rotation, background, output`,
    `const previewUrl = storage.getFilePreview(`,
    `  ${JSON.stringify(bucketId)},`,
    `  ${JSON.stringify(fileId)},`,
    `  ${s.width ?? 0},`,
    `  ${s.height ?? 0},`,
    `  ImageGravity.${gKey},`,
    `  ${s.quality},`,
    `  ${s.borderWidth},`,
    `  ${JSON.stringify(s.borderColor.replace(/^#/, '') || '')},`,
    `  ${s.borderRadius},`,
    `  ${s.opacity},`,
    `  ${s.rotation},`,
    `  ${JSON.stringify(s.background.replace(/^#/, '') || '')},`,
    `  ${JSON.stringify(outStr)},`,
    `);`,
    ``,
    `// Example: <Image source={{ uri: String(previewUrl) }} style={{ width: 300, height: 200 }} resizeMode="contain" />`,
  ]
  return lines.join('\n')
}

export function buildAppleSwiftSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const grav = gravityRestString(s.gravity)
  const lines: string[] = [
    `import Appwrite`,
    ``,
    `// Apple (iOS / macOS): async getFilePreview returns image data; sign in on the client first.`,
    ``,
    `func loadPreview() async throws {`,
    `    let client = Client()`,
    `        .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `        .setProject(${JSON.stringify(projectId)})`,
    `    let storage = Storage(client)`,
    `    let data = try await storage.getFilePreview(`,
    `        bucketId: ${JSON.stringify(bucketId)},`,
    `        fileId: ${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`        width: ${s.width},`)
  if (s.height !== null) lines.push(`        height: ${s.height},`)
  lines.push(`        gravity: ${JSON.stringify(grav)},`)
  lines.push(`        quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`        borderWidth: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `        borderColor: ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`        borderRadius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`        opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`        rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `        background: ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  if (s.output) {
    const o = String(s.output).toLowerCase()
    lines.push(`        output: ${JSON.stringify(o)},`)
  }
  lines.push(`    )`, `    // Use data with UIImage(data:) / NSImage`, `}`)
  return lines.join('\n')
}

export function buildAndroidKotlinSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const grav = gravityRestString(s.gravity)
  const lines: string[] = [
    `import io.appwrite.Client`,
    `import io.appwrite.services.Storage`,
    ``,
    `// Android (Kotlin): getFilePreview returns a URL string when using the Android SDK (see Appwrite docs).`,
    ``,
    `val client = Client(context)`,
    `    .setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `    .setProject(${JSON.stringify(projectId)})`,
    `val storage = Storage(client)`,
    `val previewUrl = storage.getFilePreview(`,
    `    bucketId = ${JSON.stringify(bucketId)},`,
    `    fileId = ${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`    width = ${s.width},`)
  if (s.height !== null) lines.push(`    height = ${s.height},`)
  lines.push(`    gravity = ${JSON.stringify(grav)},`)
  lines.push(`    quality = ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    borderWidth = ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    borderColor = ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`    borderRadius = ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity = ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation = ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background = ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  if (s.output) {
    lines.push(`    output = ${JSON.stringify(String(s.output).toLowerCase())},`)
  }
  lines.push(`)`)
  return lines.join('\n')
}

export function buildPythonSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const lines: string[] = [
    `from appwrite.client import Client`,
    `from appwrite.services.storage import Storage`,
    `from appwrite.enums import ImageGravity`,
    `from appwrite.enums import ImageFormat`,
    ``,
    `# Python (server): get_file_preview returns bytes. Use set_key for API key or set_session for user JWT.`,
    ``,
    `client = Client()`,
    `client.set_endpoint(${JSON.stringify(apiEndpoint)})`,
    `client.set_project(${JSON.stringify(projectId)})`,
    `client.set_key("<YOUR_API_KEY>")`,
    ``,
    `storage = Storage(client)`,
    ``,
    `result: bytes = storage.get_file_preview(`,
    `    bucket_id=${JSON.stringify(bucketId)},`,
    `    file_id=${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`    width=${s.width},`)
  if (s.height !== null) lines.push(`    height=${s.height},`)
  lines.push(`    gravity=${pythonGravityRef(s.gravity)},`)
  lines.push(`    quality=${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    border_width=${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    border_color=${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`    border_radius=${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity=${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation=${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background=${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  const pfmt = pythonImageFormatRef(s)
  if (pfmt) lines.push(`    output=${pfmt},`)
  lines.push(`)`, `print(len(result))`)
  return lines.join('\n')
}

export function buildPhpSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const gx = gravityPhpRubyMethodSuffix(s.gravity)
  const lines: string[] = [
    `<?php`,
    ``,
    `use Appwrite\\Client;`,
    `use Appwrite\\Services\\Storage;`,
    `use Appwrite\\Enums\\ImageGravity;`,
    `use Appwrite\\Enums\\ImageFormat;`,
    ``,
    `// PHP (server): getFilePreview returns raw image bytes.`,
    ``,
    `$client = (new Client())`,
    `    ->setEndpoint(${JSON.stringify(apiEndpoint)})`,
    `    ->setProject(${JSON.stringify(projectId)})`,
    `    ->setKey(getenv('APPWRITE_API_KEY') ?: '');`,
    ``,
    `$storage = new Storage($client);`,
    ``,
    `$result = $storage->getFilePreview(`,
    `    bucketId: ${JSON.stringify(bucketId)},`,
    `    fileId: ${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`    width: ${s.width},`)
  if (s.height !== null) lines.push(`    height: ${s.height},`)
  lines.push(`    gravity: ImageGravity::${gx}(),`)
  lines.push(`    quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    borderWidth: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    borderColor: ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`    borderRadius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background: ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  if (s.output) {
    const k = imageFormatEnumKeyForSdk(s.output)
    const phpFmt = k ? (k === 'Jpeg' ? 'JPEG' : k.toUpperCase()) : 'JPG'
    lines.push(`    output: ImageFormat::${phpFmt}(),`)
  }
  lines.push(`);`)
  return lines.join('\n')
}

export function buildRubySnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const gx = gravityPhpRubyMethodSuffix(s.gravity)
  const lines: string[] = [
    `require 'appwrite'`,
    ``,
    `include Appwrite`,
    `include Appwrite::Enums`,
    ``,
    `# Ruby (server): get_file_preview returns binary string.`,
    ``,
    `client = Client.new`,
    `    .set_endpoint(${JSON.stringify(apiEndpoint)})`,
    `    .set_project(${JSON.stringify(projectId)})`,
    `    .set_key(ENV['APPWRITE_API_KEY'] || '')`,
    ``,
    `storage = Storage.new(client)`,
    ``,
    `result = storage.get_file_preview(`,
    `    bucket_id: ${JSON.stringify(bucketId)},`,
    `    file_id: ${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`    width: ${s.width},`)
  if (s.height !== null) lines.push(`    height: ${s.height},`)
  lines.push(`    gravity: ImageGravity::${gx},`)
  lines.push(`    quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    border_width: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    border_color: ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`    border_radius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background: ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  if (s.output) {
    const k = imageFormatEnumKeyForSdk(s.output)
    const rbFmt =
      !k || k === 'Jpg' || k === 'Jpeg'
        ? 'JPG'
        : k === 'Png'
          ? 'PNG'
          : k === 'Gif'
            ? 'GIF'
            : k === 'Webp'
              ? 'WEBP'
              : k === 'Avif'
                ? 'AVIF'
                : k === 'Heic'
                  ? 'HEIC'
                  : 'JPG'
    lines.push(`    output: ImageFormat::${rbFmt},`)
  }
  lines.push(`)`)
  return lines.join('\n')
}

export function buildDotnetSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const gKey = gravityEnumKeyForWeb(s.gravity)
  const lines: string[] = [
    `using Appwrite;`,
    `using Appwrite.Enums;`,
    `using Appwrite.Services;`,
    ``,
    `// .NET (C#): GetFilePreview returns byte[]. Use SetKey for server API keys.`,
    ``,
    `var client = new Client()`,
    `    .SetEndPoint(${JSON.stringify(apiEndpoint)})`,
    `    .SetProject(${JSON.stringify(projectId)})`,
    `    .SetKey(Environment.GetEnvironmentVariable("APPWRITE_API_KEY") ?? "");`,
    ``,
    `var storage = new Storage(client);`,
    ``,
    `byte[] result = await storage.GetFilePreview(`,
    `    bucketId: ${JSON.stringify(bucketId)},`,
    `    fileId: ${JSON.stringify(fileId)},`,
  ]
  if (s.width !== null) lines.push(`    width: ${s.width},`)
  if (s.height !== null) lines.push(`    height: ${s.height},`)
  lines.push(`    gravity: ImageGravity.${gKey},`)
  lines.push(`    quality: ${s.quality},`)
  if (s.borderWidth > 0) lines.push(`    borderWidth: ${s.borderWidth},`)
  if (s.borderColor.trim())
    lines.push(
      `    borderColor: ${JSON.stringify(s.borderColor.replace(/^#/, ''))},`,
    )
  if (s.borderRadius > 0) lines.push(`    borderRadius: ${s.borderRadius},`)
  if (s.opacity < 1) lines.push(`    opacity: ${s.opacity},`)
  if (s.rotation !== 0) lines.push(`    rotation: ${s.rotation},`)
  if (s.background.trim())
    lines.push(
      `    background: ${JSON.stringify(s.background.replace(/^#/, ''))},`,
    )
  const dfmt = dotnetImageFormatMember(s)
  if (dfmt) lines.push(`    output: ${dfmt},`)
  lines.push(`);`)
  return lines.join('\n')
}

export function buildGoSnippet(
  projectId: string,
  bucketId: string,
  fileId: string,
  s: ImageTransformState,
  apiEndpoint: string,
): string {
  const grav = gravityRestString(s.gravity)
  const optLines: string[] = []
  if (s.width !== null)
    optLines.push(`    storage.WithGetFilePreviewWidth(${s.width}),`)
  if (s.height !== null)
    optLines.push(`    storage.WithGetFilePreviewHeight(${s.height}),`)
  optLines.push(`    storage.WithGetFilePreviewGravity(${JSON.stringify(grav)}),`)
  optLines.push(`    storage.WithGetFilePreviewQuality(${s.quality}),`)
  if (s.borderWidth > 0)
    optLines.push(`    storage.WithGetFilePreviewBorderWidth(${s.borderWidth}),`)
  if (s.borderColor.trim())
    optLines.push(
      `    storage.WithGetFilePreviewBorderColor(${JSON.stringify(s.borderColor.replace(/^#/, ''))}),`,
    )
  if (s.borderRadius > 0)
    optLines.push(
      `    storage.WithGetFilePreviewBorderRadius(${s.borderRadius}),`,
    )
  if (s.opacity < 1)
    optLines.push(`    storage.WithGetFilePreviewOpacity(${s.opacity}),`)
  if (s.rotation !== 0)
    optLines.push(`    storage.WithGetFilePreviewRotation(${s.rotation}),`)
  if (s.background.trim())
    optLines.push(
      `    storage.WithGetFilePreviewBackground(${JSON.stringify(s.background.replace(/^#/, ''))}),`,
    )
  const out = goOutputString(s)
  if (out) optLines.push(`    storage.WithGetFilePreviewOutput(${JSON.stringify(out)}),`)

  const lines: string[] = [
    `package main`,
    ``,
    `import (`,
    `    "fmt"`,
    `    "github.com/appwrite/sdk-for-go/client"`,
    `    "github.com/appwrite/sdk-for-go/storage"`,
    `    "os"`,
    `)`,
    ``,
    `// Go (server): GetFilePreview returns the preview payload; configure API key via options as per SDK version.`,
    ``,
    `func main() {`,
    `    client := client.New(`,
    `        client.WithEndpoint(${JSON.stringify(apiEndpoint)}),`,
    `        client.WithProject(${JSON.stringify(projectId)}),`,
    `        client.WithKey(os.Getenv("APPWRITE_API_KEY")),`,
    `    )`,
    `    service := storage.New(client)`,
    `    response, err := service.GetFilePreview(`,
    `        ${JSON.stringify(bucketId)},`,
    `        ${JSON.stringify(fileId)},`,
    ...optLines,
    `    )`,
    `    if err != nil {`,
    `        panic(err)`,
    `    }`,
    `    fmt.Println(len(response))`,
    `}`,
  ]
  return lines.join('\n')
}

export function buildTransformImageCodeSnippet(
  id: TransformImageCodeSdkId,
  projectId: string,
  bucketId: string,
  fileId: string,
  state: ImageTransformState,
  apiEndpoint: string,
): string {
  switch (id) {
    case 'web':
      return buildWebSnippet(projectId, bucketId, fileId, state, apiEndpoint)
    case 'flutter':
      return buildFlutterSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'react_native':
      return buildReactNativeSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'apple':
      return buildAppleSwiftSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'android':
      return buildAndroidKotlinSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'deno':
      return buildDenoSnippet(projectId, bucketId, fileId, state, apiEndpoint)
    case 'node':
      return buildNodeSnippet(projectId, bucketId, fileId, state, apiEndpoint)
    case 'python':
      return buildPythonSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'php':
      return buildPhpSnippet(projectId, bucketId, fileId, state, apiEndpoint)
    case 'ruby':
      return buildRubySnippet(projectId, bucketId, fileId, state, apiEndpoint)
    case 'dotnet':
      return buildDotnetSnippet(
        projectId,
        bucketId,
        fileId,
        state,
        apiEndpoint,
      )
    case 'go':
      return buildGoSnippet(projectId, bucketId, fileId, state, apiEndpoint)
    default:
      return buildWebSnippet(projectId, bucketId, fileId, state, apiEndpoint)
  }
}
