/**
 * `accept` value for manual deployment upload inputs.
 *
 * macOS pickers drop the two-part `.tar.gz` extension (Chrome and Safari find no
 * file type for it, Firefox strips it to `targz`), so `.gz` and the gzip MIME
 * types are what keep `code.tar.gz` selectable there (appwrite/appwrite#13635).
 */
export const DEPLOYMENT_ARCHIVE_ACCEPT =
  '.tar.gz,.gz,application/gzip,application/x-gzip'

/** The server only accepts the `gz` extension, so `.tgz` is not allowed. */
export function isDeploymentArchive(file: File): boolean {
  return file.name.toLowerCase().endsWith('.tar.gz')
}
