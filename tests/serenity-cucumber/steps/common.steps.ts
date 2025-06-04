// steps/common.steps.ts

import { Given, Then }               from '@cucumber/cucumber';
import { actorCalled, actorInTheSpotlight } from '@serenity-js/core';
import { CallAnApi }                 from '@serenity-js/rest';

import { ConfiguracionApi }          from '../utils/apiConfig';
import { CodigoRespuestaEsperado }   from '../questions/isResponseCode';


// —————————————————————————————————————————————————————————
// 1) “Dado que la API está disponible”
// —————————————————————————————————————————————————————————
Given(
  /^que la API está disponible$/,
  () => {
    actorCalled('Administrador').whoCan(
      CallAnApi.at(ConfiguracionApi.host)
    );
  }
);


// —————————————————————————————————————————————————————————
// 2) “Then el código de respuesta debe ser {int}”
// —————————————————————————————————————————————————————————
Then(
  /^el código de respuesta debe ser (\d+)$/,
  async (codigoEsperado: number) => {
    const actor = actorInTheSpotlight();
    await actor.attemptsTo(
      CodigoRespuestaEsperado.es(codigoEsperado)
    );
  }
);
