=== Extender for Back In Stock Notifier ===
Contributors: benoitbonavia
Tags: woocommerce, back in stock, waitlist, notification, rupture de stock
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, back-in-stock-notifier-for-woocommerce
Stable tag: 0.4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Étend « Back In Stock Notifier for WooCommerce » : règles et automatismes supplémentaires, regroupés dans une extension unique.

== Description ==

Extender for Back In Stock Notifier complète l'extension
« Back In Stock Notifier for WooCommerce | WooCommerce Waitlist Pro » (ProPluginsLab)
plutôt que de la remplacer. Elle ne fonctionne pas sans elle.

Chaque règle ajoutée devient un « module » autonome, activable individuellement depuis
Instock Notifier → Réglages Extender → Modules.

Modules disponibles :

* **Marquer « Purchased » les inscrits qui ont commandé.** L'extension hôte déclare ce
  statut mais ne le pose jamais dans sa version gratuite. Ce module fournit le déclencheur
  manquant : au fil de l'eau à chaque commande, et sur tout l'historique au premier
  démarrage. Une conversion est annulée si la commande est remboursée ou annulée, et
  l'alerte de retour en stock n'est plus envoyée à quelqu'un qui vient d'acheter.
* **Valeur des listes d'attente.** Un bandeau au-dessus de la liste des inscrits : valeur en
  attente de réassort, valeur non récupérée, chiffre d'affaires récupéré selon deux
  attributions, et taux de conversion calculé sur les seuls inscrits notifiés.
* **Demandes par taille.** Un écran croisant les demandes de retour en stock par produit et
  par déclinaison : de quelles tailles avez-vous besoin, et en quelle quantité ? Recherche,
  tri, pagination et export CSV. L'attribut porté en colonnes est détecté automatiquement,
  et reste modifiable — la même page peut aussi bien répondre par couleur ou par matière.
* **Désabonnement.** L'extension hôte enregistre le statut « Unsubscribed » et sait le poser
  depuis son administration, mais n'offre au client aucun moyen de s'en servir. Ce module
  remplace le formulaire d'inscription par un bouton de désabonnement, et fournit un lien
  signé aux gabarits d'e-mail — seul recours fiable pour une personne sans compte. Le libellé
  du bouton et les messages sont réglables.
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
4. Configurer depuis Instock Notifier → Réglages Extender.

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

= 0.4.3 =
* Une note de diagnostic apparaît sous le formulaire d'inscription, visible des seules
  personnes pouvant gérer la boutique : elle indique si le visiteur est reconnu, s'il a un
  cookie, et combien de demandes existent sur le produit affiché. Désactivable.

= 0.4.2 =
* Le bouton de désabonnement apparaît désormais aussi pour un client connecté dont
  l'inscription avait été faite en tant qu'invité : l'adresse du compte, vérifiée par
  WordPress, sert à la retrouver.
* Suivre le lien de désabonnement reçu par e-mail rattache le navigateur aux autres
  demandes de la même adresse, qui deviennent gérables depuis la fiche produit.
* Le panneau Diagnostic indique pour combien d'inscriptions le bouton peut s'afficher, et
  rappelle que les inscriptions d'invités antérieures au module ne sont joignables que par
  le lien e-mail.

= 0.4.1 =
* Les réglages ne sont plus un onglet de WooCommerce : la page vit désormais sous le menu
  Instock Notifier, avec les autres écrans de l'extension. L'ancienne adresse redirige.
* Les statuts de commande déclenchant une conversion se choisissent dans une liste plutôt
  que de se saisir séparés par des virgules. Une valeur enregistrée au format précédent
  reste comprise.
* La section Brevo signale désormais que ses réglages — dont la case de consentement du
  formulaire — restent sans effet tant que le module est inactif.

= 0.4.0 =
* Nouveau module « Désabonnement » : le formulaire d'inscription est remplacé par un bouton
  pour qui est déjà inscrit, et un lien signé est mis à disposition des gabarits d'e-mail.
* Le libellé du bouton et les deux messages affichés sont réglables.
* Désabonnement immédiat et annulable en un clic ; une demande de confirmation peut être
  activée dans les réglages.
