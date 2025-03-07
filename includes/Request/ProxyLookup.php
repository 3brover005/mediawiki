<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Request;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use Wikimedia\IPSet;

/**
 * @since 1.28
 */
class ProxyLookup {

	/** @var string[] */
	private $proxyServers;

	/** @var string[] */
	private $proxyServersComplex;

	/** @var IPSet|null */
	private $proxyIPSet;

	/** @var HookRunner */
	private $hookRunner;

	/**
	 * @param string[] $proxyServers Simple list of IPs
	 * @param string[] $proxyServersComplex Complex list of IPs/ranges
	 * @param HookContainer $hookContainer
	 */
	public function __construct(
		$proxyServers,
		$proxyServersComplex,
		HookContainer $hookContainer
	) {
		$this->proxyServers = $proxyServers;
		$this->proxyServersComplex = $proxyServersComplex;
		$this->hookRunner = new HookRunner( $hookContainer );
	}

	/**
	 * Checks if an IP matches a proxy we've configured
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function isConfiguredProxy( $ip ) {
		// Quick check of known singular proxy servers
		if ( in_array( $ip, $this->proxyServers, true ) ) {
			return true;
		}

		// Check against addresses and CIDR nets in the complex list
		if ( !$this->proxyIPSet ) {
			$this->proxyIPSet = new IPSet( $this->proxyServersComplex );
		}
		return $this->proxyIPSet->match( $ip );
	}

	/**
	 * Checks if an IP is a trusted proxy provider.
	 * Useful to tell if X-Forwarded-For data is possibly bogus.
	 * CDN cache servers for the site are allowed.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function isTrustedProxy( $ip ) {
		$trusted = $this->isConfiguredProxy( $ip );
		$this->hookRunner->onIsTrustedProxy( $ip, $trusted );
		return $trusted;
	}
}

class_alias( ProxyLookup::class, 'ProxyLookup' );
