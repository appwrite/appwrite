"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.installTransform = void 0;
const crypto = __importStar(require("crypto"));
const os = __importStar(require("os"));
const path = __importStar(require("path"));
const fs = __importStar(require("fs"));
const pirates = __importStar(require("pirates"));
const babel = __importStar(require("@babel/core"));
const sourceMapSupport = __importStar(require("source-map-support"));
const version = 4;
const cacheDir = process.env.PWTEST_CACHE_DIR || path.join(os.tmpdir(), 'playwright-transform-cache');
const sourceMaps = new Map();
sourceMapSupport.install({
    environment: 'node',
    handleUncaughtExceptions: false,
    retrieveSourceMap(source) {
        if (!sourceMaps.has(source))
            return null;
        const sourceMapPath = sourceMaps.get(source);
        if (!fs.existsSync(sourceMapPath))
            return null;
        return {
            map: JSON.parse(fs.readFileSync(sourceMapPath, 'utf-8')),
            url: source
        };
    }
});
function calculateCachePath(content, filePath) {
    const hash = crypto.createHash('sha1').update(content).update(filePath).update(String(version)).digest('hex');
    const fileName = path.basename(filePath, path.extname(filePath)).replace(/\W/g, '') + '_' + hash;
    return path.join(cacheDir, hash[0] + hash[1], fileName);
}
function installTransform() {
    return pirates.addHook((code, filename) => {
        const cachePath = calculateCachePath(code, filename);
        const codePath = cachePath + '.js';
        const sourceMapPath = cachePath + '.map';
        sourceMaps.set(filename, sourceMapPath);
        if (fs.existsSync(codePath))
            return fs.readFileSync(codePath, 'utf8');
        // We don't use any browserslist data, but babel checks it anyway.
        // Silence the annoying warning.
        process.env.BROWSERSLIST_IGNORE_OLD_DATA = 'true';
        const result = babel.transformFileSync(filename, {
            babelrc: false,
            configFile: false,
            assumptions: {
                // Without this, babel defines a top level function that
                // breaks playwright evaluates.
                setPublicClassFields: true,
            },
            presets: [
                [require.resolve('@babel/preset-typescript'), { onlyRemoveTypeImports: true }],
            ],
            plugins: [
                [require.resolve('@babel/plugin-proposal-class-properties')],
                [require.resolve('@babel/plugin-proposal-numeric-separator')],
                [require.resolve('@babel/plugin-proposal-logical-assignment-operators')],
                [require.resolve('@babel/plugin-proposal-nullish-coalescing-operator')],
                [require.resolve('@babel/plugin-proposal-optional-chaining')],
                [require.resolve('@babel/plugin-syntax-json-strings')],
                [require.resolve('@babel/plugin-syntax-optional-catch-binding')],
                [require.resolve('@babel/plugin-syntax-async-generators')],
                [require.resolve('@babel/plugin-syntax-object-rest-spread')],
                [require.resolve('@babel/plugin-proposal-export-namespace-from')],
                [require.resolve('@babel/plugin-transform-modules-commonjs')],
                [require.resolve('@babel/plugin-proposal-dynamic-import')],
            ],
            sourceMaps: 'both',
        });
        if (result.code) {
            fs.mkdirSync(path.dirname(cachePath), { recursive: true });
            if (result.map)
                fs.writeFileSync(sourceMapPath, JSON.stringify(result.map), 'utf8');
            fs.writeFileSync(codePath, result.code, 'utf8');
        }
        return result.code || '';
    }, {
        exts: ['.ts']
    });
}
exports.installTransform = installTransform;
//# sourceMappingURL=transform.js.map