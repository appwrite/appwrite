// steps/databases.steps.ts

import { Given, When, Then }         from '@cucumber/cucumber';
import { actorInTheSpotlight }       from '@serenity-js/core';

import { CrearBaseDeDatos }          from '../tasks/databases/createDatabase';
import { ListarBasesDeDatos }        from '../tasks/databases/listDatabases';
import { CrearColeccion }            from '../tasks/databases/createCollection';
import { ListarColecciones }         from '../tasks/databases/listCollections';
import { CrearDocumento }            from '../tasks/databases/createDocument';

import { IncluyeDatosBaseDeDatos }   from '../questions/didReturnDatabaseData';


// —————————————————————————————————————————————————————————
// 1) “Dado que existe una base de datos "<nombre>" con ID $databaseId”
//    (aparece en varios escenarios precedido por “Y dado que…”)
// —————————————————————————————————————————————————————————
Given(
  /^dado que existe una base de datos "([^"]*)" con ID \$databaseId$/,
  async (dbName: string) => {
    const actor = actorInTheSpotlight();

    await actor.attemptsTo(
      CrearBaseDeDatos
        .conNombre(dbName)
        .conPermisos(['owner:any'])
    );

    // Si deseas almacenar el ID real devuelto:
    // const respuesta = await LastResponse.body<{ $id: string }>().answeredBy(actor);
    // (actor as any).remember('databaseId', respuesta.$id);
  }
);


// —————————————————————————————————————————————————————————
// 2) “Cuando el Actor intenta crear una base de datos con:”
//       | nombre   | MiBasePrueba  |
//       | permisos | ["owner:any"] |
// —————————————————————————————————————————————————————————
When(
  /^el Actor intenta crear una base de datos con:$/,
  async (tabla) => {
    const datos = tabla.rowsHash();
    const actor = actorInTheSpotlight();

    await actor.attemptsTo(
      CrearBaseDeDatos
        .conNombre(datos.nombre)
        .conPermisos(JSON.parse(datos.permisos))
    );
  }
);


// —————————————————————————————————————————————————————————
// 3) “Cuando el Actor solicita GET /v1/databases”
// —————————————————————————————————————————————————————————
When(
  /^el Actor solicita GET \/v1\/databases$/,
  async () => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      ListarBasesDeDatos.desde()
    );
  }
);


// —————————————————————————————————————————————————————————
// 4) “Then la respuesta debe incluir un campo databaseId”
// —————————————————————————————————————————————————————————
Then(
  /^la respuesta debe incluir un campo databaseId$/,
  async () => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosBaseDeDatos.campoDatabaseId()
    );
  }
);


// —————————————————————————————————————————————————————————
// 5) “Then la lista devuelta de bases de datos debe incluir "<nombre>"”
// —————————————————————————————————————————————————————————
Then(
  /^la lista devuelta de bases de datos debe incluir "([^"]*)"$/,
  async (dbName: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosBaseDeDatos.nombreEnLista(dbName)
    );
  }
);


// —————————————————————————————————————————————————————————
// 6) “Dado que existe una colección "<nombre>" con ID $collectionId con atributos:”
//       | clave  | tipo    | tamaño |
//       | titulo | string  | 255    |
//       | cuenta | integer | null   |
// —————————————————————————————————————————————————————————
Given(
  /^existe una colección "([^"]*)" con ID \$collectionId con atributos:$/,
  async (collectionName: string, dataTable) => {
    const actor = actorInTheSpotlight();
    const dbId  = (actor as any).recall('databaseId');

    // Si deseas crearla realmente:
    // await actor.attemptsTo(
    //   CrearColeccion
    //     .enBaseDeDatos(dbId)
    //     .conNombre(collectionName)
    //     .conPermisos(JSON.parse(dataTable.rowsHash().permisos || '[]'))
    // );

    // Si solo “simulas” su existencia:
    (actor as any).remember('collectionId', collectionName.toLowerCase());
  }
);


