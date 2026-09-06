=== Extender for Back In Stock Notifier ===
Contributors: benoitbonavia
Tags: woocommerce, back in stock, waitlist, notification, rupture de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, back-in-stock-notifier-for-woocommerce
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Étend « Back In Stock Notifier for WooCommerce » : règles et automatismes supplémentaires, regroupés dans une extension unique.

== Description ==

Extender for Back In Stock Notifier complète l'extension
« Back In Stock Notifier for WooCommerce | WooCommerce Waitlist Pro » (ProPluginsLab)
plutôt que de la remplacer. Elle ne fonctionne pas sans elle.

Chaque règle ajoutée devient un « module » autonome, activable individuellement depuis
WooCommerce → Réglages → Extender BIS → Modules.

Modules disponibles :

* **Marquer « Purchased » les inscrits qui ont commandé.** L'extension hôte déclare ce
  statut mais ne le pose jamais dans sa version gratuite. Ce module fournit le déclencheur
  manquant : au fil de l'eau à chaque commande, et sur tout l'historique au premier
  démarrage. Une conversion est annulée si la commande est remboursée ou annulée, et
  l'alerte de retour en stock n'est plus envoyée à quelqu'un qui vient d'acheter.
* **Valeur des listes d'attente.** Un bandeau au-dessus de la liste des inscrits : valeur en
  attente de réassort, valeur non récupérée, chiffre d'affaires récupéré selon deux
  attributions, et taux de conversion calculé sur les seuls inscrits notifiés.
* **Synchronisation Brevo** (désactivé par défaut). Pousse les adresses inscrites vers une
  liste Brevo, au fil de l'eau et en rattrapage. Les attributs décrivent l'ensemble des
  produits qu'une personne attend, et sont recalculés à chaque envoi.

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

Oui, et il faut le savoir. Le module « Marquer Purchased » modifie le statut des
inscriptions dont le titulaire a commandé le produit attendu, et leur attache ses propres
métadonnées. C'est précisément sa raison d'être. En revanche, aucune inscription n'est
jamais supprimée, et le statut d'origine est conservé pour chaque conversion : elle reste
donc annulable.

Les arrivages et les réglages de l'extension hôte, eux, ne sont pas touchés.

= Que se passe-t-il si je supprime cette extension ? =

Par défaut, rien n'est effacé : ni ses réglages, ni les conversions déjà effectuées. Deux
options de la section Général permettent de demander la suppression de ses données, et le
rétablissement des statuts d'origine.

= La suppression automatique de Back In Stock Notifier pose-t-elle problème ? =

Oui, si vous l'activez. Elle efface définitivement les inscriptions « Mail Sent »,
« Unsubscribed » et « Purchased » passé un délai — donc les conversions et les statistiques
de chiffre d'affaires récupéré. Le panneau de diagnostic vous avertit lorsqu'elle est
active.

= Le plugin nécessite-t-il un jeton GitHub ? =

Non. Le dépôt est public : les mises à jour fonctionnent sans configuration.
Définir la constante `EBISN_GITHUB_TOKEN` dans wp-config.php reste possible pour relever
la limite de l'API GitHub (60 requêtes par heure et par adresse IP sans jeton).

= Comment forcer une vérification des mises à jour ? =

Depuis l'écran Extensions, le lien « Check for updates » sous la ligne du plugin.
La vérification automatique a lieu au plus toutes les 12 heures.

== Changelog ==

= 0.2.0 =
* Nouveau module « Marquer Purchased » : conversion au fil de l'eau et rattrapage automatique
  sur l'historique, avec mémorisation du statut d'origine et annulation en cas de
  remboursement ou d'annulation de commande.
* L'alerte de retour en stock n'est plus envoyée à un inscrit ayant déjà acheté le produit.
* Nouveau module « Valeur des listes d'attente » : bandeau d'indicateurs au-dessus de la
  liste des inscrits.
* Nouveau module « Synchronisation Brevo », désactivé par défaut : envoi au fil de l'eau et
  rattrapage en masse, avec suivi des imports asynchrones et case de consentement optionnelle.
* Reprise transparente des données des snippets WPCode : mise en veille des modules tant
  qu'un snippet est chargé, puis migration des métadonnées.
* Le compteur d'inscrits en attente de l'extension hôte est recalculé après chaque
  conversion, pour qu'il cesse de dériver.

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

= 0.2.0 =
Au premier chargement, l'extension rejoue la détection d'achat sur l'historique des commandes
et reprend les données de vos anciens snippets. Désactivez ces snippets : tant qu'ils sont
chargés, les modules correspondants restent en veille.

= 0.1.0 =
Première version. Aucune fonctionnalité : uniquement le socle de l'extension.
