"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.globToRegex = exports.urlMatches = exports.parsedURL = exports.evaluationScript = exports.envObjectToArray = exports.deprecate = void 0;
const fs_1 = __importDefault(require("fs"));
const utils_1 = require("../utils/utils");
const deprecatedHits = new Set();
function deprecate(methodName, message) {
    if (deprecatedHits.has(methodName))
        return;
    deprecatedHits.add(methodName);
    console.warn(message);
}
exports.deprecate = deprecate;
function envObjectToArray(env) {
    const result = [];
    for (const name in env) {
        if (!Object.is(env[name], undefined))
            result.push({ name, value: String(env[name]) });
    }
    return result;
}
exports.envObjectToArray = envObjectToArray;
async function evaluationScript(fun, arg, addSourceUrl = true) {
    if (typeof fun === 'function') {
        const source = fun.toString();
        const argString = Object.is(arg, undefined) ? 'undefined' : JSON.stringify(arg);
        return `(${source})(${argString})`;
    }
    if (arg !== undefined)
        throw new Error('Cannot evaluate a string with arguments');
    if (utils_1.isString(fun))
        return fun;
    if (fun.content !== undefined)
        return fun.content;
    if (fun.path !== undefined) {
        let source = await fs_1.default.promises.readFile(fun.path, 'utf8');
        if (addSourceUrl)
            source += '//# sourceURL=' + fun.path.replace(/\n/g, '');
        return source;
    }
    throw new Error('Either path or content property must be present');
}
exports.evaluationScript = evaluationScript;
function parsedURL(url) {
    try {
        return new URL(url);
    }
    catch (e) {
        return null;
    }
}
exports.parsedURL = parsedURL;
function urlMatches(urlString, match) {
    if (match === undefined || match === '')
        return true;
    if (utils_1.isString(match))
        match = globToRegex(match);
    if (utils_1.isRegExp(match))
        return match.test(urlString);
    if (typeof match === 'string' && match === urlString)
        return true;
    const url = parsedURL(urlString);
    if (!url)
        return false;
    if (typeof match === 'string')
        return url.pathname === match;
    if (typeof match !== 'function')
        throw new Error('url parameter should be string, RegExp or function');
    return match(url);
}
exports.urlMatches = urlMatches;
const escapeGlobChars = new Set(['/', '$', '^', '+', '.', '(', ')', '=', '!', '|']);
function globToRegex(glob) {
    const tokens = ['^'];
    let inGroup;
    for (let i = 0; i < glob.length; ++i) {
        const c = glob[i];
        if (escapeGlobChars.has(c)) {
            tokens.push('\\' + c);
            continue;
        }
        if (c === '*') {
            const beforeDeep = glob[i - 1];
            let starCount = 1;
            while (glob[i + 1] === '*') {
                starCount++;
                i++;
            }
            const afterDeep = glob[i + 1];
            const isDeep = starCount > 1 &&
                (beforeDeep === '/' || beforeDeep === undefined) &&
                (afterDeep === '/' || afterDeep === undefined);
            if (isDeep) {
                tokens.push('((?:[^/]*(?:\/|$))*)');
                i++;
            }
            else {
                tokens.push('([^/]*)');
            }
            continue;
        }
        switch (c) {
            case '?':
                tokens.push('.');
                break;
            case '{':
                inGroup = true;
                tokens.push('(');
                break;
            case '}':
                inGroup = false;
                tokens.push(')');
                break;
            case ',':
                if (inGroup) {
                    tokens.push('|');
                    break;
                }
                tokens.push('\\' + c);
                break;
            default:
                tokens.push(c);
        }
    }
    tokens.push('$');
    return new RegExp(tokens.join(''));
}
exports.globToRegex = globToRegex;
//# sourceMappingURL=clientHelper.js.map