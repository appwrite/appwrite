import path from 'path';

import { ErrorStackParser } from '../errors';
import { FileSystemLocation, Path } from '../io';
import type { UsesAbilities } from './abilities';
import type { PerformsActivities } from './activities';
import type { Answerable } from './Answerable';
import type { AnswersQuestions } from './questions/AnswersQuestions';
import { Describable } from './questions/Describable';

/**
 * **Activities** represents [tasks](https://serenity-js.org/api/core/class/Task/) and [interactions](https://serenity-js.org/api/core/class/Interaction/) to be performed by an [actor](https://serenity-js.org/api/core/class/Actor/).
 *
 * Learn more about:
 * - [Performing activities at multiple levels](https://serenity-js.org/handbook/design/screenplay-pattern#performing-activities-at-multiple-levels)
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * - [`PerformsActivities`](https://serenity-js.org/api/core/interface/PerformsActivities/)
 * - [Command design pattern on Wikipedia](https://en.wikipedia.org/wiki/Command_pattern)
 *
 * @group Screenplay Pattern
 */
export abstract class Activity extends Describable {

    private static errorStackParser = new ErrorStackParser();
    readonly #location: FileSystemLocation;

    constructor(
        description: Answerable<string>,
        location: FileSystemLocation = Activity.callerLocation(5)
    ) {
        super(description);
        this.#location = location;
    }

    /**
     * Returns the location where this [`Activity`](https://serenity-js.org/api/core/class/Activity/) was instantiated.
     */
    instantiationLocation(): FileSystemLocation {
        return this.#location;
    }

    /**
     * Instructs the provided [`Actor`](https://serenity-js.org/api/core/class/Actor/) to perform this [`Activity`](https://serenity-js.org/api/core/class/Activity/).
     *
     * @param actor
     *
     * #### Learn more
     * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     * - [`PerformsActivities`](https://serenity-js.org/api/core/interface/PerformsActivities/)
     * - [`UsesAbilities`](https://serenity-js.org/api/core/interface/UsesAbilities/)
     * - [`AnswersQuestions`](https://serenity-js.org/api/core/interface/AnswersQuestions/)
     */
    abstract performAs(actor: PerformsActivities | UsesAbilities | AnswersQuestions): Promise<any>;

    protected static callerLocation(frameOffset: number): FileSystemLocation {

        const originalStackTraceLimit = Error.stackTraceLimit;
        Error.stackTraceLimit = 30;
        const error = new Error('Caller location marker');
        Error.stackTraceLimit = originalStackTraceLimit;

        const nonSerenityNodeModulePattern = new RegExp(`node_modules` + `\\` + path.sep + `(?!@serenity-js`+ `\\` + path.sep +`)`);

        const frames = this.errorStackParser.parse(error);
        const userLandFrames = frames.filter(frame => ! (
            frame?.fileName.startsWith('node:') ||          // node 16 and 18
            frame?.fileName.startsWith('internal') ||       // node 14
            nonSerenityNodeModulePattern.test(frame?.fileName)    // ignore node_modules, except for @serenity-js/*
        ));

        const index = Math.min(Math.max(1, frameOffset), userLandFrames.length - 1);
        // use the desired user-land frame, or the last one from the stack trace for internal invocations
        const invocationFrame = userLandFrames[index] || frames.at(-1);

        return new FileSystemLocation(
            Path.from(invocationFrame.fileName?.replace(/^file:/, '')),
            invocationFrame.lineNumber,
            invocationFrame.columnNumber,
        );
    }
}
