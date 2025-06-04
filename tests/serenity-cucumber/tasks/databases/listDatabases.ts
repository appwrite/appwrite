// pruebas/tasks/basesDeDatos/ListarBasesDeDatos.ts

import { Task }             from '@serenity-js/core';
import { Send, GetRequest } from '@serenity-js/rest';
import { ConfiguracionApi }  from '../../utils/apiConfig';

export class ListarBasesDeDatos {

    /**
     * Lista todas las bases de datos en Appwrite.
     *
     * Uso en el step:
     *   actor.attemptsTo(
     *     ListarBasesDeDatos.desde()
     *   );
     *
     * Y en tu Given inicial de Cucumber debes tener:
     *   actorCalled('Administrador').whoCan(
     *     CallAnApi.at(ConfiguracionApi.host)
     *   );
     */
    static desde() {
        return Task.where(
            `#actor lista todas las bases de datos`,
            Send.a(
                GetRequest
                    .to(`/v1/databases`)
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
