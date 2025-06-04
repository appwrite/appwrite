// pruebas/tasks/basesDeDatos/ListarColecciones.ts

import { Task }             from '@serenity-js/core';
import { Send, GetRequest } from '@serenity-js/rest';
import { ConfiguracionApi }  from '../../utils/apiConfig';

export class ListarColecciones {

    /**
     * Lista todas las colecciones dentro de una base de datos dada.
     *
     * Uso en el step:
     *   actor.attemptsTo(
     *     ListarColecciones.enBaseDeDatos(miDatabaseId)
     *   );
     *
     * Y en tu Given inicial de Cucumber debes tener algo así:
     *   actorCalled('Administrador').whoCan(
     *     CallAnApi.at(ConfiguracionApi.host)
     *   );
     */
    static enBaseDeDatos(databaseId: string) {
        return Task.where(
            `#actor lista colecciones en la base ${ databaseId }`,
            Send.a(
                GetRequest
                    .to(`/v1/databases/${ databaseId }/collections`)
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
