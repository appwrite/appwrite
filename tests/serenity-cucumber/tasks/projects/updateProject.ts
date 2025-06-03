// pruebas/tasks/proyectos/ActualizarProyecto.ts

import { Task }        from '@serenity-js/core';
import { Send, PatchRequest } from '@serenity-js/rest';

import { ConfiguracionApi } from '../../utils/apiConfig';

export class ActualizarProyecto {

    /**
     * Construye la tarea para actualizar el campo "name" de un proyecto.
     * Uso:
     *   ActualizarProyecto
     *       .porId(projectId)
     *       .conNombre(nuevoNombre)
     */
    static porId(projectId: string) {
        return {
            conNombre: (nuevoNombre: string) =>
                Task.where(
                    `#actor actualiza el proyecto ${ projectId }`,
                    Send.a(
                        PatchRequest
                            .to(`/v1/projects/${ projectId }`)
                            .with({
                                headers: {
                                    'X-Appwrite-Project': ConfiguracionApi.projectId,
                                    'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                                    'Content-Type':       'application/json',
                                },
                                body: {
                                    name: nuevoNombre,
                                },
                            })
                    )
                )
        };
    }
}
