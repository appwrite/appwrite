// pruebas/preguntas/IncluyeDatosProyecto.ts

import { Question, UsesAbilities } from '@serenity-js/core';
import { LastResponse }         from '@serenity-js/rest';

export class IncluyeDatosProyecto {

    /**
     * Verifica que la respuesta de la última petición de proyecto
     * contenga el campo $id (projectId).
     */
    static campoProjectId() {
        return Question.about('la respuesta incluye campo projectId', (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                if (!cuerpo['$id']) {
                    throw new Error('No se encontró campo $id en la respuesta de proyecto');
                }
            })
        );
    }

    /**
     * Verifica que en la lista de proyectos devuelta
     * exista un elemento con nombre igual a `nombre`.
     */
    static nombreEnLista(nombre: string) {
        return Question.about(`la lista de proyectos incluye ${ nombre }`, (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                // Suponemos que "cuerpo.projects" es un array de objetos con propiedad "name"
                const nombres: string[] = cuerpo['projects'].map((item: any) => item.name);

                if (!nombres.includes(nombre)) {
                    throw new Error(`No se encontró el proyecto ${ nombre } en la lista`);
                }
            })
        );
    }

    /**
     * Verifica que el proyecto retornado tenga el campo "name"
     * con el valor esperado.
     */
    static proyectoConNombre(nombre: string) {
        return Question.about(`el proyecto retornado tiene nombre ${ nombre }`, (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                if (cuerpo['name'] !== nombre) {
                    throw new Error(
                        `El nombre del proyecto retornado es ${ cuerpo['name'] }, pero se esperaba ${ nombre }`
                    );
                }
            })
        );
    }
}