* Le désabonnement porte sur la seule déclinaison affichée : se retirer de la taille 38 ne
  touche pas la demande sur la taille 40.
* Les visiteurs non connectés sont reconnus par un cookie ne contenant qu'un identifiant
  aléatoire, jamais leur adresse e-mail.
* Le statut quitté est mémorisé même lorsque le désabonnement vient de l'administration de
  l'extension hôte ou de sa récupération de file : le rétablissement fonctionne dans tous
  les cas.
* Plus aucune alerte n'est envoyée à une personne désabonnée, même si son envoi était déjà
  en file.
* Le jeton {cwginstock_unsubscribe} est résolu : les gabarits écrits pour l'extension
  payante « Unsubscribe » fonctionnent sans modification.

= 0.3.2 =
* Une entrée « Réglages Extender » apparaît dans le menu Instock Notifier, à côté des écrans
  de l'extension. Les réglages restent un onglet de WooCommerce, mais on y accède désormais
  depuis le menu où l'on travaille.

= 0.3.1 =
* La répartition par déclinaison revient dans le pied du tableau, chaque barre
  sous sa propre colonne, au lieu d'un encart séparé où il fallait relire les
  libellés pour s'y retrouver.
* Ces totaux suivent désormais la recherche et le filtre en cours, tout en
  restant calculés sur l'ensemble des pages.

= 0.3.0 =
* Nouveau module « Demandes par taille » : écran croisant les demandes de retour en stock
  par produit et par déclinaison, avec recherche, tri, pagination, export CSV et carte de
  chaleur.
* L'attribut porté en colonnes est réglable : la page peut croiser par couleur, matière ou
  tout autre attribut, pas seulement par taille.
* Les statuts comptés sont réglables : au-delà des seules demandes en attente, il est
  possible de lire la demande totale.
* Les tailles textuelles suivent enfin l'ordre défini dans WooCommerce : XS, S, M, L, XL,
  et non l'ordre alphabétique.
* Cliquer une cellule ne liste plus que les demandes de cette déclinaison, y compris quand
  plusieurs variations la partagent.
* Un produit dont une variation a été supprimée conserve son nom.
* L'export CSV ne peut plus injecter de formule dans un tableur.

= 0.2.1 =
* Le taux de conversion occupe désormais toute la largeur du bandeau : la jauge, plus haute,
  se lit d'un coup d'œil. Libellé et valeur sont alignés de part et d'autre.
* Une jauge à taux très faible reste visible au lieu de disparaître.
* Correction : sous une locale à virgule décimale et en PHP 7.4, la largeur de la jauge
  pouvait être écrite « 12,3 % » — une valeur que le navigateur ignore.

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

= 0.4.3 =
Ajoute une aide au diagnostic du bouton de désabonnement.

= 0.4.2 =
Améliore la reconnaissance des inscrits pour le bouton de désabonnement.

= 0.4.1 =
Les réglages passent sous le menu Instock Notifier. Aucune donnée n'est modifiée.

= 0.4.0 =
Ajoute le désabonnement côté client. Pensez à insérer {unsubscribe_url} dans vos gabarits
d'e-mail : c'est le seul moyen de se désabonner pour une personne sans compte.

= 0.3.2 =
Ajoute un accès aux réglages depuis le menu Instock Notifier. Aucune donnée n'est modifiée.

= 0.3.1 =
Ajustement d'affichage de l'écran « Demandes par taille ». Aucune donnée n'est modifiée.

= 0.3.0 =
Ajoute l'écran « Demandes par taille ». Si vous utilisiez le snippet correspondant,
désactivez-le : le module reste en veille tant qu'il est chargé, et sa configuration est
reprise automatiquement.

= 0.2.1 =
Ajustement d'affichage du bandeau d'indicateurs. Aucune donnée n'est modifiée.

= 0.2.0 =
Au premier chargement, l'extension rejoue la détection d'achat sur l'historique des commandes
et reprend les données de vos anciens snippets. Désactivez ces snippets : tant qu'ils sont
chargés, les modules correspondants restent en veille.

= 0.1.0 =
Première version. Aucune fonctionnalité : uniquement le socle de l'extension.
