// pruebas/tasks/proyectos/EliminarProyecto.ts

import { Task }               from '@serenity-js/core';
import { Send, DeleteRequest } from '@serenity-js/rest';
import { ConfiguracionApi }     from '../../utils/apiConfig';

export class EliminarProyecto {

    /**
     * Elimina un proyecto por su projectId en Appwrite.
     *
     * Uso en el step:
     *   actor.attemptsTo(
     *     EliminarProyecto.porId(miProjectId)
     *   );
     *
     * Y en tu Given inicial de Cucumber debes tener:
     *   actorCalled('Administrador').whoCan(
     *     CallAnApi.at(ConfiguracionApi.host)
     *   );
     */
    static porId(projectId: string) {
        return Task.where(
            `#actor elimina el proyecto ${ projectId }`,
            Send.a(
                DeleteRequest
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
