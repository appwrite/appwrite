# language: es
Característica: Crear Documento

Escenario: Como administrador, puedo insertar un nuevo documento en una colección
  Dado que la API está disponible
  Y dado que existe una base de datos "MiBasePrueba" con ID $databaseId
  Y existe una colección "MiColeccion" con ID $collectionId con atributos:
    | clave   | tipo    | tamaño |
    | titulo  | string  | 255    |
    | cuenta  | integer | null   |
  Cuando el Actor crea un nuevo documento con:
    | titulo | "Hola Mundo" |
    | cuenta | 42           |
  Entonces el código de respuesta debe ser 201
  Y el documento retornado debe tener titulo "Hola Mundo" y cuenta 42
