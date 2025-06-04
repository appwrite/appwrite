import { ExecutionFailedWithError, ExecutionSuccessful } from '@serenity-js/core/lib/model';

import { cucumberEventProtocolAdapter } from './CucumberEventProtocolAdapter';
import type { Dependencies } from './Dependencies';

// eslint-disable-next-line @typescript-eslint/explicit-module-boundary-types
export = function (dependencies: Dependencies) {

    dependencies.cucumber.defineSupportCode(({ BeforeAll, After, AfterAll }) => {
        BeforeAll(function () {
            dependencies.notifier.testRunStarts();
        });

        After(function () {
            dependencies.notifier.scenarioFinishes();

            return dependencies.serenity.waitForNextCue();
        });

        AfterAll(async function () {
            dependencies.notifier.testRunFinishes();

            try {
                await dependencies.serenity.waitForNextCue()
                dependencies.notifier.testRunFinished(new ExecutionSuccessful());
            }
            catch(error) {
                dependencies.notifier.testRunFinished(new ExecutionFailedWithError(error));
                throw error;
            }
        });
    });

    return cucumberEventProtocolAdapter(dependencies);
};
