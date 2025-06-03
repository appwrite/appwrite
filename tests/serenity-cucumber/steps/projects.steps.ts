// steps/projects.steps.ts

import { Given, When, Then }               from '@cucumber/cucumber';
import { actorCalled, actorInTheSpotlight } from '@serenity-js/core';
import { CallAnApi }                        from '@serenity-js/rest';

import { ConfiguracionApi }                 from '../utils/apiConfig';
import { CrearProyecto }                    from '../tasks/projects/createProject';
import { ListarProyectos }                  from '../tasks/projects/listProjects';
import { ObtenerProyecto }                  from '../tasks/projects/getProject';
import { ActualizarProyecto }               from '../tasks/projects/updateProject';
import { EliminarProyecto }                 from '../tasks/projects/deleteProject';

import { IncluyeDatosProyecto }             from '../questions/didReturnProjectData';
import { CodigoRespuestaEsperado }          from '../questions/isResponseCode';


// —————————————————————————————————————————————————————————
// 1) “Dado que no existe un proyecto con nombre "{string}"”
// —————————————————————————————————————————————————————————
Given(
  /^dado que no existe un proyecto con nombre "([^"]*)"$/,
  async (nombre: string) => {
    // Creamos (o reutilizamos) el actor “Administrador” con habilidad HTTP
    const actor = actorCalled('Administrador').whoCan(
      CallAnApi.at(ConfiguracionApi.host)
    );

    // Opción: intentar eliminarlo si ya existiera
    // await actor.attemptsTo(
    //   EliminarProyecto.porId(nombre.toLowerCase())
    // );
    // Pero en este paso simplemente no hacemos nada adicional.
  }
);


// —————————————————————————————————————————————————————————
// 2) “When el Actor envía un POST a /v1/projects con:”
//       | name   | ProyectoPrueba         |
//       | teamId | equipoPrueba           |
//       | email  | "admin@ejemplo.com"    |
// —————————————————————————————————————————————————————————
When(
  /^el Actor envía un POST a \/v1\/projects con:$/,
  async (tabla) => {
    const datos = tabla.rowsHash();

    // Aseguramos que el actor “Administrador” tenga la habilidad HTTP
    const actor = actorCalled('Administrador').whoCan(
      CallAnApi.at(ConfiguracionApi.host)
    );

    await actor.attemptsTo(
      CrearProyecto
        .conNombre(datos.name.replace(/"/g, ''))
        .enEquipo(datos.teamId)
        .conCorreo(datos.email.replace(/"/g, ''))
    );

    // Opcional: guardar projectId real devuelto
    // const response = await LastResponse.body<{ $id: string }>().answeredBy(actor);
    // (actor as any).remember('projectId', response.$id);
  }
);


// —————————————————————————————————————————————————————————
// 3) “When el Actor solicita GET /v1/projects”
// —————————————————————————————————————————————————————————
When(
  /^el Actor solicita GET \/v1\/projects$/,
  async () => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      ListarProyectos.desde()
    );
  }
);


// —————————————————————————————————————————————————————————
// 4) “Dado que existe un proyecto con nombre "{string}" y ID $projectId”
// —————————————————————————————————————————————————————————
Given(
  /^dado que existe un proyecto con nombre "([^"]*)" y ID \$projectId$/,
  async (projectName: string) => {
    const actor = actorInTheSpotlight();

    await actor.attemptsTo(
      CrearProyecto
        .conNombre(projectName)
        .enEquipo('equipoPrueba')
        .conCorreo('admin@ejemplo.com')
    );

    // Guardamos el “projectId” simulado:
    (actor as any).remember('projectId', projectName.toLowerCase());
  }
);


// —————————————————————————————————————————————————————————
// 5) “When el Actor solicita GET /v1/projects/{projectId}”
// —————————————————————————————————————————————————————————
When(
  /^el Actor solicita GET \/v1\/projects\/([^"]*)$/,
  async (projectId: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      ObtenerProyecto.porId(projectId)
    );
  }
);


// —————————————————————————————————————————————————————————
// 6) “When el Actor envía PATCH /v1/projects/{projectId} con:”
//       | name | "ProyectoRenombrado" |
// —————————————————————————————————————————————————————————
When(
  /^el Actor envía PATCH \/v1\/projects\/([^"]*) con:$/,
  async (projectId: string, tabla) => {
    const datos = tabla.rowsHash();
    const actor = actorInTheSpotlight();

    await actor.attemptsTo(
      ActualizarProyecto
        .porId(projectId)
        .conNombre(datos.name.replace(/"/g, ''))
    );
  }
);


// —————————————————————————————————————————————————————————
// 7) “When el Actor envía DELETE /v1/projects/{projectId}”
// —————————————————————————————————————————————————————————
When(
  /^el Actor envía DELETE \/v1\/projects\/([^"]*)$/,
  async (projectId: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      EliminarProyecto.porId(projectId)
    );
  }
);


// —————————————————————————————————————————————————————————
// 8) “Then el JSON retornado debe incluir un campo projectId”
// —————————————————————————————————————————————————————————
Then(
  /^el JSON retornado debe incluir un campo projectId$/,
  async () => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosProyecto.campoProjectId()
    );
  }
);


// —————————————————————————————————————————————————————————
// 9) “Then la lista devuelta de proyectos debe incluir "{string}"”
// —————————————————————————————————————————————————————————
Then(
  /^la lista devuelta de proyectos debe incluir "([^"]*)"$/,
  async (projectName: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosProyecto.nombreEnLista(projectName)
    );
  }
);


// —————————————————————————————————————————————————————————
// 10) “Then el JSON retornado debe tener name "{string}"”
// —————————————————————————————————————————————————————————
Then(
  /^el JSON retornado debe tener name "([^"]*)"$/,
  async (projectName: string) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      IncluyeDatosProyecto.proyectoConNombre(projectName)
    );
  }
);


// —————————————————————————————————————————————————————————
// 11) “Then una solicitud GET /v1/projects/{projectId} devuelve 404”
// —————————————————————————————————————————————————————————
Then(
  /^una solicitud GET \/v1\/projects\/([^"]*) devuelve 404$/,
  async (projectId: string) => {
    const actor = actorInTheSpotlight();

    // Primero hacemos el GET (esperamos 404):
    await actor.attemptsTo(
      ObtenerProyecto.porId(projectId)
    );

    // Luego validamos que el status sea 404:
    await actor.attemptsTo(
      CodigoRespuestaEsperado.es(404)
    );
  }
);
