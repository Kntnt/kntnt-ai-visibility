<?php
/**
 * The Link-headers feature module.
 *
 * Release 3's feature: it emits RFC 8288 Link headers advertising every
 * registered artifact on HTML responses. boot() builds the Header_Emitter over
 * the Core registry and registers its send_headers hook. It registers no
 * provider, serve pattern, capability column or settings section — it only reads
 * the registry (docs/adr/0006).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Links;

use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module as Module_Contract;

/**
 * Boots and wires the Link-headers module.
 *
 * @since 0.4.0
 */
final class Module implements Module_Contract {

	/**
	 * Builds the header emitter and registers it against Core.
	 *
	 * @since 0.4.0
	 *
	 * @param Core $core The Core service facade.
	 * @return void
	 */
	public function boot( Core $core ): void {
		( new Header_Emitter( $core->artifacts() ) )->register();
	}

}
