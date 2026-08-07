/**
 * Framework icon mapping: framework key → icon filename in /public/icons/.
 *
 * Keys use normalized form: lowercase, spaces/underscores/dots replaced with dash.
 * Add aliases for common variants (e.g. next-js, tanstack-start).
 */

/** Normalize for icon lookup: lowercase, replace spaces/underscores/dots with dash. */
function normalizeForIcon(frameworkKey: string | null | undefined): string {
  if (!frameworkKey || typeof frameworkKey !== 'string') return ''
  return frameworkKey
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/_/g, '-')
    .replace(/\./g, '-')
    .trim()
}

/** Map normalized framework key to icon filename (e.g. 'nextjs' → 'nextjs.svg'). */
export const FRAMEWORK_ICON_MAP: Record<string, string> = {
  react: 'react.svg',
  reactjs: 'react.svg',
  'react-js': 'react.svg',
  'react-native': 'react-native.svg',
  reactnative: 'react-native.svg',

  nextjs: 'nextjs.svg',
  'next-js': 'nextjs.svg',
  next: 'nextjs.svg',

  remix: 'remix.svg',
  remixjs: 'remix.svg',
  'remix-js': 'remix.svg',

  tanstack: 'tanstack.svg',
  'tanstack-start': 'tanstack.svg',
  tanstackstart: 'tanstack.svg',

  vue: 'vue.svg',
  vuejs: 'vue.svg',
  'vue-js': 'vue.svg',

  nuxt: 'nuxt.svg',
  nuxtjs: 'nuxt.svg',
  'nuxt-js': 'nuxt.svg',

  angular: 'angular.svg',
  angularjs: 'angular.svg',
  'angular-js': 'angular.svg',

  analog: 'analog.svg',
  analogjs: 'analog.svg',
  'analog-js': 'analog.svg',

  svelte: 'svelte.svg',
  sveltekit: 'svelte.svg',
  'svelte-kit': 'svelte.svg',

  solid: 'solid.svg',
  solidjs: 'solid.svg',
  'solid-js': 'solid.svg',
  solidstart: 'solid.svg',
  'solid-start': 'solid.svg',

  refine: 'refine.svg',

  astro: 'astro.svg',
  astrojs: 'astro.svg',
  'astro-js': 'astro.svg',

  vite: 'vite.svg',
  vitejs: 'vite.svg',
  'vite-js': 'vite.svg',

  ionic: 'ionic.svg',
  capacitor: 'capacitor.svg',
  capacitorjs: 'capacitor.svg',
  tauri: 'tauri.svg',

  flutter: 'flutter.svg',

  android: 'android.svg',
  apple: 'apple.svg',

  python: 'python.svg',
  dart: 'dart.svg',
  php: 'php.svg',
  ruby: 'ruby.svg',
  dotnet: 'dotnet.svg',
  go: 'go.svg',
  rust: 'rust.svg',
  rustc: 'rust.svg',

  swift: 'swift.svg',
  kotlin: 'kotlin.svg',
  node: 'node.svg',
  express: 'node.svg',
  koa: 'koa.svg',
  koajs: 'koa.svg',
  'koa-js': 'koa.svg',
  deno: 'deno.svg',
  fresh: 'fresh.svg',
  hono: 'hono.svg',
  elysia: 'elysia.svg',
  elysiajs: 'elysia.svg',
  'elysia-js': 'elysia.svg',

  fastify: 'fastify.svg',
  nestjs: 'nestjs.svg',
  'nest-js': 'nestjs.svg',
  nest: 'nestjs.svg',

  fastapi: 'fastapi.svg',
  'fast-api': 'fastapi.svg',
  django: 'django.svg',
  flask: 'flask.svg',

  laravel: 'laravel.svg',
  symfony: 'symfony.svg',

  rails: 'rails.svg',
  'ruby-on-rails': 'rails.svg',

  gin: 'gin.svg',
  echo: 'go.svg',
  fiber: 'go.svg',

  axum: 'rust.svg',
  actix: 'actix.svg',
  'actix-web': 'actix.svg',

  vapor: 'vapor.svg',

  ktor: 'ktor.svg',
  spring: 'spring.svg',
  'spring-boot': 'spring.svg',
  springboot: 'spring.svg',
  quarkus: 'quarkus.svg',

  serverpod: 'dart.svg',
  frog: 'dart.svg',
  'dart-frog': 'dart.svg',
  dartfrog: 'dart.svg',

  aspnet: 'dotnet.svg',
  'aspnet-core': 'dotnet.svg',
  minimal: 'dotnet.svg',
  controllers: 'dotnet.svg',

  pnpm: 'pnpm.svg',
  npm: 'npm.svg',
  yarn: 'yarn.svg',
  jsr: 'jsr.svg',

  appwrite: 'appwrite.svg',

  lynx: 'lynx.svg',
  lynxjs: 'lynx.svg',
  'lynx-js': 'lynx.svg',

  static: 'js.svg',
  vanilla: 'js.svg',
  web: 'js.svg',
  javascript: 'js.svg',
  js: 'js.svg',
  typescript: 'ts.svg',
  ts: 'ts.svg',

  bun: 'bun.svg',
  java: 'java.svg',
  cpp: 'cpp.svg',
  'c++': 'cpp.svg',

  // Cloud & hosting vendors
  aws: 'amazon.svg',
  amazon: 'amazon.svg',
  google: 'google.svg',
  microsoft: 'microsoft.svg',
  azure: 'microsoft.svg',
  vercel: 'vercel.svg',
  digitalocean: 'digitalocean.svg',
  'digital-ocean': 'digitalocean.svg',
  coolify: 'coolify.svg',
  rxdb: 'rxdb.svg',
  firebase: 'firebase.svg',
  supabase: 'supabase.svg',
  nhost: 'nhost.svg',

  // AI tools & IDEs
  claude: 'claude.svg',
  'claude-code': 'claude.svg',
  'claude-desktop': 'claude.svg',
  codex: 'chatgpt.svg',
  openai: 'chatgpt.svg',
  chatgpt: 'chatgpt.svg',
  cursor: 'cursor-ai.svg',
  'cursor-ai': 'cursor-ai.svg',
  vscode: 'vscode.svg',
  'vs-code': 'vscode.svg',
  opencode: 'opencode.svg',
  antigravity: 'google-antigravity.svg',
  'google-antigravity': 'google-antigravity.svg',
  lovable: 'lovable.svg',
  emergent: 'emergent.svg',
  bolt: 'bolt.svg',
  zenflow: 'zenflow.svg',
  windsurf: 'windsurf.svg',
  zed: 'zed.svg',
  gemini: 'google.svg',
  imagine: 'imagine.svg',
  'zed-industries': 'zed.svg',

  // Messaging & email providers
  mailgun: 'mailgun.svg',
  sendgrid: 'sendgrid.svg',
  twilio: 'twilio.svg',
  msg91: 'msg91.svg',
  vonage: 'vonage.svg',
  textmagic: 'textmagic.svg',
  telesign: 'telesign.svg',
  fcm: 'firebase.svg',
  apns: 'apple.svg',

  // Dev tools
  github: 'github.svg',
  terraform: 'terraform.svg',
  graphql: 'graphql.svg',
}

/**
 * Returns the icon filename for the given framework key, or null if none.
 */
export function getFrameworkIconFile(
  frameworkKey: string | null | undefined,
): string | null {
  const normalized = normalizeForIcon(frameworkKey)
  return (normalized && FRAMEWORK_ICON_MAP[normalized]) ?? null
}
