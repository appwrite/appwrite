// pruebas/preguntas/IncluyeDatosBaseDeDatos.ts

import { Question, UsesAbilities } from '@serenity-js/core';
import { LastResponse }         from '@serenity-js/rest';

export class IncluyeDatosBaseDeDatos {

    /**
     * Verifica que la respuesta de la última petición de base de datos
     * contenga el campo $id (databaseId).
     */
    static campoDatabaseId() {
        return Question.about('la respuesta incluye campo databaseId', (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                if (!cuerpo['$id']) {
                    throw new Error('No se encontró campo $id en la respuesta de base de datos');
                }
            })
        );
    }

    /**
     * Verifica que en la lista de bases de datos devuelta
     * exista un elemento con nombre igual a `nombre`.
     */
    static nombreEnLista(nombre: string) {
        return Question.about(`la lista de bases de datos incluye ${ nombre }`, (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                // Suponemos que "cuerpo.databases" es un array de objetos con propiedad "name"
                const nombres: string[] = cuerpo['databases'].map((item: any) => item.name);

                if (!nombres.includes(nombre)) {
                    throw new Error(`No se encontró la base de datos ${ nombre } en la lista`);
                }
            })
        );
    }

    /**
     * Verifica que la respuesta de la última petición de colección
     * contenga el campo $id (collectionId).
     */
    static campoCollectionId() {
    return Question.about('campo collectionId en la respuesta', async actor => {
      const body = await LastResponse.body<any>().answeredBy(actor);
      if (!body.$id) {
        throw new Error('No se encontró campo $id (collectionId) en el JSON');
      }
      return true;
    });
  }

    /**
     * Verifica que en la lista de colecciones devuelta
     * exista un elemento con nombre igual a `nombre`.
     */
    static nombreColeccionEnLista(nombre: string) {
        return Question.about(`la lista de colecciones incluye ${ nombre }`, (actor: UsesAbilities) =>
            LastResponse.body().then((cuerpo: any) => {
                // Suponemos que "cuerpo.collections" es un array de objetos con propiedad "name"
                const nombres: string[] = cuerpo['collections'].map((item: any) => item.name);

                if (!nombres.includes(nombre)) {
                    throw new Error(`No se encontró la colección ${ nombre } en la lista`);
                }
            })
        );
    }

    /**
     * Verifica que el documento retornado tenga los campos
     * "titulo" y "cuenta" con los valores esperados.
     */
    static documentoConCampos(campos: { titulo: string; cuenta: number }) {
        return Question.about(
            `el documento tiene titulo ${ campos.titulo } y cuenta ${ campos.cuenta }`,
            (actor: UsesAbilities) =>
                LastResponse.body().then((cuerpo: any) => {
                    // En Appwrite, los datos reales suelen ir dentro de "cuerpo.data"
                    const datos = cuerpo['data'] || {};

                    if (datos.titulo !== campos.titulo || datos.cuenta !== campos.cuenta) {
                        throw new Error(
                            `El documento no contiene los valores esperados: ${ JSON.stringify(campos) }`
                        );
                    }
                })
        );
    }
}
