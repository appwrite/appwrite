# language: es
Característica: Obtener Detalles de Proyecto

Escenario: Como administrador, puedo recuperar un proyecto por ID
  Dado que la API está disponible
  Y dado que existe un proyecto "ProyectoPrueba" con ID $projectId
  Cuando el Actor solicita GET /v1/projects/$projectId
  Entonces el código de respuesta debe ser 200
  Y el JSON retornado debe tener name "ProyectoPrueba"
