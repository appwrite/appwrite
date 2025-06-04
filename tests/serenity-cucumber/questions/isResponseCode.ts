// serenity-cucumber/questions/isResponseCode.ts

import { Question, UsesAbilities, AnswersQuestions } from '@serenity-js/core';
import { LastResponse }                                  from '@serenity-js/rest';

export class CodigoRespuestaEsperado {

    /**
     * Crea una pregunta que verifica que el status HTTP de la última
     * petición coincida con el valor `esperado`.
     */
    static es(esperado: number) {
        return Question.about(
            `el código de respuesta es ${ esperado }`,
            /**
             * El actor debe implementar tanto UsesAbilities (para usar lastResponse)
             * como AnswersQuestions (para poder "responder" preguntas internas).
             */
            (actor: UsesAbilities & AnswersQuestions) =>
                LastResponse
                    .status()
                    .answeredBy(actor)      // Ahora el tipo de actor es correcto
                    .then((real: number) => {
                        if (real !== esperado) {
                            throw new Error(
                                `Código de respuesta: esperado ${ esperado } pero fue ${ real }`
                            );
                        }
                    })
        );
    }
}
