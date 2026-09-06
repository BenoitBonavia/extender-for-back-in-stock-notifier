# Extender for Back In Stock Notifier

Extension maison de **Back In Stock Notifier for WooCommerce | WooCommerce Waitlist Pro**
(ProPluginsLab). Elle ne remplace pas le plugin hôte : elle s'y accroche, et ne fonctionne
pas sans lui.

- **Version** : 0.4.0
- **Prérequis** : WordPress 6.8+, PHP 7.4+, WooCommerce 9.9+ (testé jusqu'à 11.0),
  Back In Stock Notifier 7.0+ (relu sur 7.4.2)
- **Préfixe** : `ebisn_` (options, hooks) / `EBISN\` (namespace PHP)
- **Text domain** : `extender-for-back-in-stock-notifier` (doit rester identique au slug du dossier)
- **Licence** : GPL-3.0-or-later — alignée sur celle du plugin hôte, pour qu'adapter un de
  ses gabarits reste possible sans problème de compatibilité

## Arborescence

```
extender-for-back-in-stock-notifier/
├── extender-for-back-in-stock-notifier.php  Fichier principal : en-tête, constantes, hooks d'amorçage
├── readme.txt                               Métadonnées au format WordPress.org (lues pour la fiche de mise à jour)
├── uninstall.php                            Purge des données ebisn_* à la suppression (sur opt-in)
├── .gitattributes                           export-ignore : fichiers exclus des archives
├── .github/workflows/release.yml            Construit et publie l'archive sur push d'un tag v*
├── bin/build-plugin-zip.sh                  Construction reproductible de l'archive d'installation
├── lib/plugin-update-checker/               Bibliothèque tierce embarquée (Plugin Update Checker 5.7)
├── composer.json                            Autoload PSR-4 + outillage de dev (PHPCS, PHPStan)
├── phpcs.xml.dist                           Règles WordPress Coding Standards
├── phpstan.neon.dist                        Analyse statique avec stubs WordPress/WooCommerce
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── languages/                               Fichiers .pot / .po / .mo
└── src/
    ├── Autoloader.php                       Autoload PSR-4 sans Composer
    ├── functions.php                        Helpers globaux : ebisn(), ebisn_log()
    ├── Plugin.php                           Conteneur : amorçage + registre des modules
    ├── Updater.php                          Mises à jour depuis les releases GitHub
    ├── Requirements.php                     Vérification WooCommerce + plugin hôte
    ├── Installer.php                        Activation / désactivation / migrations
    ├── Integration/
    │   ├── BackInStockNotifier.php          Tout ce qu'on sait du plugin hôte
    │   └── Brevo.php                        Tout ce qu'on sait des extensions Brevo
    ├── Admin/
    │   ├── Admin.php                        Hooks admin, assets, lien « Réglages »
    │   ├── SettingsTab.php                  WooCommerce → Réglages → Extender BIS
    │   ├── StatsBanner.php                  Bandeau d'indicateurs sur la liste des inscrits
    │   ├── SizeMatrixPage.php               Écran « Demandes par taille »
    │   ├── SizeMatrixTable.php              WP_List_Table à colonnes dynamiques
    │   └── BrevoBackfillPage.php            Lancement et suivi du rattrapage Brevo
    ├── Conversion/                          Détection d'achat et statut « Purchased »
    │   ├── ConversionService.php            SEUL point d'écriture d'un statut d'inscription
    │   ├── OrderMatcher.php                 Règles d'appariement, sans accès base
    │   ├── SubscriptionRepository.php       Lectures d'inscriptions en SQL préparé
    │   ├── OrderLookup.php                  Recherche de commandes (HPOS et stockage historique)
    │   ├── RealtimeListener.php             Conversion au fil de l'eau et retours en arrière
    │   ├── Backfill.php                     Rattrapage automatique sur l'historique
    │   ├── NotificationSuppressor.php       Bloque l'alerte pour qui a déjà acheté
    │   ├── Stats.php                        Calcul des indicateurs, mis en cache
    │   └── MetaKeys.php                     Clés de métadonnées, actuelles et héritées
    ├── Brevo/                               Synchronisation vers Brevo
    │   ├── Client.php                       Transport HTTP
    │   ├── Response.php                     Verdicts : succès, réessayable, définitif, authentification
    │   ├── Contacts.php                     Contacts, imports, listes, attributs
    │   ├── Lists.php                        Listes du compte, mises en cache
    │   ├── ContactState.php                 Table de suivi, idempotence par empreinte
    │   ├── PayloadBuilder.php               Attributs agrégés sur toutes les attentes d'une adresse
    │   ├── AttributeInstaller.php           Déclaration des attributs chez Brevo
    │   ├── SyncService.php                  Synchro au fil de l'eau, avec report exponentiel
    │   ├── Backfill.php                     Rattrapage en masse, suivi des imports
    │   └── Consent.php                      Case de consentement et preuve horodatée
    ├── Matrix/                              Croisement des demandes par déclinaison
    │   ├── DemandMatrix.php                 Collecte agrégée, mise en cache
    │   ├── AttributeResolver.php            Choix de l'attribut d'axe et ordre des colonnes
    │   └── MatrixExporter.php               Export CSV, formules neutralisées
    ├── Unsubscribe/                         Désabonnement d'une alerte
    │   ├── UnsubscribeService.php           Délègue l'écriture à l'API de l'hôte
    │   ├── StatusRecorder.php               Mémorise le statut quitté, quel qu'en soit l'auteur
    │   ├── SubscriberLocator.php            Reconnaît le visiteur et ses inscriptions
    │   ├── VisitorCookie.php                Jeton opaque de navigateur
    │   ├── ProductForm.php                  Encart sur la fiche produit, point d'entrée AJAX
    │   └── EmailLink.php                    Lien signé des e-mails
    ├── Migration/
    │   └── LegacyConversionMeta.php         Reprise des métadonnées des snippets WPCode
    ├── Modules/
    │   ├── ModuleInterface.php              Contrat d'un module
    │   ├── AbstractModule.php               Base : activation pilotée par option
    │   ├── PurchaseConversion.php           Module « Purchased »
    │   ├── ConversionStats.php              Module « indicateurs »
    │   ├── SizeMatrix.php                   Module « Demandes par taille »
    │   ├── Unsubscribe.php                  Module « Désabonnement »
    │   └── BrevoSync.php                    Module « Brevo » (désactivé par défaut)
    └── Support/
        ├── Settings.php                     Lecture/écriture des options ebisn_*
        ├── Logger.php                       Journaux WooCommerce (source extender-bisn)
        ├── Scheduler.php                    Unique point de contact avec Action Scheduler
        ├── Lock.php                         Verrou atomique entre processus
        ├── JobState.php                     État persistant d'un traitement par lots
        ├── BatchJob.php                     Contrat d'un traitement par lots
        ├── BatchRunner.php                  Moteur par lots : budget, curseur, chaînage
        └── SnippetGuard.php                 Met un module en veille si son snippet tourne encore
```

## Modules

| Module | Identifiant | Actif par défaut | Rôle |
|---|---|---|---|
| Marquer « Purchased » | `purchase_conversion` | oui | Fait passer une inscription au statut `cwg_converted` quand son titulaire commande le produit attendu, au fil de l'eau et sur tout l'historique. Annule la conversion en cas de remboursement ou d'annulation. |
| Valeur des listes d'attente | `conversion_stats` | oui | Affiche au-dessus de la liste des inscrits la valeur en attente, le chiffre d'affaires récupéré et le taux de conversion. |
| Demandes par taille | `size_matrix` | oui | Écran croisant les demandes par produit et par déclinaison, avec recherche, tri, pagination et export CSV. L'attribut porté en colonnes et les statuts comptés sont réglables. |
| Désabonnement | `unsubscribe` | oui | Remplace le formulaire d'inscription par un bouton de désabonnement, et fournit un lien signé aux e-mails. Libellé et messages réglables. |
| Synchronisation Brevo | `brevo_sync` | **non** | Pousse les adresses inscrites vers une liste Brevo. Dépend de l'extension Brevo (`mailin`) ou d'une clé d'API saisie manuellement. |

### Dépendance optionnelle à Brevo

Brevo n'est **pas** déclarée dans l'en-tête `Requires Plugins` : cela rendrait l'extension
entière inactivable sans elle alors qu'un seul module la concerne. La détection se fait à
l'exécution, avec trois sources de clé d'API dans cet ordre : l'option de l'extension Brevo,
son nom en dur, puis une clé saisie dans les réglages.

Attention : seule l'extension **Brevo** (slug `mailin`) détient une clé d'API v3. Le
connecteur « Brevo for WooCommerce » n'en stocke plus depuis sa version 4 — un site n'ayant
que celui-ci doit saisir sa clé manuellement.

## Reprise depuis les snippets WPCode

Le plugin remplace six snippets. La bascule est conçue pour être transparente :

- **Mise en veille automatique.** Tant qu'un snippet remplacé est encore chargé, le module
  correspondant ne s'accroche à rien et le signale dans le panneau Diagnostic. Sans cela, les
  deux traiteraient les mêmes données en parallèle.
- **Migration des métadonnées.** Les clés `mh_bisn_order_id` et `mh_bisn_converted_on` sont
  reprises sous le préfixe `_ebisn_`, par lots, puis supprimées. Ces conversions héritées
  sont marquées comme telles : les snippets n'enregistraient pas le statut d'origine, elles
  ne peuvent donc pas être annulées.
- **Marqueur de commande.** `_mh_bisn_converted` n'est plus lu ni écrit — l'idempotence vient
  désormais d'une mise à jour conditionnelle du statut. Il est laissé en place, inerte : le
  purger sur l'ensemble des commandes coûterait davantage que le bénéfice, et sous HPOS il
  vit dans `wc_orders_meta`.
- **Rattrapage Brevo.** Si l'option `mh_brevo_backfill_job` du snippet est trouvée en base
  avec au moins un lot traité, le rattrapage est marqué comme déjà effectué et rien n'est
  renvoyé.
- **Demandes par taille.** Les constantes `MH_BISN_MX_STATUS`, `MH_BISN_MX_SIZE_ATTR` et
  `MH_BISN_MX_CACHE_TTL` sont reprises comme valeurs initiales des réglages si elles sont
  définies au moment de la migration. L'ancienne adresse `page=mh-bisn-matrice` redirige vers
  la nouvelle page, et le cache du snippet est purgé.

## Traitements de fond

Tous passent par Action Scheduler, sous le groupe `ebisn`, visibles dans
WooCommerce → État → Actions programmées.

| Hook | Rôle |
|---|---|
| `ebisn_convert_order` | Traite une commande hors du tunnel de paiement |
| `ebisn_purchase_backfill_step` | Une étape du rattrapage sur l'historique |
| `ebisn_legacy_conversion_meta_step` | Une étape de la migration des métadonnées |
| `ebisn_brevo_sync_contact` | Synchronise une adresse vers Brevo |
| `ebisn_brevo_backfill_step` | Une étape du rattrapage Brevo |
| `ebisn_brevo_backfill_poll` | Vérifie l'issue d'un import Brevo |

Les arguments passés sont toujours **scalaires** : `wp-cron.php` transmet les arguments
d'un événement sans les réindexer, et une clé de chaîne devient un argument nommé en PHP 8 —
donc une erreur fatale qui interrompt l'intégralité du run cron.
```

## La règle qui structure tout : `Integration\BackInStockNotifier`

Ce plugin s'accroche à du code qu'il ne maîtrise pas. **Aucune classe, hors
`src/Integration/BackInStockNotifier.php`, ne doit écrire en dur `cwginstock`,
`CWGINSTOCK_*` ou `CWG_Instock_Notifier`.** Slugs, constantes, types de contenu, noms
d'options : tout passe par cette classe. Le jour où l'hôte renomme quelque chose, un seul
fichier bouge — et le diagnostic prévient quand il change de version majeure.

## Ajouter un module

1. Créer `src/Modules/MonModule.php` :

```php
<?php

namespace EBISN\Modules;

defined( 'ABSPATH' ) || exit;

final class MonModule extends AbstractModule {

    protected $id    = 'mon_module';
    protected $title = 'Mon module';

    public function register(): void {
        add_action( 'cwginstock_after_insert_subscriber', array( $this, 'on_subscribe' ), 10, 2 );
    }

    public function on_subscribe( $subscriber_id, $data ): void {
        // …
    }
}
```

2. Le référencer dans `Plugin::get_module_classes()`.
3. Il apparaît alors dans WooCommerce → Réglages → Extender BIS → Modules, avec sa propre
   case d'activation (`ebisn_module_mon_module_enabled`).

## Surface d'extension du plugin hôte

Relevé sur l'archive **7.4.2** (`cwginstocknotifier.php`, dossier
`back-in-stock-notifier-for-woocommerce/`). À revérifier à chaque changement de version
majeure de l'hôte — c'est précisément ce que signale le panneau Diagnostic.

