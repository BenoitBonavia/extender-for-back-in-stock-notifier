<?php
/**
 * Constantes du plugin, déclarées pour l'analyse statique uniquement.
 *
 * Ce fichier n'est JAMAIS chargé à l'exécution : il est seulement listé dans
 * `scanFiles` de phpstan.neon.dist, et exclu des archives par .gitattributes.
 *
 * Raison d'être : PHPStan n'enregistre une constante déclarée par `define()`
 * que s'il peut évaluer sa valeur statiquement. `EBISN_VERSION` (littéral) et
 * `EBISN_FILE` (__FILE__) passent donc, mais pas `EBISN_PATH`, `EBISN_URL` ni
 * `EBISN_BASENAME`, dont la valeur vient d'un appel de fonction WordPress.
 * Les valeurs ci-dessous ne servent qu'à fixer un TYPE ; elles n'ont aucune
 * signification.
 *
 * @package ExtenderForBackInStockNotifier
 */

define( 'EBISN_VERSION', '0.0.0' );
define( 'EBISN_FILE', '' );
define( 'EBISN_PATH', '' );
define( 'EBISN_URL', '' );
define( 'EBISN_BASENAME', '' );
define( 'EBISN_MIN_WC_VERSION', '0.0' );
