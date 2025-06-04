import type { Artifact, Name } from '../../model';

/**
 * Describes an [`Actor`](https://serenity-js.org/api/core/class/Actor/) who can collect artifacts, such as photos or `JSON` data.,
 * while the scenario is being executed
 *
 * ## Learn more
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 *
 * @group Actors
 */
export interface CollectsArtifacts {

    /**
     * Makes the [`Actor`](https://serenity-js.org/api/core/class/Actor/) collect an artifact so that it can be included in the test report.
     *
     * #### Implementing a custom interaction to attach artifacts
     *
     * ```ts
     * import * as fs from 'node:fs'
     * import { Answerable, Interaction, the } from '@serenity-js/core'
     * import { Path } from '@serenity-js/core/lib/io'
     * import { Name, TextData } from '@serenity-js/core/lib/model'
     *
     * export class Attach {
     *
     *   static contentsOf = (pathToFile: Path): Interaction =>
     *     Interaction.where(`#actor attaches contents of ${ pathToFile.basename() }`, async actor => {
     *       const data = fs.readFileSync(pathToFile.value).toString('utf-8');
     *
     *       actor.collect(
     *         TextData.fromJSON({ contentType: 'text/plain', data }),
     *         new Name(pathToFile.basename()),
     *       )
     *     })
     *
     *   static textData = (contents: Answerable<string>, name?: string): Interaction =>
     *     Interaction.where(the`#actor attaches text data`, async actor => {
     *       const data = await actor.answer(contents);
     *
     *       actor.collect(
     *         TextData.fromJSON({ contentType: 'text/plain', data }),
     *         name && new Name(name),
     *       )
     *     })
     * }
     * ```
     *
     * #### Attaching plain text
     *
     * ```ts
     * import { actorCalled } from '@serenity-js/core'
     * import { Path } from '@serenity-js/core/lib/io'
     *
     * actorCalled('Alice').attemptsTo(
     *   Attach.textData('some text', 'some name'),
     * )
     * ```
     *
     * #### Attaching contents of a text file
     *
     * ```ts
     * import { actorCalled } from '@serenity-js/core'
     * import { Log } from '@serenity-js/core'
     *
     * actorCalled('Alice').attemptsTo(
     *   Attach.contentsOf(Path.from(__dirname, 'output/server.log')),
     * )
     * ```
     *
     * @param artifact
     *  The artifact to be collected, such as `JSON` data.
     *
     * @param name
     *  The name of the artifact to make it easy to recognise in the test report
     */
    collect(artifact: Artifact, name?: Name): void;
}
