/**
 * Provides the async `import()` function to source code that needs it,
 * without having it transpiled down to commonjs `require()` by TypeScript.
 * See https://github.com/microsoft/TypeScript/issues/43329.
 *
 * @param {any} descriptor - A URL or path for the module to load
 * @return {Promise<any>} Promise that resolves to the loaded module
 */
async function importer(descriptor) {
  return await import(descriptor)
}

module.exports = { importer }
