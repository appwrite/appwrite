// pruebas/tasks/basesDeDatos/CrearColeccion.ts

import { Task } from '@serenity-js/core';
import { Send, PostRequest } from '@serenity-js/rest';

import { ConfiguracionApi } from '../../utils/apiConfig';

export class CrearColeccion {

    /**
     * Devuelve un objeto intermedio que permite encadenar:
     *   CrearColeccion.enBaseDeDatos(databaseId)
     *       .conNombre(nombre)
     *       .conPermisos(permisos)
     */
    static enBaseDeDatos(databaseId: string) {
        return {
            conNombre: (nombre: string) => ({
                conPermisos: (permisos: string[]) =>
                    Task.where(
                        `#actor crea la colección ${ nombre } en la base ${ databaseId }`,
                        // Usamos Send.a(PostRequest.to(...)) para la llamada HTTP
                        Send.a(
                            PostRequest
                                .to(`/v1/databases/${ databaseId }/collections`)
                                .with({
                                    // El actor ya debe tener la habilidad CallAnApi.at(ConfiguracionApi.host)
                                    headers: {
                                        'X-Appwrite-Project': ConfiguracionApi.projectId,
                                        'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                                        'Content-Type':       'application/json',
                                    },
                                    body: {
                                        collectionId: nombre.toLowerCase(),
                                        name: nombre,
                                        permissions: permisos,
                                    },
                                })
                        )
                    ),
            }),
        };
    }
}
