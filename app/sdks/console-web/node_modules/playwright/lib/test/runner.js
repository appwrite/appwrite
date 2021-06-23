"use strict";
/**
 * Copyright 2019 Google Inc. All rights reserved.
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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Runner = void 0;
const rimraf_1 = __importDefault(require("rimraf"));
const fs = __importStar(require("fs"));
const path = __importStar(require("path"));
const util_1 = require("util");
const dispatcher_1 = require("./dispatcher");
const util_2 = require("./util");
const test_1 = require("./test");
const loader_1 = require("./loader");
const multiplexer_1 = require("./reporters/multiplexer");
const dot_1 = __importDefault(require("./reporters/dot"));
const line_1 = __importDefault(require("./reporters/line"));
const list_1 = __importDefault(require("./reporters/list"));
const json_1 = __importDefault(require("./reporters/json"));
const junit_1 = __importDefault(require("./reporters/junit"));
const empty_1 = __importDefault(require("./reporters/empty"));
const minimatch_1 = require("minimatch");
const removeFolderAsync = util_1.promisify(rimraf_1.default);
const readDirAsync = util_1.promisify(fs.readdir);
const readFileAsync = util_1.promisify(fs.readFile);
class Runner {
    constructor(defaultConfig, configOverrides) {
        this._didBegin = false;
        this._loader = new loader_1.Loader(defaultConfig, configOverrides);
    }
    _createReporter() {
        const reporters = [];
        const defaultReporters = {
            dot: dot_1.default,
            line: line_1.default,
            list: list_1.default,
            json: json_1.default,
            junit: junit_1.default,
            null: empty_1.default,
        };
        for (const r of this._loader.fullConfig().reporter) {
            const [name, arg] = r;
            if (name in defaultReporters) {
                reporters.push(new defaultReporters[name](arg));
            }
            else {
                const reporterConstructor = this._loader.loadReporter(name);
                reporters.push(new reporterConstructor(arg));
            }
        }
        return new multiplexer_1.Multiplexer(reporters);
    }
    loadConfigFile(file) {
        return this._loader.loadConfigFile(file);
    }
    loadEmptyConfig(rootDir) {
        this._loader.loadEmptyConfig(rootDir);
    }
    async run(list, testFileReFilters, projectName) {
        this._reporter = this._createReporter();
        const config = this._loader.fullConfig();
        const globalDeadline = config.globalTimeout ? config.globalTimeout + util_2.monotonicTime() : undefined;
        const { result, timedOut } = await util_2.raceAgainstDeadline(this._run(list, testFileReFilters, projectName), globalDeadline);
        if (timedOut) {
            if (!this._didBegin)
                this._reporter.onBegin(config, new test_1.Suite(''));
            this._reporter.onTimeout(config.globalTimeout);
            await this._flushOutput();
            return 'failed';
        }
        if (result === 'forbid-only') {
            console.error('=====================================');
            console.error(' --forbid-only found a focused test.');
            console.error('=====================================');
        }
        else if (result === 'no-tests') {
            console.error('=================');
            console.error(' no tests found.');
            console.error('=================');
        }
        await this._flushOutput();
        return result;
    }
    async _flushOutput() {
        // Calling process.exit() might truncate large stdout/stderr output.
        // See https://github.com/nodejs/node/issues/6456.
        //
        // We can use writableNeedDrain to workaround this, but it is only available
        // since node v15.2.0.
        // See https://nodejs.org/api/stream.html#stream_writable_writableneeddrain.
        if (process.stdout.writableNeedDrain)
            await new Promise(f => process.stdout.on('drain', f));
        if (process.stderr.writableNeedDrain)
            await new Promise(f => process.stderr.on('drain', f));
    }
    async _run(list, testFileReFilters, projectName) {
        const testFileFilter = testFileReFilters.length ? util_2.createMatcher(testFileReFilters) : () => true;
        const config = this._loader.fullConfig();
        const projects = this._loader.projects().filter(project => {
            return !projectName || project.config.name.toLocaleLowerCase() === projectName.toLocaleLowerCase();
        });
        if (projectName && !projects.length) {
            const names = this._loader.projects().map(p => p.config.name).filter(name => !!name);
            if (!names.length)
                throw new Error(`No named projects are specified in the configuration file`);
            throw new Error(`Project "${projectName}" not found. Available named projects: ${names.map(name => `"${name}"`).join(', ')}`);
        }
        const files = new Map();
        const allTestFiles = new Set();
        for (const project of projects) {
            const testDir = project.config.testDir;
            if (!fs.existsSync(testDir))
                throw new Error(`${testDir} does not exist`);
            if (!fs.statSync(testDir).isDirectory())
                throw new Error(`${testDir} is not a directory`);
            const allFiles = await collectFiles(project.config.testDir);
            const testMatch = util_2.createMatcher(project.config.testMatch);
            const testIgnore = util_2.createMatcher(project.config.testIgnore);
            const testFiles = allFiles.filter(file => !testIgnore(file) && testMatch(file) && testFileFilter(file));
            files.set(project, testFiles);
            testFiles.forEach(file => allTestFiles.add(file));
        }
        let globalSetupResult;
        if (config.globalSetup)
            globalSetupResult = await this._loader.loadGlobalHook(config.globalSetup, 'globalSetup')(this._loader.fullConfig());
        try {
            for (const file of allTestFiles)
                this._loader.loadTestFile(file);
            const rootSuite = new test_1.Suite('');
            for (const fileSuite of this._loader.fileSuites().values())
                rootSuite._addSuite(fileSuite);
            if (config.forbidOnly && rootSuite._hasOnly())
                return 'forbid-only';
            filterOnly(rootSuite);
            const fileSuites = new Map();
            for (const fileSuite of rootSuite.suites)
                fileSuites.set(fileSuite.file, fileSuite);
            const outputDirs = new Set();
            const grepMatcher = util_2.createMatcher(config.grep);
            for (const project of projects) {
                for (const file of files.get(project)) {
                    const fileSuite = fileSuites.get(file);
                    if (!fileSuite)
                        continue;
                    for (const spec of fileSuite._allSpecs()) {
                        if (grepMatcher(spec._testFullTitle(project.config.name)))
                            project.generateTests(spec);
                    }
                }
                outputDirs.add(project.config.outputDir);
            }
            const total = rootSuite.totalTestCount();
            if (!total)
                return 'no-tests';
            await Promise.all(Array.from(outputDirs).map(outputDir => removeFolderAsync(outputDir).catch(e => { })));
            let sigint = false;
            let sigintCallback;
            const sigIntPromise = new Promise(f => sigintCallback = f);
            const sigintHandler = () => {
                // We remove handler so that double Ctrl+C immediately kills the runner,
                // for the case where our shutdown takes a lot of time or is buggy.
                // Removing the handler synchronously sometimes triggers the default handler
                // that exits the process, so we remove asynchronously.
                setTimeout(() => process.off('SIGINT', sigintHandler), 0);
                sigint = true;
                sigintCallback();
            };
            process.on('SIGINT', sigintHandler);
            if (process.stdout.isTTY) {
                const workers = new Set();
                rootSuite.findTest(test => {
                    workers.add(test.spec.file + test._workerHash);
                });
                console.log();
                const jobs = Math.min(config.workers, workers.size);
                const shard = config.shard;
                const shardDetails = shard ? `, shard ${shard.current + 1} of ${shard.total}` : '';
                console.log(`Running ${total} test${total > 1 ? 's' : ''} using ${jobs} worker${jobs > 1 ? 's' : ''}${shardDetails}`);
            }
            this._reporter.onBegin(config, rootSuite);
            this._didBegin = true;
            let hasWorkerErrors = false;
            if (!list) {
                const dispatcher = new dispatcher_1.Dispatcher(this._loader, rootSuite, this._reporter);
                await Promise.race([dispatcher.run(), sigIntPromise]);
                await dispatcher.stop();
                hasWorkerErrors = dispatcher.hasWorkerErrors();
            }
            this._reporter.onEnd();
            if (sigint)
                return 'sigint';
            return hasWorkerErrors || rootSuite.findSpec(spec => !spec.ok()) ? 'failed' : 'passed';
        }
        finally {
            if (globalSetupResult && typeof globalSetupResult === 'function')
                await globalSetupResult(this._loader.fullConfig());
            if (config.globalTeardown)
                await this._loader.loadGlobalHook(config.globalTeardown, 'globalTeardown')(this._loader.fullConfig());
        }
    }
}
exports.Runner = Runner;
function filterOnly(suite) {
    const onlySuites = suite.suites.filter(child => filterOnly(child) || child._only);
    const onlyTests = suite.specs.filter(spec => spec._only);
    const onlyEntries = new Set([...onlySuites, ...onlyTests]);
    if (onlyEntries.size) {
        suite.suites = onlySuites;
        suite.specs = onlyTests;
        suite._entries = suite._entries.filter(e => onlyEntries.has(e)); // Preserve the order.
        return true;
    }
    return false;
}
async function collectFiles(testDir) {
    const checkIgnores = (entryPath, rules, isDirectory, parentStatus) => {
        let status = parentStatus;
        for (const rule of rules) {
            const ruleIncludes = rule.negate;
            if ((status === 'included') === ruleIncludes)
                continue;
            const relative = path.relative(rule.dir, entryPath);
            if (rule.match('/' + relative) || rule.match(relative)) {
                // Matches "/dir/file" or "dir/file"
                status = ruleIncludes ? 'included' : 'ignored';
            }
            else if (isDirectory && (rule.match('/' + relative + '/') || rule.match(relative + '/'))) {
                // Matches "/dir/subdir/" or "dir/subdir/" for directories.
                status = ruleIncludes ? 'included' : 'ignored';
            }
            else if (isDirectory && ruleIncludes && (rule.match('/' + relative, true) || rule.match(relative, true))) {
                // Matches "/dir/donotskip/" when "/dir" is excluded, but "!/dir/donotskip/file" is included.
                status = 'ignored-but-recurse';
            }
        }
        return status;
    };
    const files = [];
    const visit = async (dir, rules, status) => {
        const entries = await readDirAsync(dir, { withFileTypes: true });
        entries.sort((a, b) => a.name.localeCompare(b.name));
        const gitignore = entries.find(e => e.isFile() && e.name === '.gitignore');
        if (gitignore) {
            const content = await readFileAsync(path.join(dir, gitignore.name), 'utf8');
            const newRules = content.split(/\r?\n/).map(s => {
                s = s.trim();
                if (!s)
                    return;
                // Use flipNegate, because we handle negation ourselves.
                const rule = new minimatch_1.Minimatch(s, { matchBase: true, dot: true, flipNegate: true });
                if (rule.comment)
                    return;
                rule.dir = dir;
                return rule;
            }).filter(rule => !!rule);
            rules = [...rules, ...newRules];
        }
        for (const entry of entries) {
            if (entry === gitignore || entry.name === '.' || entry.name === '..')
                continue;
            if (entry.isDirectory() && entry.name === 'node_modules')
                continue;
            const entryPath = path.join(dir, entry.name);
            const entryStatus = checkIgnores(entryPath, rules, entry.isDirectory(), status);
            if (entry.isDirectory() && entryStatus !== 'ignored')
                await visit(entryPath, rules, entryStatus);
            else if (entry.isFile() && entryStatus === 'included')
                files.push(entryPath);
        }
    };
    await visit(testDir, [], 'included');
    return files;
}
//# sourceMappingURL=runner.js.map