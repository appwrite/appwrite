import type { Actor } from '../screenplay';
import type { Cast } from './Cast';

/**
 * Produces no-op actors with no special [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 */
export class Extras implements Cast {
    prepare(actor: Actor): Actor {
        return actor;
    }
}
