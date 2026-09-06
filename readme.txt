=== Extender for Back In Stock Notifier ===
Contributors: benoitbonavia
Tags: woocommerce, back in stock, waitlist, notification, rupture de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, back-in-stock-notifier-for-woocommerce
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Étend « Back In Stock Notifier for WooCommerce » : règles et automatismes supplémentaires, regroupés dans une extension unique.

== Description ==

Extender for Back In Stock Notifier complète l'extension
« Back In Stock Notifier for WooCommerce | WooCommerce Waitlist Pro » (ProPluginsLab)
plutôt que de la remplacer. Elle ne fonctionne pas sans elle.

Chaque règle ajoutée devient un « module » autonome, activable individuellement depuis
WooCommerce → Réglages → Extender BIS → Modules.

L'extension déclare sa compatibilité avec le stockage haute performance des commandes (HPOS)
et avec les blocs Panier et Commande.

Les mises à jour sont distribuées depuis le dépôt GitHub du projet et apparaissent
directement dans l'écran Extensions de WordPress.

== Installation ==

1. Installer et activer « Back In Stock Notifier for WooCommerce » ainsi que WooCommerce.
2. Téléverser l'archive depuis Extensions → Ajouter → Téléverser une extension.
3. Activer l'extension. WordPress refuse l'activation tant que les deux dépendances
   ne sont pas actives.
4. Configurer depuis WooCommerce → Réglages → Extender BIS.

== Frequently Asked Questions ==

= Que se passe-t-il si je désactive Back In Stock Notifier ? =

L'extension se met en veille et affiche un avertissement dans l'administration.
Aucune donnée n'est modifiée ni supprimée.

= Cette extension touche-t-elle aux abonnés déjà enregistrés ? =

Non. Les abonnés, les arrivages et les réglages appartiennent à l'extension hôte.
Sa désinstallation ne les efface pas non plus.

= Le plugin nécessite-t-il un jeton GitHub ? =

Non. Le dépôt est public : les mises à jour fonctionnent sans configuration.
Définir la constante `EBISN_GITHUB_TOKEN` dans wp-config.php reste possible pour relever
la limite de l'API GitHub (60 requêtes par heure et par adresse IP sans jeton).

= Comment forcer une vérification des mises à jour ? =

Depuis l'écran Extensions, le lien « Check for updates » sous la ligne du plugin.
La vérification automatique a lieu au plus toutes les 12 heures.

== Changelog ==

= 0.1.0 =
* Version initiale : structure du plugin, registre de modules, onglet de réglages WooCommerce.
* Dépendance déclarée à WooCommerce et à « Back In Stock Notifier for WooCommerce » via
  l'en-tête `Requires Plugins`, doublée d'un contrôle des versions à l'exécution.
* Couche d'intégration isolant tout le code du plugin hôte en un seul fichier.
* Panneau de diagnostic : version de l'extension hôte, seuil minimal exigé, volumétrie
  des abonnés, alerte en cas de changement de version majeure de l'hôte.
* Déclaration de compatibilité HPOS et blocs Panier/Commande.
* Mises à jour automatiques depuis GitHub.

== Upgrade Notice ==

= 0.1.0 =
Première version. Aucune fonctionnalité : uniquement le socle de l'extension.
