import type { DomainEvent } from '../events';

/**
 * A [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMember/) that can listen and react to [Serenity/JS domain events](https://serenity-js.org/api/core-events/class/DomainEvent/)
 *
 * ## Learn more
 *
 * - [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMember/)
 * - [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMemberBuilder/)
 * - [`engage`](https://serenity-js.org/api/core/function/engage/)
 * - [`SerenityConfig.crew`](https://serenity-js.org/api/core/class/SerenityConfig/#crew)
 *
 * @group Stage
 */
export interface ListensToDomainEvents {

    /**
     * Handles [`DomainEvent`](https://serenity-js.org/api/core-events/class/DomainEvent/) objects emitted by the [`Stage`](https://serenity-js.org/api/core/class/Stage/)
     * that this [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMember/) is assigned to.
     *
     * @param event
     */
    notifyOf(event: DomainEvent): void;
}
