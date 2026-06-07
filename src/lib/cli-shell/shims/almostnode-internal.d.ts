declare module '@almostnode-internal/registry' {
  export class Registry {
    constructor(options?: { registryUrl?: string })
    getPackageManifest(
      name: string,
      version?: string,
    ): Promise<{
      versions: Record<
        string,
        {
          dist: { tarball: string }
          dependencies?: Record<string, string>
          peerDependencies?: Record<string, string>
          peerDependenciesMeta?: Record<string, { optional?: boolean }>
          optionalDependencies?: Record<string, string>
        }
      >
      'dist-tags': Record<string, string>
    }>
    resolveVersion(name: string, range: string): Promise<string>
  }
}

declare module '@almostnode-internal/tarball' {
  export function downloadAndExtract(
    url: string,
    vfs: unknown,
    destPath: string,
    options?: { stripComponents?: number },
  ): Promise<void>
}

declare module '@almostnode-internal/transform' {
  export function isTransformerReady(): boolean
  export function initTransformer(): Promise<void>
  export function transformPackage(
    vfs: unknown,
    pkgPath: string,
    onProgress?: (message: string) => void,
  ): Promise<number>
}

declare module '@almostnode-internal/path' {
  export function join(...parts: string[]): string
  export function dirname(path: string): string
  export function basename(path: string): string
}
