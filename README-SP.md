> ¡Grandes noticias! ¡Appwrite Cloud está ahora en beta pública! Regístrese en [cloud.appwrite.io](https://cloud.appwrite.io) por una experiencia alojada sin problemas. ¡Únete a nosotros en la nube hoy!  ⁇ 🎉

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img src="./public/images/banner.png" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Appwrite es una plataforma de backend para desarrollar aplicaciones Web, Móviles y Flutter. Construido con la comunidad de código abierto y optimizado para la experiencia del desarrollador en los lenguajes de codificación que amas.</b>
    <br />
    <br />
</p>


<!-- [![Estado de construcción](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Estamos Contratando](https://img.shields.io/static/v1?label=We're&message=Hiring&color=blue&style=flat-square)](https://appwrite.io/company/careers)
[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Estado de construcción](https://img.shields.io/github/actions/workflow/status/appwrite/appwrite/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Cuenta de Twitter](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Traducir](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

English | [简体中文](README-CN.md) | [español](README-SP.md)

¡[**Anunciando Appwrite Cloud Public Beta! ¡Regístrate hoy!**](https://cloud.appwrite.io)

Appwrite es un servidor backend de extremo a extremo para aplicaciones Web, Móviles, Nativas o Backend empaquetado como un conjunto de microservicios Docker<nobr>. Appwrite abstrae la complejidad y la repetitividad necesarias para crear una API de backend moderna desde cero y le permite crear aplicaciones seguras más rápido.

Con Appwrite, puede integrar fácilmente su aplicación con autenticación de usuario y múltiples métodos de inicio de sesión, una base de datos para almacenar y consultar datos de usuarios y equipos, almacenamiento y administración de archivos, manipulación de imágenes, etc, Cloud Functions, y [más servicios](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="ancho: 250px; altura: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](público/imágenes/github.png)

Obtenga más información en: [https://appwrite.io](https://appwrite.io)

Tabla de Contenido:

- [Instalación](#instalación)
  - [Unix](#unix)
  - [Ventanas](#ventanas)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Actualizar desde una Versión Antigua](#actualizar desde una versión anterior)
- [Configuración de One-Click](#configuración de un clic)
- [Comenzando](#comenzando)
  - [Servicios](#servicios)
  - [SDK](#sdks)
    - [Cliente](#cliente)
    - [Servidor](#servidor)
    - [Comunidad](#comunidad)
- [Arquitectura](#arquitectura)
- [Contribuyendo](#contribuyendo)
- [Seguridad](#seguridad)
- [Síguenos](#síguenos)
- [Licencia](#licencia)

## Instalación

Appwrite está diseñado para ejecutarse en un entorno en contenedores. Ejecutar su servidor es tan fácil como ejecutar un comando desde su terminal. Puede ejecutar Appwrite en su localhost utilizando docker-compose o en cualquier otra herramienta de orquestación de contenedores, como Kubernetes, Docker Swarm o Rancher.

La forma más fácil de comenzar a ejecutar su servidor Appwrite es ejecutando nuestro archivo docker-compose. Antes de ejecutar el comando de instalación, asegúrese de tener [Docker](https://www.docker.com/products/docker-desktop) instalado en su máquina:

### Unix

'''bash
docker run - es --rm \
    - volumen /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite: 1.4.5
''gráfico

### Windows

#### CMD

''adgalucmd
docker run - es --rm ^
    - volumen //var/run/docker.sock:/var/run/docker.sock ^
    - Volumen "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite: 1.4.5
''gráfico

#### PowerShell

''powershell
docker run - es - rm
    - volumen /var/run/docker.sock:/var/run/docker.sock urs
    --volumen ${pwd}/appwrite:/usr/src/code/appwrite:rw
    --entrypoint="install" á
    appwrite/appwrite: 1.4.5
''gráfico

Una vez que se complete la instalación de Docker, vaya a http://localhost para acceder a la consola Appwrite desde su navegador. Tenga en cuenta que en los hosts no nativos de Linux, el servidor puede tardar unos minutos en comenzar después de completar la instalación.

Para una producción avanzada e instalación personalizada, consulte nuestras Docker [variables de entorno](https://appwrite.io/docs/environment-variables) documentos. También puede usar nuestros archivos públicos [docker-compose.yml](https://appwrite.io/install/compose) y [.env](https://appwrite.io/install/env) para configurar manualmente un entorno.

### Actualizar desde una Versión más Antigua

Si está actualizando su servidor de Appwrite desde una versión anterior, debe usar la herramienta de migración de Appwrite una vez completada la configuración. Para obtener más información sobre esto, consulte [Installation Docs](https://appwrite.io/docs/installation).

## Configuración de One-Click

Además de ejecutar Appwrite localmente, también puede iniciar Appwrite utilizando una configuración preconfigurada. Esto le permite ponerse en marcha rápidamente con Appwrite sin instalar Docker en su máquina local.

Elija entre uno de los proveedores a continuación:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="público/imágenes/integraciones/digitalocean-logo.svg" alt="DigitalOcean Logo"/>
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo"/>
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://www.linode.com/marketplace/apps/appwrite/appwrite/">
        <img width="50" height="39" src="public/images/integrations/akamai-logo.svg" alt="Akamai Logo"/>
          <br /><sub><b>Akamai Compute</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Comenzando

Comenzar con Appwrite es tan fácil como crear un nuevo proyecto, elegir su plataforma e integrar su SDK en su código. Puede comenzar fácilmente con su plataforma de elección leyendo uno de nuestros tutoriales de Getting Started.

- [Comenzando para Web](https://appwrite.io/docs/getting-started-for-web)
- [Comenzando para Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Comenzando para Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Comenzando para Android](https://appwrite.io/docs/getting-started-for-android)
- [Comenzando para Servidor](https://appwrite.io/docs/getting-started-for-server)
- [Comenzando para CLI](https://appwrite.io/docs/command-line)

### Servicios

- [**Cuenta**](https://appwrite.io/docs/references/cloud/client-web/account) - Administrar la autenticación y la cuenta de usuario actual. Rastree y administre las sesiones de usuario, los dispositivos, los métodos de inicio de sesión y los registros de seguridad.
- [**Usuarios**](https://appwrite.io/docs/server/users) - Administre y enumere todos los usuarios del proyecto al crear integraciones de backend con SDK de servidor.
- [**Equipos**](https://appwrite.io/docs/references/cloud/client-web/teams) - Administrar y agrupar usuarios en equipos. Administre membresías, invitaciones y roles de usuario dentro de un equipo.
- [**Bases de datos**](https://appwrite.io/docs/references/cloud/client-web/databases) - Administrar bases de datos, colecciones y documentos. Lea, cree, actualice y elimine documentos y filtre listas de colecciones de documentos utilizando filtros avanzados.
- [**Almacenamiento**](https://appwrite.io/docs/references/cloud/client-web/storage) - Administrar archivos de almacenamiento. Lea, cree, elimine y obtenga una vista previa de los archivos. Manipula la vista previa de tus archivos para que se ajusten perfectamente a tu app. Todos los archivos son escaneados por ClamAV y almacenados de forma segura y encriptada.
- [**Funciones**](https://appwrite.io/docs/server/functions) - Personaliza tu servidor Appwrite ejecutando tu código personalizado en un entorno seguro y aislado. Puede activar su código en cualquier evento del sistema Appwrite manualmente o utilizando un programa CRON.
- [**Realtime**](https://appwrite.io/docs/realtime) - Escuche eventos en tiempo real para cualquiera de sus servicios de Appwrite, incluidos usuarios, almacenamiento, funciones, bases de datos y más.
- [**Locale**](https://appwrite.io/docs/references/cloud/client-web/locale) - Rastree la ubicación de su usuario y administre los datos basados en la configuración regional de su aplicación.
- [**Avatares**](https://appwrite.io/docs/references/cloud/client-web/avatars) - Administre los avatares de sus usuarios, las banderas de los países, los iconos del navegador y los símbolos de la tarjeta de crédito. Genere códigos QR a partir de enlaces o cadenas de texto sin formato.

- Para obtener la documentación completa de la API, visite [https://appwrite.io/docs](https://appwrite.io/docs). Para obtener más tutoriales, noticias y anuncios, consulte nuestro [blog](https://medium.com/appwrite-io) y [Discord Server](https://discord.gg/GSeTUeA).

### SDK

A continuación se muestra una lista de plataformas e idiomas actualmente compatibles. Si desea ayudarnos a agregar soporte a su plataforma de elección, puede ir a nuestro [SDK Generator](https://github.com/appwrite/sdk-generator) proyecto y ver nuestra [guía de contribución](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Mantenido por el Equipo de Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Mantenido por el equipo de Appwrite)

#### Servidor

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Mantenido por el Equipo Appwrite)

#### Comunidad

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Mantenido por [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Mantenido por [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Buscando más SDK? - Ayúdanos contribuyendo con una solicitud de extracción a nuestro [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Arquitectura

![Arquitectura Appwrite](docs/specs/overview.drawio.svg)

Appwrite utiliza una arquitectura de microservicios que fue diseñada para una fácil ampliación y delegación de responsabilidades. Además, Appwrite admite múltiples API, como REST, WebSocket y GraphQL para permitirle interactuar con sus recursos aprovechando sus conocimientos y protocolos existentes de elección.

La capa API de Appwrite fue diseñada para ser extremadamente rápida aprovechando el almacenamiento en caché en memoria y delegando cualquier tarea de carga pesada a los trabajadores de fondo de Appwrite. Los trabajadores de fondo también le permiten controlar con precisión su capacidad de cómputo y sus costos utilizando una cola de mensajes para manejar la carga. Puede obtener más información sobre nuestra arquitectura en la [guía de contribución](CONTRIBUTING.md#architecture-1).

## Contribuyendo

Todas las contribuciones de código, incluidas las de las personas que tienen acceso de compromiso, deben pasar por una solicitud de extracción y ser aprobadas por un desarrollador principal antes de fusionarse. Esto es para garantizar una revisión adecuada de todo el código.

¡Realmente  ⁇  tiramos de solicitudes! Si desea ayudar, puede obtener más información sobre cómo puede contribuir a este proyecto en la [guía de contribución](CONTRIBUTING.md).

## Seguridad

Para problemas de seguridad, envíenos un correo electrónico a [security@appwrite.io](mailto:security@appwrite.io) en lugar de publicar un problema público en GitHub.

## Síguenos

¡Únete a nuestra creciente comunidad en todo el mundo! Echa un vistazo a nuestro oficial [Blog](https://medium.com/appwrite-io). Síguenos en [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/), [Dev Community](https://dev.to/appwrite) o únete a nuestro live [Discord server](https://discord.gg/GSeTUeA) por más ayuda, ideas y discusiones.
