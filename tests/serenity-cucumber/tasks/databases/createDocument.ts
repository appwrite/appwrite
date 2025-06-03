// pruebas/tasks/basesDeDatos/CrearDocumento.ts

import { Task }          from '@serenity-js/core';
import { Send, PostRequest } from '@serenity-js/rest';

import { ConfiguracionApi } from '../../utils/apiConfig';

export class CrearDocumento {

    /**
     * Construye la tarea para crear un documento en una colección de una base de datos.
     * Uso:
     *   CrearDocumento
     *       .enBaseDeDatos(databaseId, collectionId)
     *       .conDatos({ clave1: valor1, clave2: valor2 })
     */
    static enBaseDeDatos(databaseId: string, collectionId: string) {
        return {
            conDatos: (datos: { [clave: string]: any }) =>
                Task.where(
                    `#actor crea un documento en ${ databaseId }/${ collectionId }`,
                    Send.a(
                        PostRequest
                            .to(`/v1/databases/${ databaseId }/collections/${ collectionId }/documents`)
                            .with({
                                headers: {
                                    'X-Appwrite-Project': ConfiguracionApi.projectId,
                                    'X-Appwrite-Key':     ConfiguracionApi.apiKey,
                                    'Content-Type':       'application/json',
                                },
                                body: {
                                    documentId: `doc_${ Date.now() }`,
                                    data:       datos,
                                    read:       ['role:any'],
                                    write:      ['role:any'],
                                },
                            })
                    )
                )
        };
    }
}
