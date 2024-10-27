> ¡Appwrite Init ha concluido! Puedes revisar todos los anuncios [en nuestro sitio web de Init](https://appwrite.io/init) 🚀

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img src="./public/images/banner.png" alt="Logo de Appwrite"></a>
    <br />
    <br />
    <b>Appwrite es una plataforma backend para desarrollar aplicaciones Web, Móviles y Flutter. Construida con la comunidad de código abierto y optimizada para mejorar la experiencia del desarrollador en los lenguajes de programación que amas.</b>
    <br />
    <br />
</p>

<!-- [![Estado de la compilación](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Estamos contratando](https://img.shields.io/static/v1?label=Estamos&message=Contratando&color=blue&style=flat-square)](https://appwrite.io/company/careers)
[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Estado de compilación](https://img.shields.io/github/actions/workflow/status/appwrite/appwrite/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Cuenta en X](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Descargas de Docker](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Traducciones](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Tienda de Swag](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

[English](README.md) | [简体中文](README-CN.md) | Español

[**¡Introduciendo la beta pública de Appwrite Cloud! Regístrate hoy**](https://cloud.appwrite.io)

Appwrite es un servidor backend de extremo a extremo para aplicaciones Web, Móviles, Nativas o Backend empaquetado como un conjunto de microservicios Docker. Appwrite abstrae la complejidad y repetitividad necesaria para construir una API backend moderna desde cero, permitiéndote crear aplicaciones seguras más rápido.

Usando Appwrite, puedes integrar fácilmente en tu aplicación autenticación de usuario y múltiples métodos de inicio de sesión, una base de datos para almacenar y consultar datos de usuarios y equipos, almacenamiento y administración de archivos, manipulación de imágenes, Funciones en la Nube y [más servicios](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;alternativa&#0032;open&#0032;source&#0032;a&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Descubre más en: [https://appwrite.io](https://appwrite.io)

Tabla de Contenidos:

- [Instalación](#installation)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Actualizar desde una Versión Anterior](#upgrade-from-an-older-version)
- [Instalaciones de un Solo Clic](#one-click-setups)
- [Empezando](#getting-started)
  - [Servicios](#services)
  - [SDKs](#sdks)
    - [Cliente](#client)
    - [Servidor](#server)
    - [Comunidad](#community)
- [Arquitectura](#architecture)
- [Contribuir](#contributing)
- [Seguridad](#security)
- [Síguenos](#follow-us)
- [Licencia](#license)

## Instalación

Appwrite está diseñado para ejecutarse en un entorno containerizado. Ejecutar tu servidor es tan fácil como ejecutar un comando desde tu terminal. Puedes ejecutar Appwrite en tu localhost usando docker-compose o en cualquier otra herramienta de orquestación de contenedores, como [Kubernetes](https://kubernetes.io/docs/home/), [Docker Swarm](https://docs.docker.com/engine/swarm/) o [Rancher](https://rancher.com/docs/).

La manera más fácil de comenzar a ejecutar tu servidor Appwrite es ejecutando nuestro archivo docker-compose. Antes de ejecutar el comando de instalación, asegúrate de tener [Docker](https://www.docker.com/products/docker-desktop) instalado en tu máquina:


### Unix

bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.6.0



### Windows

#### CMD

cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.6.0



#### PowerShell

powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.6.0


Una vez que la instalación con Docker está completada, ve a http://localhost para acceder a la consola de Appwrite desde tu navegador. Por favor, tenga en cuenta que para los sistemas no-Linux nativos, el servidor puede tomaralgunos minutos en comenzar tras la instalación.

Para producción avanzada e instalación personalizada, mire nuestra documentación de [variables de entorno](https://appwrite.io/docs/environment-variables) de Docker. También puedes usar nuestro [docker-compose.yml](https://appwrite.io/install/compose) público y archivo [.env](https://appwrite.io/install/env) para configurar un entorno manualmente.

### Actualziar desde una versión anterior

Si estás actualizando tu servidor de Appwrite desde una versión anterior, debes de usar la herramienta de migración una vez se haya completado la instalación. Para más información, consulte nuestra [documentación de actualización](https://appwrite.io/docs/advanced/self-hosting/update).

## Instalación en Un-Click 

Además de ejecutar Appwrite localmente, también puedes iniciar Appwrite utilizando una configuración preconfigurada. Esto te permite comenzar rápidamente con Appwrite sin instalar Docker en tu máquina local.

Elige uno de los proveedores a continuación:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="DigitalOcean Logo" />
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo" />
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://www.linode.com/marketplace/apps/appwrite/appwrite/">
        <img width="50" height="39" src="public/images/integrations/akamai-logo.svg" alt="Akamai Logo" />
          <br /><sub><b>Akamai Compute</b></sub></a>    
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://aws.amazon.com/marketplace/pp/prodview-2hiaeo2px4md6">
        <img width="50" height="39" src="public/images/integrations/aws-logo.svg" alt="AWS Logo" />
          <br /><sub><b>AWS Marketplace</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Comenzando

Comenzar con Appwrite es tan fácil como crear un nuevo proyecto, elegir tu plataforma e integrar su SDK en tu código. Puedes empezar fácilmente con la plataforma de tu elección leyendo uno de nuestros tutoriales de introducción.

| Plataforma             | Tecnología                                                                          |
| ---------------------- | ----------------------------------------------------------------------------------- |
| **Aplicación web**     | [Inicio rápido para Web](https://appwrite.io/docs/quick-starts/web)                 |
|                        | [Inicio rápido para Next.js](https://appwrite.io/docs/quick-starts/nextjs)          |
|                        | [Inicio rápido para React](https://appwrite.io/docs/quick-starts/react)             |
|                        | [Inicio rápido para Vue.js](https://appwrite.io/docs/quick-starts/vue)              |
|                        | [Inicio rápido para Nuxt](https://appwrite.io/docs/quick-starts/nuxt)               |
|                        | [Inicio rápido para SvelteKit](https://appwrite.io/docs/quick-starts/sveltekit)     |
|                        | [Inicio rápido para Refine](https://appwrite.io/docs/quick-starts/refine)           |
|                        | [Inicio rápido para Angular](https://appwrite.io/docs/quick-starts/angular)         |
| **Móvil y Nativo**     | [Inicio rápido para React Native](https://appwrite.io/docs/quick-starts/react-native) |
|                        | [Inicio rápido para Flutter](https://appwrite.io/docs/quick-starts/flutter)         |
|                        | [Inicio rápido para Apple](https://appwrite.io/docs/quick-starts/apple)             |
|                        | [Inicio rápido para Android](https://appwrite.io/docs/quick-starts/android)         |
| **Servidor**           | [Inicio rápido para Node.js](https://appwrite.io/docs/quick-starts/node)            |
|                        | [Inicio rápido para Python](https://appwrite.io/docs/quick-starts/python)           |
|                        | [Inicio rápido para .NET](https://appwrite.io/docs/quick-starts/dotnet)             |
|                        | [Inicio rápido para Dart](https://appwrite.io/docs/quick-starts/dart)               |
|                        | [Inicio rápido para Ruby](https://appwrite.io/docs/quick-starts/ruby)               |
|                        | [Inicio rápido para Deno](https://appwrite.io/docs/quick-starts/deno)               |
|                        | [Inicio rápido para PHP](https://appwrite.io/docs/quick-starts/php)                 |
|                        | [Inicio rápido para Kotlin](https://appwrite.io/docs/quick-starts/kotlin)           |
|                        | [Inicio rápido para Swift](https://appwrite.io/docs/quick-starts/swift)             |


### Productos

- [**Cuenta**](https://appwrite.io/docs/references/cloud/client-web/account) - Administra la autenticación y la cuenta del usuario actual. Rastrea y gestiona las sesiones de usuario, dispositivos, métodos de inicio de sesión y registros de seguridad.
- [**Usuarios**](https://appwrite.io/docs/server/users) - Administra y lista todos los usuarios del proyecto al construir integraciones de backend con los SDK de servidor.
- [**Equipos**](https://appwrite.io/docs/references/cloud/client-web/teams) - Administra y agrupa usuarios en equipos. Administra membresías, invitaciones y roles de usuario dentro de un equipo.
- [**Bases de datos**](https://appwrite.io/docs/references/cloud/client-web/databases) - Administra bases de datos, colecciones y documentos. Lee, crea, actualiza y elimina documentos y filtra listas de colecciones de documentos usando filtros avanzados.
- [**Almacenamiento**](https://appwrite.io/docs/references/cloud/client-web/storage) - Administra archivos de almacenamiento. Lee, crea, elimina y previsualiza archivos. Manipula la vista previa de tus archivos para que se ajusten perfectamente a tu aplicación. Todos los archivos son escaneados por ClamAV y almacenados de manera segura y encriptada.
- [**Funciones**](https://appwrite.io/docs/references/cloud/server-nodejs/functions) - Personaliza tu proyecto Appwrite ejecutando tu propio código en un entorno seguro y aislado. Puedes activar tu código en cualquier evento del sistema Appwrite de forma manual o mediante una programación CRON.
- [**Mensajería**](https://appwrite.io/docs/references/cloud/client-web/messaging) - Comunícate con tus usuarios a través de notificaciones push, correos electrónicos y mensajes de texto SMS usando Appwrite Messaging.
- [**Tiempo real**](https://appwrite.io/docs/realtime) - Escucha eventos en tiempo real para cualquiera de tus servicios Appwrite, incluyendo usuarios, almacenamiento, funciones, bases de datos y más.
- [**Localización**](https://appwrite.io/docs/references/cloud/client-web/locale) - Rastrea la ubicación de tus usuarios y gestiona los datos de tu aplicación según la configuración de localización.
- [**Avatares**](https://appwrite.io/docs/references/cloud/client-web/avatars) - Administra los avatares de tus usuarios, banderas de países, iconos de navegador y símbolos de tarjetas de crédito. Genera códigos QR a partir de enlaces o cadenas de texto plano.

Para una documentación completa de la API, visite [https://appwrite.io/docs](https://appwrite.io/docs). Para más tutoriales, noticias y anuncios, visite nuestro [blog](https://appwrite.io/blog) y nuestro [servidor de Discord](https://discord.gg/GSeTUeA).

### SDKs

A continuación se muestra una lista de las plataformas y lenguajes actualmente compatibles. Si desea ayudar a agregar soporte para su plataforma preferida, puede visitar nuestro [Generador de SDK](https://github.com/appwrite/sdk-generator) y ver nuestra [guía de contribución](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [React Native](https://github.com/appwrite/sdk-for-react-native) - **Beta** (Mantenido por el equipo de Appwrite)

#### Servidor

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Swift](https://github.com/appwrite/sdk-for-swift) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Beta** (Mantenido por el equipo de Appwrite)


#### Comunidad

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Mantenido por [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Mantenido por [fenix-hub @GodotNuts](https://github.com/fenix-hub))

¿Buscas más SDKs? - ¡Ayúdanos contribuyendo con una solicitud de extracción a nuestro [Generador de SDK](https://github.com/appwrite/sdk-generator)!

## Arquitectura

![Arquitectura de Appwrite](docs/specs/overview.drawio.svg)

Appwrite utiliza una arquitectura de microservicios diseñada para facilitar la escalabilidad y la delegación de responsabilidades. Además, Appwrite admite múltiples API, como REST, WebSocket y GraphQL, lo que te permite interactuar con tus recursos aprovechando tus conocimientos y protocolos existentes.

La capa de API de Appwrite fue diseñada para ser extremadamente rápida al aprovechar el almacenamiento en caché en memoria y delegar cualquier tarea pesada a los trabajadores en segundo plano de Appwrite. Los trabajadores en segundo plano también te permiten controlar con precisión tu capacidad de cómputo y costos utilizando una cola de mensajes para manejar la carga. Puedes obtener más información sobre nuestra arquitectura en la [guía de contribuciones](CONTRIBUTING.md#architecture-1).

## Contributing

All code contributions, including those of people having commit access, must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Seguridad

Para problemas de seguridad, envíanos un correo a [security@appwrite.io](mailto:security@appwrite.io) en lugar de publicar un issue público en GitHub.

## Síguenos

¡Únete a nuestra creciente comunidad en todo el mundo! Consulta nuestro [Blog](https://appwrite.io/blog). Síguenos en [X](https://twitter.com/appwrite), [LinkedIn](https://www.linkedin.com/company/appwrite/), [Dev Community](https://dev.to/appwrite) o únete a nuestro servidor de [Discord en vivo](https://appwrite.io/discord) para más ayuda, ideas y discusiones.

## Licencia

Este repositorio está disponible bajo la [Licencia BSD de 3 cláusulas](./LICENSE).