// —————————————————————————————————————————————————————————
// 7) “Cuando el Actor crea un nuevo documento con:”
//       | titulo | "Hola Mundo" |
//       | cuenta | 42           |
// —————————————————————————————————————————————————————————
When(
  /^el Actor crea un nuevo documento con:$/,
  async (tabla) => {
    const datos = tabla.rowsHash();
    const actor = actorInTheSpotlight();

    const dbId  = (actor as any).recall('databaseId');
    const colId = (actor as any).recall('collectionId');

    await actor.attemptsTo(
      CrearDocumento
        .enBaseDeDatos(dbId, colId)
        .conDatos({
          titulo: datos.titulo.replace(/"/g, ''),
          cuenta: Number(datos.cuenta),
        })
    );
  }
);


// —————————————————————————————————————————————————————————
// 8) “Then el documento retornado debe tener titulo "<titulo>" y cuenta {int}”
// —————————————————————————————————————————————————————————
Then(
  /^el documento retornado debe tener titulo "([^"]*)" y cuenta (\d+)$/,
  async (titulo: string, cuenta: number) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosBaseDeDatos.documentoConCampos({ titulo, cuenta })
    );
  }
);


// —————————————————————————————————————————————————————————
// 9) “Cuando el Actor crea una colección en esa base de datos con:”
//       | nombre   | "MiColeccion"                 |
//       | permisos | ["document:read('role:any')"] |
// —————————————————————————————————————————————————————————
When(
  /^el Actor crea una colección en esa base de datos con:$/,
  async (tabla) => {
    const datos = tabla.rowsHash();
    const actor = actorInTheSpotlight();

    const dbId = (actor as any).recall('databaseId');

    await actor.attemptsTo(
      CrearColeccion
        .enBaseDeDatos(dbId)
        .conNombre(datos.nombre.replace(/"/g, ''))
        .conPermisos(JSON.parse(datos.permisos))
    );
  }
);


// —————————————————————————————————————————————————————————
// 10) “Cuando el Actor solicita GET /v1/databases/{databaseId}/collections”
// —————————————————————————————————————————————————————————
When(
  /^el Actor solicita GET \/v1\/databases\/([^"]*)\/collections$/,
  async (databaseId: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      ListarColecciones.enBaseDeDatos(databaseId)
    );
  }
);


// —————————————————————————————————————————————————————————
// 11) “Then la lista devuelta de colecciones debe incluir "<nombre>"”
// —————————————————————————————————————————————————————————
Then(
  /^la lista devuelta de colecciones debe incluir "([^"]*)"$/,
  async (collectionName: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosBaseDeDatos.nombreColeccionEnLista(collectionName)
    );
  }
);


// —————————————————————————————————————————————————————————
// PASO ADICIONAL 1:
// “Dado que existe una base de datos con nombre "{string}"”
// —————————————————————————————————————————————————————————
Given(
  /^dado que existe una base de datos con nombre "([^"]*)"$/,
  async (dbName: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      CrearBaseDeDatos
        .conNombre(dbName)
        .conPermisos(['owner:any'])
    );

    // Si quieres guardar el ID real devuelto:
    // const body = await LastResponse.body<{ $id: string }>().answeredBy(actor);
    // (actor as any).remember('databaseId', body.$id);
  }
);


// —————————————————————————————————————————————————————————
// PASO ADICIONAL 2:
// “Then la respuesta debe incluir un campo collectionId”
// —————————————————————————————————————————————————————————
Then(
  /^la respuesta debe incluir un campo collectionId$/,
  async () => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosBaseDeDatos.campoCollectionId()
    );
  }
);


// —————————————————————————————————————————————————————————
// PASO ADICIONAL 3:
// “Dado que existe una colección "{string}" con ID $collectionId”
// —————————————————————————————————————————————————————————
Given(
  /^existe una colección "([^"]*)" con ID \$collectionId$/,
  async (collectionName: string) => {
    const actor = actorInTheSpotlight();
    (actor as any).remember('collectionId', collectionName.toLowerCase());
  }
);