### Repères

| | |
|---|---|
| Classe principale | `CWG_Instock_Notifier` (singleton, **instancié à l'inclusion du fichier**, pas sur `plugins_loaded`) |
| Constantes | `CWGINSTOCK_VERSION`, `CWGINSTOCK_FILE`, `CWGINSTOCK_PLUGINDIR`, `CWGINSTOCK_PLUGINURL`, `CWGSTOCKPLUGINBASENAME`, `CWGINSTOCK_DIRNAME` |
| Types de contenu | `cwginstocknotifier` (abonnés), `cwginstock_arrival` (arrivages) |
| Options | `cwginstocksettings` (principale), `cwginstock_imail_settings`, `cwginstock_iagree_settings`, `cwginstock_backend_ui` |
| Text domain | `back-in-stock-notifier-for-woocommerce` |
| Shortcode | `[cwginstock_subscribe_form product_id=… variation_id=…]` |
| Emails WooCommerce | `woocommerce_cwg_bis_instock_settings`, `woocommerce_cwg_bis_subscription_settings` |
| Envois en masse | `WP_Background_Process` + Action Scheduler (`cwg_delete_subscribers`, `cwginstock_third_party`, `cwg_schedule_third_party_support`) |

> **Piège** : la classe `CWG_Instock_Notifier` est *déclarée* dès l'inclusion du fichier,
> mais le singleton n'est instancié — et les constantes définies — que si WooCommerce est
> actif. `class_exists()` ne prouve donc rien ; c'est `defined( 'CWGINSTOCK_VERSION' )` qui
> fait foi. C'est ce que teste `BackInStockNotifier::is_active()`.

### Gabarits

L'hôte cherche, dans l'ordre : `{thème}/back-in-stock-notifier-for-woocommerce/{gabarit}`,
puis `{thème}/{gabarit}`, puis les siens. Le filtre `cwginstock_locate_template`
(`$template, $template_name, $template_path, $default_path, $args`) permet de court-circuiter
la recherche depuis une extension — préférable à un fichier posé dans le thème.

Gabarits livrés : `default-form.php`, `emails/bis-instock.php`, `emails/bis-subscription.php`,
et leurs variantes `emails/plain/`.

### Hooks utiles

**Cycle de vie d'un abonné**

| Hook | Type | Arguments |
|---|---|---|
| `cwginstocknotifier_insert_subscriber` | filtre | `$do_insert`, `$post_data` — renvoyer `false` refuse l'inscription |
| `cwginstock_after_insert_subscriber` | action | `$subscriber_id`, `$post_data` |
| `cwginstock_subscriber_instock` | action | `$subscriber_id` — la notification part |
| `cwginstock_subscriber_updated` | action | |
| `cwginstocknotifier_double_optin` | action | |
| `cwginstocknotifier_insert_custom_meta_data` | filtre | métas additionnelles à l'inscription |

**Retour en stock et envoi**

| Hook | Type | Arguments |
|---|---|---|
| `cwg_before_process_instock_email` | filtre | `$process`, … (3 args) — la porte d'entrée pour conditionner un envoi |
| `cwginstock_trigger_status` | action | `$product_id`, `$stock_status`, `$product` (3 args) |
| `cwginstock_before_trigger_status` | action | |
| `cwginstock_stop_email` | filtre | `$stop`, `$subscriber_id`, `$product` |
| `cwginstock_auto_email_sent` / `cwginstock_manual_email_sent` | actions | |
| `cwginstock_notify_process` | action | |

**Contenu des e-mails**

`cwginstock_from_email`, `cwginstock_from_name`, `cwginstock_reply_to_email`,
`cwginstock_raw_subject` / `cwginstock_raw_message` (`$message, $subscriber_id`),
`cwgsubscribe_raw_subject` / `cwgsubscribe_raw_message`, `cwgimail_raw_subject` / `cwgimail_raw_message`.

**Formulaire côté boutique**

| Hook | Type | Arguments |
|---|---|---|
| `cwginstock_display_subscribe_form` | filtre | `$display`, `$product`, `$variation` — masquer le formulaire |
| `cwg_instock_before_input_fields` / `cwg_instock_after_input_fields` | actions | `$product_id`, `$variation_id` |
| `cwg_instock_before_heading` / `cwg_instock_after_heading` | actions | |
| `cwg_instock_after_email_field` | action | |
| `cwginstock_before_submit_button` / `cwginstock_after_submit_button` | actions | |
| `cwginstock_submit_btn_label`, `cwgstock_submit_attr`, `cwginstock_form_design`, `cwginstock_popup_design` | filtres | |
| `cwginstock_success_subscription_html` | filtre | `$html`, `$success`, `$post_data` |
| `cwginstock_localization_array` | filtre | données passées au JavaScript |
| `cwginstock_bypass_recaptcha` | filtre | 3 arguments |

**Administration**

`cwginstocknotifier_columns` / `cwginstock_custom_columns`, `cwginstocknotifier_bulk_actions` /
`cwg_instock_bulk_status_action`, `cwginstocknotifier_row_actions`, `cwginstock_screen_ids`,
`cwginstock_register_settings`, `cwginstocksettings_before_section`, `cwginstock_metaquery`.

Liste complète : `grep -rn "do_action( 'cwg\|apply_filters( 'cwg" ` dans le dossier du plugin hôte.

## Développement

```bash
composer install
composer run lint       # PHPCS / WordPress Coding Standards
composer run lint:fix   # PHPCBF
composer run analyse    # PHPStan niveau 6
```

Aucun stub du plugin hôte n'est fourni à PHPStan : le code n'accède à ses constantes que par
`constant()` sous garde `defined()`, et à aucune de ses classes. Dès qu'un module appellera
une classe de l'hôte, ajouter un dossier `stubs/` (déclaré dans `phpstan.neon.dist` et mis en
`export-ignore`) plutôt que de baisser le niveau d'analyse.

## Publier une version

1. Mettre à jour d'un seul coup les **trois** numéros de version : en-tête `Version:`,
   constante `EBISN_VERSION`, et `Stable tag` de `readme.txt`. Le script de build refuse de
   construire s'ils divergent.
2. Renseigner le changelog de `readme.txt`.
3. `git tag vX.Y.Z && git push origin vX.Y.Z` — le workflow construit l'archive et publie la
   release. Le vérificateur de mises à jour lit l'en-tête `Version:` du tag, jamais le nom du
   tag : un tag posé sur un fichier non mis à jour ne déclenche aucune mise à jour, sans erreur.

Construction locale : `bash bin/build-plugin-zip.sh` (nécessite un commit — `git archive`
ignore les modifications non commitées, volontairement).
