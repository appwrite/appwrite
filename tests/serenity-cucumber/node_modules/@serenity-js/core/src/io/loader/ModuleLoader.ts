// eslint-disable-next-line @typescript-eslint/no-var-requires
const Module = require('module'); // No type definitions available
import * as path from 'path'; // eslint-disable-line unicorn/import-style

import { Version } from '../Version';

/**
 * Dynamically loads Node modules located relative to `cwd`.
 */
export class ModuleLoader {

    /**
     * @param {string} cwd
     *  Current working directory, relative to which Node modules should be resolved.
     * @param useRequireCache
     *  Whether to use Node's `require.cache`. Defaults to `true`.
     *  Set to `false` to force Node to reload required modules on every call.
     */
    constructor(
        public readonly cwd: string,
        public readonly useRequireCache: boolean = true,
    ) {
    }

    /**
     * Returns `true` if a given module is available to be required, false otherwise.
     *
     * @param moduleId
     *  NPM module id, for example 'cucumber' or '@serenity-js/core'
     */
    hasAvailable(moduleId: string): boolean {
        try {
            return !! this.require(moduleId);
        } catch {
            return false;
        }
    }

    /**
     * Works like `require.resolve`, but relative to specified current working directory
     *
     * @param moduleId
     *  NPM module id, for example `cucumber` or `@serenity-js/core`
     *
     * @returns
     *  Path to a given Node.js module
     */
    resolve(moduleId: string): string {
        const fromFile = path.join(this.cwd, 'noop.js');

        return Module._resolveFilename(moduleId, {
            id: fromFile,
            filename: fromFile,
            paths: Module._nodeModulePaths(this.cwd),
        });
    }

    /**
     * Works like `require`, but relative to specified current working directory
     *
     * @param moduleId
     */
    require(moduleId: string): any {
        try {
            return require(this.cachedIfNeeded(this.resolve(moduleId)));
        }
        catch {
            return require(this.cachedIfNeeded(moduleId));
        }
    }

    private cachedIfNeeded(moduleId: string): string {
        if (! this.useRequireCache) {
            delete require.cache[moduleId];
        }

        return moduleId;
    }

    /**
     * Returns `Version` of module specified by `moduleId`, based on its `package.json`.
     *
     * @param moduleId
     */
    versionOf(moduleId: string): Version {
        return new Version(this.require(`${ moduleId }/package.json`).version);
    }
}
