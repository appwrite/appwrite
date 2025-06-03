import type { TestRunnerAdapter } from '@serenity-js/core/lib/adapter';
import type { FileSystem, ModuleLoader } from '@serenity-js/core/lib/io';
import { FileFinder, Path, Version } from '@serenity-js/core/lib/io';
import type { Outcome } from '@serenity-js/core/lib/model';
import { ExecutionIgnored, ImplementationPending } from '@serenity-js/core/lib/model';
import * as path from 'path'; // eslint-disable-line unicorn/import-style

import type { CucumberConfig } from './CucumberConfig';
import { CucumberOptions } from './CucumberOptions';
import type { OutputDescriptor, SerenityFormatterOutput } from './output';

/**
 * Allows for programmatic execution of Cucumber test scenarios.
 *
 * ## Learn more
 * - [`TestRunnerAdapter`](https://serenity-js.org/api/core-adapter/interface/TestRunnerAdapter/)
 *
 * @group Integration
 */
export class CucumberCLIAdapter implements TestRunnerAdapter {

    private pathsToScenarios: string[] = [];

    private readonly options: CucumberOptions;

    constructor(
        config: CucumberConfig,
        private readonly loader: ModuleLoader,
        fileSystem: FileSystem,
        private readonly output: SerenityFormatterOutput,
    ) {
        this.options = new CucumberOptions(new FileFinder(Path.from(this.loader.cwd)), fileSystem, config);
    }

    /**
     * Scenario success threshold for this test runner, calculated based on [`CucumberConfig`](https://serenity-js.org/api/cucumber-adapter/interface/CucumberConfig/)
     */
    successThreshold(): Outcome | { Code: number } {
        return this.options.isStrict()
            ? ExecutionIgnored
            : ImplementationPending;
    }

    /**
     * Loads feature files.
     *
     * @param pathsToScenarios
     *  Absolute or relative paths to feature files
     */
    async load(pathsToScenarios: string[]): Promise<void> {
        this.pathsToScenarios = pathsToScenarios.map(maybeAbsolutePathToScenario => {
            // Ensure paths provided to Cucumber are relative
            // see https://github.com/cucumber/cucumber-js/issues/1900
            return path.isAbsolute(maybeAbsolutePathToScenario)
                ? path.relative(this.loader.cwd, maybeAbsolutePathToScenario)
                : maybeAbsolutePathToScenario;
        });

        // todo: implement loading, so parsing feature files to determine how many executable we have
    }

    /**
     * Returns the number of loaded scenarios
     *
     * @throws [`LogicError`](https://serenity-js.org/api/core/class/LogicError/)
     *  If called before `load`
     */
    scenarioCount(): number {
        // todo: we should count the actual executable scenarios to avoid launching a WebdriverIO worked
        //  for a feature file without any scenarios.
        return this.pathsToScenarios.length;

        // if (this.totalScenarios === undefined) {
        //     throw new LogicError('Make sure to call `load` before calling `scenarioCount`');
        // }
        //
        // return this.totalScenarios;
    }

    /**
     * Instructs Cucumber to execute feature files located at `pathsToScenarios`
     */
    async run(): Promise<void> {
        const version = this.loader.hasAvailable('@cucumber/cucumber')
            ? this.loader.versionOf('@cucumber/cucumber')
            : this.loader.versionOf('cucumber');

        const serenityListener = this.loader.resolve('@serenity-js/cucumber');

        return this.runScenarios(version, serenityListener, this.pathsToScenarios);
    }

    private runScenarios(version: Version, serenityListener: string, pathsToScenarios: string[]): Promise<void> {
        if (version.isAtLeast(new Version('10.0.0'))) {
            return this.runWithCucumber10(serenityListener, pathsToScenarios);
        }

        if (version.isAtLeast(new Version('9.0.0'))) {
            return this.runWithCucumber8JavaScriptApi(serenityListener, pathsToScenarios);
        }

        if (version.isAtLeast(new Version('8.7.0'))) {
            return this.runWithCucumber8JavaScriptApi(serenityListener, pathsToScenarios);
        }

        const argv = this.options.asArgumentsForCucumber(version);

        if (version.isAtLeast(new Version('8.0.0'))) {
            return this.runWithCucumber8(argv, serenityListener, pathsToScenarios);
        }

        if (version.isAtLeast(new Version('7.0.0'))) {
            return this.runWithCucumber7(argv, serenityListener, pathsToScenarios);
        }

        if (version.isAtLeast(new Version('3.0.0'))) {
            return this.runWithCucumber3to6(argv, serenityListener, pathsToScenarios);
        }

        if (version.isAtLeast(new Version('2.0.0'))) {
            return this.runWithCucumber2(argv, serenityListener, pathsToScenarios);
        }

        return this.runWithCucumber0to1(argv, serenityListener, pathsToScenarios);
    }

