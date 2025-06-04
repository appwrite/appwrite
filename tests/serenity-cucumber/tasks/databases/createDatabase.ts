// pruebas/tasks/basesDeDatos/CrearBaseDeDatos.ts

import { Task }         from '@serenity-js/core';
import { Send, PostRequest } from '@serenity-js/rest';

import { ConfiguracionApi } from '../../utils/apiConfig';

export class CrearBaseDeDatos {

    /**
     * Construye la tarea para crear una base de datos.
     * Uso:
     *   CrearBaseDeDatos
     *       .conNombre(nombre)
     *       .conPermisos(permisosArray)
     */
    static conNombre(nombre: string) {
        return {
            conPermisos: (permisos: string[]) =>
                Task.where(
                    `#actor crea la base de datos ${ nombre }`,
                    Send.a(
                        PostRequest
                            .to('/v1/databases')
                            .with({
                                headers: {
                                    'X-Appwrite-Project': ConfiguracionApi.projectId,
                                    'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                                    'Content-Type':       'application/json',
                                },
                                body: {
                                    databaseId: nombre.toLowerCase(),
                                    name:       nombre,
                                    permisos
                                },
                            })
                    )
                )
        };
    }
}
