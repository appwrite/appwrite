"use strict";
/**
 * Copyright Microsoft Corporation. All rights reserved.
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
exports.inheritFixtureParameterNames = exports.FixtureRunner = exports.FixturePool = void 0;
const util_1 = require("./util");
const crypto = __importStar(require("crypto"));
class Fixture {
    constructor(runner, registration) {
        this._setup = false;
        this._teardown = false;
        this.runner = runner;
        this.registration = registration;
        this.usages = new Set();
        this.value = null;
    }
    async setup(info) {
        if (typeof this.registration.fn !== 'function') {
            this._setup = true;
            this.value = this.registration.fn;
            return;
        }
        const params = {};
        for (const name of this.registration.deps) {
            const registration = this.runner.pool.resolveDependency(this.registration, name);
            if (!registration)
                throw util_1.errorWithCallLocation(`Unknown fixture "${name}"`);
            const dep = await this.runner.setupFixtureForRegistration(registration, info);
            dep.usages.add(this);
            params[name] = dep.value;
        }
        let setupFenceFulfill = () => { };
        let setupFenceReject = (e) => { };
        let called = false;
        const setupFence = new Promise((f, r) => { setupFenceFulfill = f; setupFenceReject = r; });
        const teardownFence = new Promise(f => this._teardownFenceCallback = f);
        this._tearDownComplete = util_1.wrapInPromise(this.registration.fn(params, async (value) => {
            if (called)
                throw util_1.errorWithCallLocation(`Cannot provide fixture value for the second time`);
            called = true;
            this.value = value;
            setupFenceFulfill();
            return await teardownFence;
        }, info)).catch((e) => {
            if (!this._setup)
                setupFenceReject(e);
            else
                throw e;
        });
        await setupFence;
        this._setup = true;
    }
    async teardown() {
        if (this._teardown)
            return;
        this._teardown = true;
        if (typeof this.registration.fn !== 'function')
            return;
        for (const fixture of this.usages)
            await fixture.teardown();
        this.usages.clear();
        if (this._setup) {
            this._teardownFenceCallback();
            await this._tearDownComplete;
        }
        this.runner.instanceForId.delete(this.registration.id);
    }
}
class FixturePool {
    constructor(fixturesList, parentPool) {
        this.registrations = new Map(parentPool ? parentPool.registrations : []);
        for (const { fixtures, location } of fixturesList) {
            try {
                for (const entry of Object.entries(fixtures)) {
                    const name = entry[0];
                    let value = entry[1];
                    let options;
                    if (Array.isArray(value) && typeof value[1] === 'object' && ('scope' in value[1] || 'auto' in value[1])) {
                        options = {
                            auto: !!value[1].auto,
                            scope: value[1].scope || 'test'
                        };
                        value = value[0];
                    }
                    const fn = value;
                    const previous = this.registrations.get(name);
                    if (previous && options) {
                        if (previous.scope !== options.scope)
                            throw errorWithLocations(`Fixture "${name}" has already been registered as a { scope: '${previous.scope}' } fixture.`, { location, name }, previous);
                        if (previous.auto !== options.auto)
                            throw errorWithLocations(`Fixture "${name}" has already been registered as a { auto: '${previous.scope}' } fixture.`, { location, name }, previous);
                    }
                    else if (previous) {
                        options = { auto: previous.auto, scope: previous.scope };
                    }
                    else if (!options) {
                        options = { auto: false, scope: 'test' };
                    }
                    const deps = fixtureParameterNames(fn, location);
                    const registration = { id: '', name, location, scope: options.scope, fn, auto: options.auto, deps, super: previous };
                    registrationId(registration);
                    this.registrations.set(name, registration);
                }
            }
            catch (e) {
                util_1.prependErrorMessage(e, `Error processing fixtures at ${util_1.formatLocation(location)}:\n`);
                throw e;
            }
        }
        this.digest = this.validate();
    }
    validate() {
        const markers = new Map();
        const stack = [];
        const visit = (registration) => {
            markers.set(registration, 'visiting');
            stack.push(registration);
            for (const name of registration.deps) {
                const dep = this.resolveDependency(registration, name);
                if (!dep) {
                    if (name === registration.name)
                        throw errorWithLocations(`Fixture "${registration.name}" references itself, but does not have a base implementation.`, registration);
                    else
                        throw errorWithLocations(`Fixture "${registration.name}" has unknown parameter "${name}".`, registration);
                }
                if (registration.scope === 'worker' && dep.scope === 'test')
                    throw errorWithLocations(`Worker fixture "${registration.name}" cannot depend on a test fixture "${name}".`, registration, dep);
                if (!markers.has(dep)) {
                    visit(dep);
                }
                else if (markers.get(dep) === 'visiting') {
                    const index = stack.indexOf(dep);
                    const regs = stack.slice(index, stack.length);
                    const names = regs.map(r => `"${r.name}"`);
                    throw errorWithLocations(`Fixtures ${names.join(' -> ')} -> "${dep.name}" form a dependency cycle.`, ...regs);
                }
            }
            markers.set(registration, 'visited');
            stack.pop();
        };
        const hash = crypto.createHash('sha1');
        const names = Array.from(this.registrations.keys()).sort();
        for (const name of names) {
            const registration = this.registrations.get(name);
            visit(registration);
            if (registration.scope === 'worker')
                hash.update(registration.id + ';');
        }
        return hash.digest('hex');
    }
    validateFunction(fn, prefix, allowTestFixtures, location) {
        const visit = (registration) => {
            for (const name of registration.deps)
                visit(this.resolveDependency(registration, name));
        };
        for (const name of fixtureParameterNames(fn, location)) {
            const registration = this.registrations.get(name);
            if (!registration)
                throw errorWithLocations(`${prefix} has unknown parameter "${name}".`, { location, name: prefix, quoted: false });
            if (!allowTestFixtures && registration.scope === 'test')
                throw errorWithLocations(`${prefix} cannot depend on a test fixture "${name}".`, { location, name: prefix, quoted: false }, registration);
            visit(registration);
        }
    }
    resolveDependency(registration, name) {
        if (name === registration.name)
            return registration.super;
        return this.registrations.get(name);
    }
}
exports.FixturePool = FixturePool;
class FixtureRunner {
    constructor() {
        this.testScopeClean = true;
        this.instanceForId = new Map();
    }
    setPool(pool) {
        if (!this.testScopeClean)
            throw new Error('Did not teardown test scope');
        if (this.pool && pool.digest !== this.pool.digest)
            throw new Error('Digests do not match');
        this.pool = pool;
    }
    async teardownScope(scope) {
        for (const [, fixture] of this.instanceForId) {
            if (fixture.registration.scope === scope)
                await fixture.teardown();
        }
        if (scope === 'test')
            this.testScopeClean = true;
    }
    async resolveParametersAndRunHookOrTest(fn, scope, info) {
        // Install all automatic fixtures.
        for (const registration of this.pool.registrations.values()) {
            const shouldSkip = scope === 'worker' && registration.scope === 'test';
            if (registration.auto && !shouldSkip)
                await this.setupFixtureForRegistration(registration, info);
        }
        // Install used fixtures.
        const names = fixtureParameterNames(fn, { file: '<unused>', line: 1, column: 1 });
        const params = {};
        for (const name of names) {
            const registration = this.pool.registrations.get(name);
            if (!registration)
                throw util_1.errorWithCallLocation('Unknown fixture: ' + name);
            const fixture = await this.setupFixtureForRegistration(registration, info);
            params[name] = fixture.value;
        }
        return fn(params, info);
    }
    async setupFixtureForRegistration(registration, info) {
        if (registration.scope === 'test')
            this.testScopeClean = false;
        let fixture = this.instanceForId.get(registration.id);
        if (fixture)
            return fixture;
        fixture = new Fixture(this, registration);
        this.instanceForId.set(registration.id, fixture);
        await fixture.setup(info);
        return fixture;
    }
}
exports.FixtureRunner = FixtureRunner;
function inheritFixtureParameterNames(from, to, location) {
    if (!to[signatureSymbol])
        to[signatureSymbol] = innerFixtureParameterNames(from, location);
}
exports.inheritFixtureParameterNames = inheritFixtureParameterNames;
const signatureSymbol = Symbol('signature');
function fixtureParameterNames(fn, location) {
    if (typeof fn !== 'function')
        return [];
    if (!fn[signatureSymbol])
        fn[signatureSymbol] = innerFixtureParameterNames(fn, location);
    return fn[signatureSymbol];
}
function innerFixtureParameterNames(fn, location) {
    const text = fn.toString();
    const match = text.match(/(?:async)?(?:\s+function)?[^(]*\(([^)]*)/);
    if (!match)
        return [];
    const trimmedParams = match[1].trim();
    if (!trimmedParams)
        return [];
    const [firstParam] = splitByComma(trimmedParams);
    if (firstParam[0] !== '{' || firstParam[firstParam.length - 1] !== '}')
        throw errorWithLocations('First argument must use the object destructuring pattern: ' + firstParam, { location });
    const props = splitByComma(firstParam.substring(1, firstParam.length - 1)).map(prop => {
        const colon = prop.indexOf(':');
        return colon === -1 ? prop : prop.substring(0, colon).trim();
    });
    return props;
}
function splitByComma(s) {
    const result = [];
    const stack = [];
    let start = 0;
    for (let i = 0; i < s.length; i++) {
        if (s[i] === '{' || s[i] === '[') {
            stack.push(s[i] === '{' ? '}' : ']');
        }
        else if (s[i] === stack[stack.length - 1]) {
            stack.pop();
        }
        else if (!stack.length && s[i] === ',') {
            const token = s.substring(start, i).trim();
            if (token)
                result.push(token);
            start = i + 1;
        }
    }
    const lastToken = s.substring(start).trim();
    if (lastToken)
        result.push(lastToken);
    return result;
}
// name + superId, fn -> id
const registrationIdMap = new Map();
let lastId = 0;
function registrationId(registration) {
    if (registration.id)
        return registration.id;
    const key = registration.name + '@@@' + (registration.super ? registrationId(registration.super) : '');
    let map = registrationIdMap.get(key);
    if (!map) {
        map = new Map();
        registrationIdMap.set(key, map);
    }
    if (!map.has(registration.fn))
        map.set(registration.fn, String(lastId++));
    registration.id = map.get(registration.fn);
    return registration.id;
}
function errorWithLocations(message, ...defined) {
    for (const { name, location, quoted } of defined) {
        let prefix = '';
        if (name && quoted === false)
            prefix = name + ' ';
        else if (name)
            prefix = `"${name}" `;
        message += `\n  ${prefix}defined at ${util_1.formatLocation(location)}`;
    }
    const error = new Error(message);
    error.stack = 'Error: ' + message + '\n';
    return error;
}
//# sourceMappingURL=fixtures.js.map