    private async runWithCucumber10(pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const output = this.output.get();
        const serenityListenerUrl = Path.from(pathToSerenityListener).toFileURL().href;
        const outputUrl = output.value() ?? undefined;

        // https://github.com/cucumber/cucumber-js/blob/main/docs/deprecations.md#ambiguous-colons-in-formats
        // https://github.com/cucumber/cucumber-js/issues/2326#issuecomment-1711701382
        return await this.runWithCucumberApi([
            serenityListenerUrl,
            outputUrl,
        ], pathsToScenarios, output);
    }

    // https://github.com/cucumber/cucumber-js/blob/main/docs/deprecations.md
    private async runWithCucumber8JavaScriptApi(pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const output = this.output.get();
        return await this.runWithCucumberApi(`${ pathToSerenityListener }:${ output.value() }`, pathsToScenarios, output);
    }

    private async runWithCucumberApi(serenityFormatter: string | [string, string?], pathsToScenarios: string[], output: OutputDescriptor): Promise<void> {
        const configuration = this.options.asCucumberApiConfiguration();
        const { loadConfiguration, loadSupport, runCucumber }  = this.loader.require('@cucumber/cucumber/api');

        // https://github.com/cucumber/cucumber-js/blob/main/src/api/environment.ts
        const environment = {
            cwd:    this.loader.cwd,
            stdout: process.stdout,
            stderr: process.stderr,
            env:    process.env,
            debug:  false,
        };

        configuration.format.push(serenityFormatter)
        configuration.paths = pathsToScenarios;

        // https://github.com/cucumber/cucumber-js/blob/main/src/configuration/types.ts
        const { runConfiguration } = await loadConfiguration({ provided: configuration }, environment);

        try {
            // load the support code upfront
            const support = await loadSupport(runConfiguration, environment)

            // run cucumber, using the support code we loaded already
            const { success } = await runCucumber({ ...runConfiguration, support }, environment)
            await output.cleanUp();

            return success
        }
        catch (error) {
            await output.cleanUp()
            throw error;
        }
    }

    private runWithCucumber8(argv: string[], pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const cucumber  = this.loader.require('@cucumber/cucumber');
        const output    = this.output.get();

        return new cucumber.Cli({
            argv:   argv.concat('--format', `${ pathToSerenityListener }:${ output.value() }`, ...pathsToScenarios),
            cwd:    this.loader.cwd,
            stdout: process.stdout,
            stderr: process.stderr,
            env:    process.env,
        })
        .run()
        .then(cleanUpAndPassThrough(output), cleanUpAndReThrow(output));
    }

    private runWithCucumber7(argv: string[], pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const cucumber  = this.loader.require('@cucumber/cucumber');
        const output    = this.output.get();

        return new cucumber.Cli({
            argv:   argv.concat('--format', `${ pathToSerenityListener }:${ output.value() }`, ...pathsToScenarios),
            cwd:    this.loader.cwd,
            stdout: process.stdout,
        })
        .run()
        .then(cleanUpAndPassThrough(output), cleanUpAndReThrow(output));
    }

    private runWithCucumber3to6(argv: string[], pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const cucumber  = this.loader.require('cucumber');
        const output    = this.output.get();

        return new cucumber.Cli({
            argv:   argv.concat('--format', `${ pathToSerenityListener }:${ output.value() }`, ...pathsToScenarios),
            cwd:    this.loader.cwd,
            stdout: process.stdout,
        })
        .run()
        .then(cleanUpAndPassThrough(output), cleanUpAndReThrow(output));
    }

    private runWithCucumber2(argv: string[], pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        const cucumber = this.loader.require('cucumber');

        return new cucumber.Cli({
            argv:   argv.concat('--require', pathToSerenityListener, ...pathsToScenarios),
            cwd:    this.loader.cwd,
            stdout: process.stdout,
        }).run();
    }

    private runWithCucumber0to1(argv: string[], pathToSerenityListener: string, pathsToScenarios: string[]): Promise<void> {
        return new Promise((resolve, reject) => {
            this.loader.require('cucumber')
                .Cli(argv.concat('--require', pathToSerenityListener, ...pathsToScenarios))
                .run((wasSuccessful: boolean) => resolve());
        })
    }
}

/**
 * @private
 */
function cleanUpAndPassThrough<T>(output: OutputDescriptor): (result: T) => Promise<T> {
    return (result: T) => {
        return output.cleanUp()
            .then(() => result);
    }
}

/**
 * @private
 */
function cleanUpAndReThrow(output: OutputDescriptor): (error: Error) => Promise<void> {
    return (error: Error) => {
        return output.cleanUp()
            .then(() => {
                throw error;
            }, ignoredError => {
                throw error;
            });
    }
}
