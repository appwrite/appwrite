// pruebas/tasks/proyectos/CrearProyecto.ts

import { Task }          from '@serenity-js/core';
import { Send, PostRequest }  from '@serenity-js/rest';

import { ConfiguracionApi } from '../../utils/apiConfig';

export class CrearProyecto {

    /**
     * Construye la tarea para crear un nuevo proyecto.
     * Uso:
     *   CrearProyecto
     *       .conNombre(nombreProyecto)
     *       .enEquipo(teamId)
     *       .conCorreo(email)
     */
    static conNombre(nombre: string) {
        return {
            enEquipo: (teamId: string) => ({
                conCorreo: (email: string) =>
                    Task.where(
                        `#actor crea el proyecto ${ nombre }`,
                        Send.a(
                            PostRequest
                                .to('/v1/projects')
                                .with({
                                    headers: {
                                        'X-Appwrite-Project': ConfiguracionApi.projectId,
                                        'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                                        'Content-Type':       'application/json',
                                    },
                                    body: {
                                        projectId: nombre.toLowerCase(),
                                        name:      nombre,
                                        teamId,
                                        email,
                                    },
                                })
                        )
                    )
            }),
        };
    }
}
