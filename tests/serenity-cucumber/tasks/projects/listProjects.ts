// pruebas/tasks/proyectos/ListarProyectos.ts

import { Task }             from '@serenity-js/core';
import { Send, GetRequest } from '@serenity-js/rest';
import { ConfiguracionApi }  from '../../utils/apiConfig';

export class ListarProyectos {

    /**
     * Lista todos los proyectos en Appwrite.
     *
     * Uso en el step:
     *   actor.attemptsTo(
     *     ListarProyectos.desde()
     *   );
     *
     * Y en tu Given inicial de Cucumber debes tener:
     *   actorCalled('Administrador').whoCan(
     *     CallAnApi.at(ConfiguracionApi.host)
     *   );
     */
    static desde() {
        return Task.where(
            `#actor lista todos los proyectos`,
            Send.a(
                GetRequest
                    .to(`/v1/projects`)
                    .using({
                        headers: {
                            'X-Appwrite-Project': ConfiguracionApi.projectId,
                            'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                        },
                    })
            )
        );
    }
}
