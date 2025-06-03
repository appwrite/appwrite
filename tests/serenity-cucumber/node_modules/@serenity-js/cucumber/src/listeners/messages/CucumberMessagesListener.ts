import type { Envelope } from '@cucumber/messages';
import { IdGenerator } from '@cucumber/messages';
import type { Serenity } from '@serenity-js/core';
import type { DomainEvent } from '@serenity-js/core/lib/events';
import { SceneFinishes, TestRunFinished, TestRunFinishes, TestRunStarts } from '@serenity-js/core/lib/events';
import type { ModuleLoader } from '@serenity-js/core/lib/io';
import { ExecutionFailedWithError, ExecutionSuccessful } from '@serenity-js/core/lib/model';

import { CucumberMessagesParser } from './parser/CucumberMessagesParser';
import type { IParsedTestStep } from './types/cucumber';

export = function (serenity: Serenity, moduleLoader: ModuleLoader) {    // eslint-disable-line @typescript-eslint/explicit-module-boundary-types

    const
        { Formatter, formatterHelpers } = moduleLoader.require('@cucumber/cucumber'),
        TestCaseHookDefinition          = moduleLoader.require('@cucumber/cucumber/lib/models/test_case_hook_definition').default;

    return class CucumberMessagesListener extends Formatter {
        static readonly fakeInternalAfterHookUri = '/internal/serenity-js/cucumber';

        readonly parser: CucumberMessagesParser;

        log: (buffer: string | Uint8Array) => void;
        supportCodeLibrary: any;

        constructor(options) {
            super(options);

            this.parser = new CucumberMessagesParser(
                serenity,
                formatterHelpers,
                options,
                (step: IParsedTestStep) =>
                    step?.actionLocation?.uri !== CucumberMessagesListener.fakeInternalAfterHookUri,
            );

            this.supportCodeLibrary = this.supportCodeLibrary ?? options.supportCodeLibrary;

            this.addAfterHook(() => {
                this.emit(new SceneFinishes(
                    serenity.currentSceneId(),
                    serenity.currentTime()
                ));

                return serenity.waitForNextCue();
            });

            options.eventBroadcaster.on('envelope', (envelope: Envelope) => {
                // this.log('> [cucumber] ' + JSON.stringify(envelope) + '\n');

                switch (true) {
                    case !! envelope.testRunStarted:
                        return this.emit(new TestRunStarts(serenity.currentTime()));

                    case !! envelope.testCaseStarted:
                        return this.emit(
                            this.parser.parseTestCaseStarted(envelope.testCaseStarted),
                        );

                    case !! envelope.testStepStarted:
                        return this.emit(
                            this.parser.parseTestStepStarted(envelope.testStepStarted),
                        );

                    case !! envelope.testStepFinished:
                        return this.emit(
                            this.parser.parseTestStepFinished(envelope.testStepFinished),
                        );

                    case !! envelope.testCaseFinished:
                        return this.emit(
                            this.parser.parseTestCaseFinished(envelope.testCaseFinished),
                        );
                }
            });
        }

        public async finished(): Promise<void> {
            this.emit(new TestRunFinishes(serenity.currentTime()));

            try {
                await serenity.waitForNextCue();

                this.emit(new TestRunFinished(new ExecutionSuccessful(), serenity.currentTime()));
            }
            catch(error) {
                this.emit(new TestRunFinished(new ExecutionFailedWithError(error), serenity.currentTime()));
                throw error;
            }
            finally {
                await super.finished();
            }
        }

        addAfterHook(code: (...args: any) => Promise<void> | void) {
            this.supportCodeLibrary.afterTestCaseHookDefinitions.unshift(
                new TestCaseHookDefinition({
                    code,
                    id:     IdGenerator.uuid()(),
                    line:   0,
                    uri:    CucumberMessagesListener.fakeInternalAfterHookUri,
                    options: {},
                }),
            );
        }

        emit(events: DomainEvent[] | DomainEvent): void {
            [].concat(events).forEach(event => serenity.announce(event));
        }
    }
}
