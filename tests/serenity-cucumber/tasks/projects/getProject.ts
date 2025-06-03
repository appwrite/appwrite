// pruebas/tasks/proyectos/ObtenerProyecto.ts

import { Task }             from '@serenity-js/core';
import { Send, GetRequest } from '@serenity-js/rest';
import { ConfiguracionApi }  from '../../utils/apiConfig';

export class ObtenerProyecto {

    /**
     * Obtiene un proyecto por su projectId en Appwrite.
     *
     * Uso en el step:
     *   actor.attemptsTo(
     *     ObtenerProyecto.porId(miProjectId)
     *   );
     *
     * Y en tu Given inicial de Cucumber debes tener:
     *   actorCalled('Administrador').whoCan(
     *     CallAnApi.at(ConfiguracionApi.host)
     *   );
     */
    static porId(projectId: string) {
        return Task.where(
            `#actor obtiene el proyecto ${ projectId }`,
            Send.a(
                GetRequest
                    .to(`/v1/projects/${ projectId }`)
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
