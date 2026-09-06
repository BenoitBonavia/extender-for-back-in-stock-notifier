<?php
/**
 * Contrat d'un traitement par lots.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Un travail découpable en lots, exécuté en arrière-plan par BatchRunner.
 *
 * L'implémentation ne se préoccupe ni du verrou, ni du budget, ni du
 * chaînage : elle traite le lot qu'on lui demande et rend la main.
 */
interface BatchJob {

	/**
	 * Identifiant machine du travail, en snake_case.
	 *
	 * Sert à nommer l'option d'état et le verrou.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Hook Action Scheduler qui déclenche une étape.
	 *
	 * @return string
	 */
	public function get_hook(): string;

	/**
	 * Nombre d'éléments traités par lot.
	 *
	 * @return int
	 */
	public function get_batch_size(): int;

	/**
	 * Traite un lot à partir du curseur.
	 *
	 * L'implémentation doit être idempotente : un lot peut être rejoué à
	 * l'identique après une interruption brutale.
	 *
	 * @param int $cursor Position atteinte lors de l'étape précédente.
	 * @param int $limit  Nombre maximal d'éléments à examiner.
	 *
	 * @return array{cursor:int, processed:int, affected:int, done:bool}
	 */
	public function process( int $cursor, int $limit ): array;
}
