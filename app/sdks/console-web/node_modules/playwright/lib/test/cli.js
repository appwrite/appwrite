"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
exports.addTestCommand = void 0;
const fs = __importStar(require("fs"));
const path = __importStar(require("path"));
const runner_1 = require("./runner");
const defaultTimeout = 30000;
const defaultReporter = process.env.CI ? 'dot' : 'list';
const builtinReporters = ['list', 'line', 'dot', 'json', 'junit', 'null'];
const tsConfig = 'playwright.config.ts';
const jsConfig = 'playwright.config.js';
const defaultConfig = {
    preserveOutput: process.env.CI ? 'failures-only' : 'always',
    reporter: [[defaultReporter]],
    timeout: defaultTimeout,
    updateSnapshots: process.env.CI ? 'none' : 'missing',
    workers: Math.ceil(require('os').cpus().length / 2),
};
function addTestCommand(program) {
    const command = program.command('test [test-filter...]');
    command.description('Run tests with Playwright Test');
    command.option('--browser <browser>', `Browser to use for tests, one of "all", "chromium", "firefox" or "webkit" (default: "chromium")`);
    command.option('--headed', `Run tests in headed browsers (default: headless)`);
    command.option('-c, --config <file>', `Configuration file, or a test directory with optional "${tsConfig}"/"${jsConfig}"`);
    command.option('--forbid-only', `Fail if test.only is called (default: false)`);
    command.option('-g, --grep <grep>', `Only run tests matching this regular expression (default: ".*")`);
    command.option('--global-timeout <timeout>', `Maximum time this test suite can run in milliseconds (default: unlimited)`);
    command.option('-j, --workers <workers>', `Number of concurrent workers, use 1 to run in a single worker (default: number of CPU cores / 2)`);
    command.option('--list', `Collect all the tests and report them, but do not run`);
    command.option('--max-failures <N>', `Stop after the first N failures`);
    command.option('--output <dir>', `Folder for output artifacts (default: "test-results")`);
    command.option('--quiet', `Suppress stdio`);
    command.option('--repeat-each <N>', `Run each test N times (default: 1)`);
    command.option('--reporter <reporter>', `Reporter to use, comma-separated, can be ${builtinReporters.map(name => `"${name}"`).join(', ')} (default: "${defaultReporter}")`);
    command.option('--retries <retries>', `Maximum retry count for flaky tests, zero for no retries (default: no retries)`);
    command.option('--shard <shard>', `Shard tests and execute only the selected shard, specify in the form "current/all", 1-based, for example "3/5"`);
    command.option('--project <project-name>', `Only run tests from the specified project (default: run all projects)`);
    command.option('--timeout <timeout>', `Specify test timeout threshold in milliseconds, zero for unlimited (default: ${defaultTimeout})`);
    command.option('-u, --update-snapshots', `Update snapshots with actual results (default: only create missing snapshots)`);
    command.option('-x', `Stop after the first failure`);
    command.action(async (args, opts) => {
        try {
            await runTests(args, opts);
        }
        catch (e) {
            console.error(e.toString());
            process.exit(1);
        }
    });
    command.on('--help', () => {
        console.log('');
        console.log('Arguments [test-filter...]:');
        console.log('  Pass arguments to filter test files. Each argument is treated as a regular expression.');
        console.log('');
        console.log('Examples:');
        console.log('  $ test my.spec.ts');
        console.log('  $ test -c tests/ --headed');
        console.log('  $ test --browser=webkit');
    });
}
exports.addTestCommand = addTestCommand;
async function runTests(args, opts) {
    const browserOpt = opts.browser ? opts.browser.toLowerCase() : 'chromium';
    if (!['all', 'chromium', 'firefox', 'webkit'].includes(browserOpt))
        throw new Error(`Unsupported browser "${opts.browser}", must be one of "all", "chromium", "firefox" or "webkit"`);
    const browserNames = browserOpt === 'all' ? ['chromium', 'firefox', 'webkit'] : [browserOpt];
    defaultConfig.projects = browserNames.map(browserName => {
        return {
            name: browserName,
            use: { browserName },
        };
    });
    const overrides = overridesFromOptions(opts);
    if (opts.headed)
        overrides.use = { headless: false };
    const runner = new runner_1.Runner(defaultConfig, overrides);
    function loadConfig(configFile) {
        if (fs.existsSync(configFile)) {
            if (process.stdout.isTTY)
                console.log(`Using config at ` + configFile);
            const loadedConfig = runner.loadConfigFile(configFile);
            if (('projects' in loadedConfig) && opts.browser)
                throw new Error(`Cannot use --browser option when configuration file defines projects. Specify browserName in the projects instead.`);
            return true;
        }
        return false;
    }
    if (opts.config) {
        const configFile = path.resolve(process.cwd(), opts.config);
        if (!fs.existsSync(configFile))
            throw new Error(`${opts.config} does not exist`);
        if (fs.statSync(configFile).isDirectory()) {
            // When passed a directory, look for a config file inside.
            if (!loadConfig(path.join(configFile, tsConfig)) && !loadConfig(path.join(configFile, jsConfig))) {
                // If there is no config, assume this as a root testing directory.
                runner.loadEmptyConfig(configFile);
            }
        }
        else {
            // When passed a file, it must be a config file.
            loadConfig(configFile);
        }
    }
    else if (!loadConfig(path.resolve(process.cwd(), tsConfig)) && !loadConfig(path.resolve(process.cwd(), jsConfig))) {
        // No --config option, let's look for the config file in the current directory.
        // If not, scan the world.
        runner.loadEmptyConfig(process.cwd());
    }
    const result = await runner.run(!!opts.list, args.map(forceRegExp), opts.project || undefined);
    if (result === 'sigint')
        process.exit(130);
    process.exit(result === 'passed' ? 0 : 1);
}
function forceRegExp(pattern) {
    const match = pattern.match(/^\/(.*)\/([gi]*)$/);
    if (match)
        return new RegExp(match[1], match[2]);
    return new RegExp(pattern, 'g');
}
function overridesFromOptions(options) {
    const isDebuggerAttached = !!require('inspector').url();
    const shardPair = options.shard ? options.shard.split('/').map((t) => parseInt(t, 10)) : undefined;
    return {
        forbidOnly: options.forbidOnly ? true : undefined,
        globalTimeout: isDebuggerAttached ? 0 : (options.globalTimeout ? parseInt(options.globalTimeout, 10) : undefined),
        grep: options.grep ? forceRegExp(options.grep) : undefined,
        maxFailures: options.x ? 1 : (options.maxFailures ? parseInt(options.maxFailures, 10) : undefined),
        outputDir: options.output ? path.resolve(process.cwd(), options.output) : undefined,
        quiet: options.quiet ? options.quiet : undefined,
        repeatEach: options.repeatEach ? parseInt(options.repeatEach, 10) : undefined,
        retries: options.retries ? parseInt(options.retries, 10) : undefined,
        reporter: (options.reporter && options.reporter.length) ? options.reporter.split(',').map((r) => [r]) : undefined,
        shard: shardPair ? { current: shardPair[0] - 1, total: shardPair[1] } : undefined,
        timeout: isDebuggerAttached ? 0 : (options.timeout ? parseInt(options.timeout, 10) : undefined),
        updateSnapshots: options.updateSnapshots ? 'all' : undefined,
        workers: options.workers ? parseInt(options.workers, 10) : undefined,
    };
}
//# sourceMappingURL=cli.js.map