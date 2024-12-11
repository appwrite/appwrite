> Appwrite Init tamamlandı! Bütün duyurulara, son haberlere [bizim Init sayfamızdan ](https://appwrite.io/init) ulaşabilirsiniz. 🚀

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img src="./public/images/banner.png" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Appwrite, Web, Mobil ve Flutter uygulamaları geliştirmek için bir backend platformudur. Açık kaynak topluluğu tarafından oluşturulmuş ve sevdiğiniz programlama dillerinde geliştirici deneyimi için optimize edilmiştir.</b>
    <br />
    <br />
</p>

<!-- [![Build Status](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![We're Hiring](https://img.shields.io/static/v1?label=We're&message=Hiring&color=blue&style=flat-square)](https://appwrite.io/company/careers)
[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Build Status](https://img.shields.io/github/actions/workflow/status/appwrite/appwrite/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![X Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Translate](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

[English](README.md) | [简体中文](README-CN.md) | Türkçe

[**Appwrite Cloud’un Genel Beta Sürümü Duyuruldu! Bugün kaydolun!**](https://cloud.appwrite.io)

Appwrite, Web, Mobil, Native veya Backend uygulamaları için uçtan uca bir backend sunucusudur ve Docker mikro hizmetleri olarak paketlenmiştir. Appwrite, modern bir backend API’sini sıfırdan oluşturmanın gerektirdiği karmaşıklığı ve tekrarı soyutlayarak, güvenli uygulamaları daha hızlı geliştirmenize olanak tanır.

Appwrite kullanarak, uygulamanıza kolayca kullanıcı kimlik doğrulama ve birden fazla oturum açma yöntemi, kullanıcı ve ekip verilerini depolayıp sorgulamak için bir veritabanı, depolama ve dosya yönetimi, görsel işleme, Bulut Fonksiyonları ve [daha fazlasını](https://appwrite.io/docs) entegre edebilirsiniz.

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Daha fazlasını öğrenmek için: [https://appwrite.io](https://appwrite.io)

İçindekiler:

- [Kurulum](#installation)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Eski Bir Sürümden Yükseltme](#upgrade-from-an-older-version)
- [Tek Tıkla Kurulumlar](#one-click-setups)
- [Buradan Başlayın](#getting-started)
  - [Hizmetler](#services)
  - [SDK’ler](#sdks)
    - [İstemci](#client)
    - [Sunucu](#server)
    - [Topluluk](#community)
- [Mimari](#architecture)
- [Katkıda Bulunma](#contributing)
- [Güvenlik](#security)
- [Bizi Takip Edin](#follow-us)
- [Lisans](#license)

## Kurulum

Appwrite, konteyner tabanlı bir ortamda çalışacak şekilde tasarlanmıştır. Sunucunuzu çalıştırmak, terminalinizde tek bir komut çalıştırmak kadar kolaydır. Appwrite’ı, ya docker-compose kullanarak yerel bilgisayarınızda çalıştırabilir ya da [Kubernetes](https://kubernetes.io/docs/home/), [Docker Swarm](https://docs.docker.com/engine/swarm/) veya [Rancher](https://rancher.com/docs/) gibi başka bir konteyner orkestrasyon aracıyla yönetebilirsiniz.

Appwrite sunucunuzu çalıştırmaya başlamanın en kolay yolu, docker-compose dosyamızı çalıştırmaktır. Kurulum komutunu çalıştırmadan önce, bilgisayarınızda [Docker](https://www.docker.com/products/docker-desktop)’ın kurulu olduğundan emin olun:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.6.0
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.6.0
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.6.0
```

Docker kurulumu tamamlandıktan sonra, tarayıcınızdan Appwrite konsoluna erişmek için http://localhost adresine gidin. Lütfen, Linux dışındaki yerel sistemlerde, kurulum tamamlandıktan sonra sunucunun başlatılması birkaç dakika sürebilir.

Gelişmiş üretim ve özelleştirilmiş kurulumlar için Docker [ortam değişkenleri](https://appwrite.io/docs/environment-variables)belgelerimizi inceleyebilirsiniz. Ayrıca, ortamı manuel olarak ayarlamak için genel [docker-compose.yml](https://appwrite.io/install/compose) ve [.env](https://appwrite.io/install/env) dosyalarımızı kullanabilirsiniz.

### Eski Bir Sürümden Yükseltme

Appwrite sunucunuzu eski bir sürümden yükseltiyorsanız, kurulum tamamlandıktan sonra Appwrite geçiş aracını kullanmalısınız. Bu konuda daha fazla bilgi için [kurulum belgelerini](https://appwrite.io/docs/installation)'ni inceleyebilirsiniz.

## Tek Tıkla Kurulumlar

Appwrite’ı yerel olarak çalıştırmanın yanı sıra, önceden yapılandırılmış bir kurulum kullanarak da Appwrite’ı başlatabilirsiniz. Bu yöntem, yerel makinenize Docker kurmadan Appwrite’ı hızlı bir şekilde çalıştırmanıza olanak tanır.

Aşağıdaki sağlayıcılardan birini seçin:

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

## Buradan Başlayın

Appwrite ile başlamak, yeni bir proje oluşturmak, platformunuzu seçmek ve SDK’sını kodunuza entegre etmek kadar kolaydır. Seçtiğiniz platformla kolayca başlamak için başlangıç kılavuzlarımızdan birini okuyabilirsiniz.

| Platform              | Teknoloji                                                                          |
| --------------------- | ---------------------------------------------------------------------------------- |
| **Web**               | [Web için hızlı başlangıç](https://appwrite.io/docs/quick-starts/web)                   |
|                       | [Next.js için hızlı başlangıç](https://appwrite.io/docs/quick-starts/nextjs)            |
|                       | [React için hızlı başlangıç](https://appwrite.io/docs/quick-starts/react)               |
|                       | [Vue.js için hızlı başlangıç](https://appwrite.io/docs/quick-starts/vue)                |
|                       | [Nuxt için hızlı başlangıç](https://appwrite.io/docs/quick-starts/nuxt)                 |
|                       | [SvelteKit için hızlı başlangıç](https://appwrite.io/docs/quick-starts/sveltekit)       |
|                       | [Refine için hızlı başlangıç](https://appwrite.io/docs/quick-starts/refine)             |
|                       | [Angular için hızlı başlangıç](https://appwrite.io/docs/quick-starts/angular)           |
| **Mobil ve Yerel**    | [React Native için hızlı başlangıç](https://appwrite.io/docs/quick-starts/react-native) |
|                       | [Flutter için hızlı başlangıç](https://appwrite.io/docs/quick-starts/flutter)           |
|                       | [Apple için hızlı başlangıç](https://appwrite.io/docs/quick-starts/apple)               |
|                       | [Android için hızlı başlangıç](https://appwrite.io/docs/quick-starts/android)           |
| **Sunucu**            | [Node.js için hızlı başlangıç](https://appwrite.io/docs/quick-starts/node)              |
|                       | [Python için hızlı başlangıç](https://appwrite.io/docs/quick-starts/python)             |
|                       | [.NET için hızlı başlangıç](https://appwrite.io/docs/quick-starts/dotnet)               |
|                       | [Dart için hızlı başlangıç](https://appwrite.io/docs/quick-starts/dart)                 |
|                       | [Ruby için hızlı başlangıç](https://appwrite.io/docs/quick-starts/ruby)                 |
|                       | [Deno için hızlı başlangıç](https://appwrite.io/docs/quick-starts/deno)                 |
|                       | [PHP için hızlı başlangıç](https://appwrite.io/docs/quick-starts/php)                   |
|                       | [Kotlin için hızlı başlangıç](https://appwrite.io/docs/quick-starts/kotlin)             |
|                       | [Swift için hızlı başlangıç](https://appwrite.io/docs/quick-starts/swift)               |

### Ürünler

- [**Hesap**](https://appwrite.io/docs/references/cloud/client-web/account) - Mevcut kullanıcı kimlik doğrulamasını ve hesabını yönetin. Kullanıcı oturumlarını, cihazlarını, giriş yöntemlerini ve güvenlik günlüklerini takip edin ve yönetin.
- [**Kullanıcılar**](https://appwrite.io/docs/server/users) - Sunucu SDK’larıyla backend entegrasyonları oluştururken tüm proje kullanıcılarını listeleyin ve yönetin.
- [**Takımlar**](https://appwrite.io/docs/references/cloud/client-web/teams) - Kullanıcıları takımlar halinde yönetin ve gruplandırın. Takım üyeliklerini, davetleri ve kullanıcı rollerini yönetin.
- [**Veritabanları**](https://appwrite.io/docs/references/cloud/client-web/databases) - Veritabanlarını, koleksiyonları ve belgeleri yönetin. Belgeleri okuyun, oluşturun, güncelleyin ve silin. Gelişmiş filtreler kullanarak belge koleksiyonlarını listeleyin.
- [**Depolama**](https://appwrite.io/docs/references/cloud/client-web/storage) - Depolama dosyalarını yönetin. Dosyaları okuyun, oluşturun, silin ve önizleyin. Dosya önizlemelerini uygulamanıza mükemmel bir şekilde uyacak şekilde manipüle edin. Tüm dosyalar ClamAV tarafından taranır ve güvenli ve şifreli bir şekilde saklanır.
- [**Fonksiyonlar**](https://appwrite.io/docs/references/cloud/server-nodejs/functions) - Appwrite projenizi, güvenli ve izole bir ortamda özel kodunuzu çalıştırarak özelleştirin. Kodunuzu herhangi bir Appwrite sistem olayında manuel olarak veya CRON zamanlayıcısı kullanarak tetikleyebilirsiniz.
- [**Mesajlaşma**](https://appwrite.io/docs/references/cloud/client-web/messaging) - Appwrite Mesajlaşma ile kullanıcılarınıza push bildirimleri, e-postalar ve SMS metin mesajları göndererek iletişim kurun.
- [**Gerçek Zamanlı**](https://appwrite.io/docs/realtime) - Kullanıcılar, depolama, fonksiyonlar, veritabanları ve daha fazlası dahil olmak üzere Appwrite hizmetlerinizin herhangi birine ait gerçek zamanlı olayları dinleyin.
- [**Yerel**](https://appwrite.io/docs/references/cloud/client-web/locale) - Kullanıcınızın konumunu takip edin ve uygulamanızın yerel veri yönetimini yapın.
- [**Avatarlar**](https://appwrite.io/docs/references/cloud/client-web/avatars) - Kullanıcı avatarlarını, ülke bayraklarını, tarayıcı simgelerini ve kredi kartı sembollerini yönetin. Linklerden veya düz metin dizelerinden QR kodları oluşturun.

Tam API dokümantasyonu için [https://appwrite.io/docs](https://appwrite.io/docs) adresini ziyaret edin. Daha fazla öğretici, haber ve duyuru için [blogumuzu](https://medium.com/appwrite-io) ve [Discord Sunucumuzu](https://discord.gg/GSeTUeA) inceleyin.

### SDK'lar

Aşağıda, şu anda desteklenen platformlar ve dillerin bir listesi bulunmaktadır. Tercih ettiğiniz platform için destek eklememize yardımcı olmak isterseniz, [SDK Sağlayıcısı](https://github.com/appwrite/sdk-generator) projemizi ziyaret edebilir ve [katkı rehberimizi](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md) inceleyebilirsiniz.

#### İstemci

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [React Native](https://github.com/appwrite/sdk-for-react-native) - **Beta** (Appwrite Ekibi tarafından yönetilmektedir.)

#### Sunucu

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [Swift](https://github.com/appwrite/sdk-for-swift) (Appwrite Ekibi tarafından yönetilmektedir.)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Beta** (Appwrite Ekibi tarafından yönetilmektedir.)

#### Topluluk

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) ([Michael Gangolf](https://github.com/m1ga/) tarafından yönetilmektedir.)
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) ([fenix-hub @GodotNuts](https://github.com/fenix-hub) tarafından yönetilmektedir.)

Daha fazla SDK mı arıyorsunuz? - [SDK Sağlayıcısı](https://github.com/appwrite/sdk-generator) projemize bir pull request göndererek bize destek olabilirsiniz!

## Mimari

![Appwrite Mimarisi](docs/specs/overview.drawio.svg)

Appwrite, kolay ölçeklenebilirlik ve sorumlulukların dağıtımı için tasarlanmış bir mikro hizmet mimarisi kullanır. Ayrıca Appwrite, mevcut bilginizi ve tercih ettiğiniz protokolleri kullanarak kaynaklarınızla etkileşim kurmanıza olanak tanıyan REST, WebSocket ve GraphQL gibi birden fazla API'yi destekler.

Appwrite API katmanı, bellek içi önbellekleme kullanarak ve ağır işlemleri Appwrite arka plan çalışanlarına devrederek son derece hızlı olacak şekilde tasarlanmıştır. Arka plan çalışanları, yükü yönetmek için bir mesaj kuyruğu kullanarak hesaplama kapasitenizi ve maliyetlerinizi hassas bir şekilde kontrol etmenizi de sağlar. Mimari hakkında daha fazla bilgiye [katkı rehberi](CONTRIBUTING.md#architecture-1) üzerinden ulaşabilirsiniz.

## Katkıda Bulunma

Tüm kod katkıları, commit erişimine sahip olanların katkıları da dahil olmak üzere, bir pull request üzerinden gönderilmeli ve bir çekirdek geliştirici tarafından onaylanmadan önce birleştirilmemelidir. Bu, tüm kodun doğru bir şekilde incelenmesini sağlamak içindir.

Pull request'lere gerçekten ❤️ ile yaklaşıyoruz! Eğer yardımcı olmak isterseniz, bu projeye nasıl katkıda bulunabileceğiniz hakkında daha fazla bilgiyi [katkı rehberi](CONTRIBUTING.md) üzerinden öğrenebilirsiniz.

## Güvenlik

Güvenlik sorunları için lütfen GitHub'da herkese açık bir sorun oluşturmak yerine bize security@appwrite.io adresinden e-posta gönderin.

## Bizi Takip Edin

Dünyanın dört bir yanındaki büyüyen topluluğumuza katılın! Resmi [Blogumuzu](https://appwrite.io/blog) inceleyin. Bizi [X](https://twitter.com/appwrite), [LinkedIn](https://www.linkedin.com/company/appwrite/), [Dev Community](https://dev.to/appwrite) platformlarında takip edin ya da daha fazla yardım, fikir ve tartışma için canlı [Discord sunucumuza](https://appwrite.io/discord) katılın.

## Lisans

Bu depo, [BSD 3-Clause Lisansı](./LICENSE) altında sunulmaktadır.
