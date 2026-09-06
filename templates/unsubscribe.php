<?php
/**
 * Encart de désabonnement, rendu à la place du formulaire d'inscription.
 *
 * Ce gabarit se substitue à `default-form.php` de l'extension hôte, via le
 * filtre `cwginstock_locate_template`. Les variables disponibles sont celles
 * que l'hôte destinait à son propre formulaire : `$product_id` et
 * `$variation_id` sont les seules dont nous ayons besoin.
 *
 * Surchargeable depuis le thème, comme tout gabarit :
 * `{thème}/extender-for-back-in-stock-notifier/unsubscribe.php`
 *
 * @package ExtenderForBackInStockNotifier
 */

defined( 'ABSPATH' ) || exit;

\EBISN\Unsubscribe\ProductForm::render_box(
	isset( $product_id ) ? (int) $product_id : 0,
	isset( $variation_id ) ? (int) $variation_id : 0
);
