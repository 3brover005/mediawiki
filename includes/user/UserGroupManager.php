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

namespace MediaWiki\User;

use Autopromote;
use ConfiguredReadOnlyMode;
use DBAccessObjectUtils;
use DeferredUpdates;
use IDBAccessObject;
use InvalidArgumentException;
use JobQueueGroup;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use ReadOnlyMode;
use User;
use UserGroupExpiryJob;
use UserGroupMembership;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Managers user groups.
 * @since 1.35
 */
class UserGroupManager implements IDBAccessObject {

	public const CONSTRUCTOR_OPTIONS = [
		'ImplicitGroups',
		'GroupPermissions',
		'RevokePermissions',
	];

	/** @var ServiceOptions */
	private $options;

	/** @var ILBFactory */
	private $loadBalancerFactory;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var HookContainer */
	private $hookContainer;

	/** @var HookRunner */
	private $hookRunner;

	/** @var ReadOnlyMode */
	private $readOnlyMode;

	/** @var callable[] */
	private $clearCacheCallbacks;

	/** @var string|false */
	private $dbDomain;

	/**
	 * @var array Service caches, an assoc. array keyed after the user-keys generated
	 * by the getCacheKey method and storing values in the following format:
	 *
	 * userKey => [
	 *   'implicit' => implicit groups cache
	 *   'effective' => effective groups cache
	 *   'membership' => [ ] // Array of UserGroupMembership objects
	 *   'former' => former groups cache
	 * ]
	 */
	private $userGroupCache = [];

	/**
	 * @param ServiceOptions $options
	 * @param ConfiguredReadOnlyMode $configuredReadOnlyMode
	 * @param ILBFactory $loadBalancerFactory
	 * @param HookContainer $hookContainer
	 * @param callable[] $clearCacheCallbacks
	 * @param string|bool $dbDomain
	 */
	public function __construct(
		ServiceOptions $options,
		ConfiguredReadOnlyMode $configuredReadOnlyMode,
		ILBFactory $loadBalancerFactory,
		HookContainer $hookContainer,
		array $clearCacheCallbacks = [],
		$dbDomain = false
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->loadBalancerFactory = $loadBalancerFactory;
		$this->loadBalancer = $loadBalancerFactory->getMainLB( $dbDomain );
		$this->hookContainer = $hookContainer;
		$this->hookRunner = new HookRunner( $hookContainer );
		// Can't just inject ROM since we LB can be for foreign wiki
		$this->readOnlyMode = new ReadOnlyMode( $configuredReadOnlyMode, $this->loadBalancer );
		$this->clearCacheCallbacks = $clearCacheCallbacks;
		$this->dbDomain = $dbDomain;
	}

	/**
	 * Return the set of defined explicit groups.
	 * The implicit groups (by default *, 'user' and 'autoconfirmed')
	 * are not included, as they are defined automatically, not in the database.
	 * @return string[] Array of internal group names
	 */
	public function listAllGroups() : array {
		return array_values( array_diff(
			array_merge(
				array_keys( $this->options->get( 'GroupPermissions' ) ),
				array_keys( $this->options->get( 'RevokePermissions' ) )
			),
			$this->listAllImplicitGroups()
		) );
	}

	/**
	 * Get a list of all configured implicit groups
	 * @return string[]
	 */
	public function listAllImplicitGroups() : array {
		return $this->options->get( 'ImplicitGroups' );
	}

	/**
	 * Creates a new UserGroupMembership instance from $row.
	 * The fields required to build an instance could be
	 * found using getQueryInfo() method.
	 *
	 * @param \stdClass $row A database result object
	 *
	 * @return UserGroupMembership
	 */
	public function newGroupMembershipFromRow( \stdClass $row ) : UserGroupMembership {
		return new UserGroupMembership(
			(int)$row->ug_user,
			$row->ug_group,
			$row->ug_expiry === null ? null : wfTimestamp(
				TS_MW,
				$row->ug_expiry
			)
		);
	}

	/**
	 * Load the user groups cache from the provided user groups data
	 * @internal for use by the User object only
	 * @param UserIdentity $user
	 * @param array $userGroups an array of database query results
	 */
	public function loadGroupMembershipsFromArray(
		UserIdentity $user,
		array $userGroups
	) {
		$userKey = $this->getCacheKey( $user );

		$this->userGroupCache[$userKey]['membership'] = [];
		reset( $userGroups );
		foreach ( $userGroups as $row ) {
			$ugm = $this->newGroupMembershipFromRow( $row );
			$this->userGroupCache[ $userKey ]['membership'][ $ugm->getGroup() ] = $ugm;
		}
	}

	/**
	 * Get the list of implicit group memberships this user has.
	 *
	 * This includes 'user' if logged in, '*' for all accounts,
	 * and autopromoted groups
	 *
	 * @param UserIdentity $user
	 * @param bool $recache Whether to avoid the cache
	 * @return string[] internal group names
	 */
	public function getUserImplicitGroups( UserIdentity $user, bool $recache = false ) : array {
		$userKey = $this->getCacheKey( $user );
		if ( $recache || !isset( $this->userGroupCache[$userKey]['implicit'] ) ) {
			$groups = [ '*' ];
			if ( $user->isRegistered() ) {
				$groups[] = 'user';

				$groups = array_unique( array_merge(
					$groups,
					// XXX: the User is necessary to pass it to the `GetAutoPromoteGroups` hook
					// within the `getAutopromoteGroups` method
					Autopromote::getAutopromoteGroups( User::newFromIdentity( $user ) )
				) );
			}
			$this->userGroupCache[$userKey]['implicit'] = $groups;
			if ( $recache ) {
				// Assure data consistency with rights/groups,
				// as getEffectiveGroups() depends on this function
				unset( $this->userGroupCache[$userKey]['effective'] );
			}
		}
		return $this->userGroupCache[$userKey]['implicit'];
	}

	/**
	 * Get the list of implicit group memberships the user has.
	 *
	 * This includes all explicit groups, plus 'user' if logged in,
	 * '*' for all accounts, and autopromoted groups
	 *
	 * @param UserIdentity $user
	 * @param int $queryFlags
	 * @param bool $recache Whether to avoid the cache
	 * @return string[] Array of String internal group names
	 */
	public function getUserEffectiveGroups(
		UserIdentity $user,
		int $queryFlags = self::READ_NORMAL,
		bool $recache = false
	) : array {
		$userKey = $this->getCacheKey( $user );
		// Ignore cache is the $recache flag is set, query flags = READ_NORMAL
		// or the cache value is missing
		if ( $recache
			|| $queryFlags !== self::READ_NORMAL
			|| !isset( $this->userGroupCache[$userKey]['effective'] )
		) {
			$groups = array_unique( array_merge(
				$this->getUserGroups( $user, $queryFlags ), // explicit groups
				$this->getUserImplicitGroups( $user, $recache ) // implicit groups
			) );
			// TODO: Deprecate passing out user object in the hook by introducing
			// an alternative hook
			if ( $this->hookContainer->isRegistered( 'UserEffectiveGroups' ) ) {
				$userObj = User::newFromIdentity( $user );
				$userObj->load();
				// Hook for additional groups
				$this->hookRunner->onUserEffectiveGroups( $userObj, $groups );
			}
			// Force reindexation of groups when a hook has unset one of them
			$this->userGroupCache[$userKey]['effective'] = array_values( array_unique( $groups ) );
		}
		return $this->userGroupCache[$userKey]['effective'];
	}

	/**
	 * Returns the groups the user has belonged to.
	 *
	 * The user may still belong to the returned groups. Compare with getGroups().
	 *
	 * The function will not return groups the user had belonged to before MW 1.17
	 *
	 * @param UserIdentity $user
	 * @param int $queryFlags
	 * @return array Names of the groups the user has belonged to.
	 */
	public function getUserFormerGroups(
		UserIdentity $user,
		int $queryFlags = self::READ_NORMAL
	) : array {
		$userKey = $this->getCacheKey( $user );

		if ( isset( $this->userGroupCache[$userKey]['former'] ) ) {
			return $this->userGroupCache[$userKey]['former'];
		}

		$db = $this->getDBConnectionRefForQueryFlags( $queryFlags );
		$res = $db->select(
			'user_former_groups',
			[ 'ufg_group' ],
			[ 'ufg_user' => $user->getId() ],
			__METHOD__
		);
		$this->userGroupCache[$userKey]['former'] = [];
		foreach ( $res as $row ) {
			$this->userGroupCache[$userKey]['former'][] = $row->ufg_group;
		}

		return $this->userGroupCache[$userKey]['former'];
	}

	/**
	 * Get the list of explicit group memberships this user has.
	 * The implicit * and user groups are not included.
	 *
	 * @param UserIdentity $user
	 * @param int $queryFlags
	 * @return string[]
	 */
	public function getUserGroups(
		UserIdentity $user,
		int $queryFlags = self::READ_NORMAL
	) : array {
		return array_keys( $this->getUserGroupMemberships( $user, $queryFlags ) );
	}

	/**
	 * Loads and returns UserGroupMembership objects for all the groups a user currently
	 * belongs to.
	 *
	 * @param UserIdentity $user the user to search for
	 * @param int $queryFlags
	 * @return UserGroupMembership[] Associative array of (group name => UserGroupMembership object)
	 */
	public function getUserGroupMemberships(
		UserIdentity $user,
		int $queryFlags = self::READ_NORMAL
	) : array {
		$userKey = $this->getCacheKey( $user );

		// Return cached value (if any) only if the query flags are for READ_NORMAL
		// otherwise - ignore cache
		if ( $queryFlags === self::READ_NORMAL
			&& isset( $this->userGroupCache[$userKey]['membership'] )
		) {
			/** @suppress PhanTypeMismatchReturn */
			return $this->userGroupCache[$userKey]['membership'];
		}

		$db = $this->getDBConnectionRefForQueryFlags( $queryFlags );
		$queryInfo = $this->getQueryInfo();
		$res = $db->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			[ 'ug_user' => $user->getId() ],
			__METHOD__,
			[],
			$queryInfo['joins']
		);

		$ugms = [];
		foreach ( $res as $row ) {
			$ugm = $this->newGroupMembershipFromRow( $row );
			if ( !$ugm->isExpired() ) {
				$ugms[$ugm->getGroup()] = $ugm;
			}
		}
		ksort( $ugms );

		$this->userGroupCache[$userKey]['membership'] = $ugms;
		return $ugms;
	}

	/**
	 * Add the user to the given group. This takes immediate effect.
	 * If the user is already in the group, the expiry time will be updated to the new
	 * expiry time. (If $expiry is omitted or null, the membership will be altered to
	 * never expire.)
	 *
	 * @param UserIdentity $user
	 * @param string $group Name of the group to add
	 * @param string|null $expiry Optional expiry timestamp in any format acceptable to
	 *   wfTimestamp(), or null if the group assignment should not expire
	 * @param bool $allowUpdate Whether to perform "upsert" instead of INSERT
	 *
	 * @throws InvalidArgumentException
	 * @return bool
	 */
	public function addUserToGroup(
		UserIdentity $user,
		string $group,
		string $expiry = null,
		bool $allowUpdate = false
	) : bool {
		if ( $this->readOnlyMode->isReadOnly() ) {
			return false;
		}

		if ( !$user->isRegistered() ) {
			throw new InvalidArgumentException(
				'UserGroupManager::addUserToGroup() needs a positive user ID. ' .
				'Perhaps addGroup() was called before the user was added to the database.'
			);
		}

		if ( $expiry ) {
			$expiry = wfTimestamp( TS_MW, $expiry );
		}

		// TODO: Deprecate passing out user object in the hook by introducing
		// an alternative hook
		if ( $this->hookContainer->isRegistered( 'UserAddGroup' ) ) {
			$userObj = User::newFromIdentity( $user );
			$userObj->load();
			if ( !$this->hookRunner->onUserAddGroup( $userObj, $group, $expiry ) ) {
				return false;
			}
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER, [], $this->dbDomain );

		$dbw->startAtomic( __METHOD__ );
		$dbw->insert(
			'user_groups',
			[
				'ug_user' => $user->getId(),
				'ug_group' => $group,
				'ug_expiry' => $expiry ? $dbw->timestamp( $expiry ) : null,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$affected = $dbw->affectedRows();
		if ( !$affected ) {
			// Conflicting row already exists; it should be overridden if it is either expired
			// or if $allowUpdate is true and the current row is different than the loaded row.
			$conds = [
				'ug_user' => $user->getId(),
				'ug_group' => $group
			];
			if ( $allowUpdate ) {
				// Update the current row if its expiry does not match that of the loaded row
				$conds[] = $expiry
					? "ug_expiry IS NULL OR ug_expiry != {$dbw->addQuotes( $dbw->timestamp( $expiry ) )}"
					: 'ug_expiry IS NOT NULL';
			} else {
				// Update the current row if it is expired
				$conds[] = "ug_expiry < {$dbw->addQuotes( $dbw->timestamp() )}";
			}
			$dbw->update(
				'user_groups',
				[ 'ug_expiry' => $expiry ? $dbw->timestamp( $expiry ) : null ],
				$conds,
				__METHOD__
			);
			$affected = $dbw->affectedRows();
		}
		$dbw->endAtomic( __METHOD__ );

		// Purge old, expired memberships from the DB
		$fname = __METHOD__;
		DeferredUpdates::addCallableUpdate( function () use ( $fname ) {
			$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
			$hasExpiredRow = $dbr->selectField(
				'user_groups',
				'1',
				[ "ug_expiry < {$dbr->addQuotes( $dbr->timestamp() )}" ],
				$fname
			);
			if ( $hasExpiredRow ) {
				JobQueueGroup::singleton( $this->dbDomain )->push( new UserGroupExpiryJob( [] ) );
			}
		} );

		if ( $affected > 0 ) {
			// TODO: optimization: we can avoid re-querying groups if we update caches in place
			$this->clearCache( $user );
			foreach ( $this->clearCacheCallbacks as $callback ) {
				$callback( $user );
			}
			return true;
		}
		return false;
	}

	/**
	 * Remove the user from the given group. This takes immediate effect.
	 *
	 * @param UserIdentity $user
	 * @param string $group Name of the group to remove
	 * @return bool
	 */
	public function removeUserFromGroup( UserIdentity $user, string $group ) : bool {
		// TODO: Deprecate passing out user object in the hook by introducing
		// an alternative hook
		if ( $this->hookContainer->isRegistered( 'UserRemoveGroup' ) ) {
			$userObj = User::newFromIdentity( $user );
			$userObj->load();
			if ( !$this->hookRunner->onUserRemoveGroup( $userObj, $group ) ) {
				return false;
			}
		}

		if ( $this->readOnlyMode->isReadOnly() ) {
			return false;
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER, [], $this->dbDomain );
		$dbw->delete(
			'user_groups',
			[ 'ug_user' => $user->getId(), 'ug_group' => $group ],
			__METHOD__
		);

		if ( !$dbw->affectedRows() ) {
			return false;
		}
		// Remember that the user was in this group
		$dbw->insert(
			'user_former_groups',
			[ 'ufg_user' => $user->getId(), 'ufg_group' => $group ],
			__METHOD__,
			[ 'IGNORE' ]
		);

		// TODO: optimization: we can avoid re-querying groups if we update caches in place
		$this->clearCache( $user );
		foreach ( $this->clearCacheCallbacks as $callback ) {
			$callback( $user );
		}
		return true;
	}

	/**
	 * Return the tables and fields to be selected to construct new UserGroupMembership object
	 * using newGroupMembershipFromRow method.
	 *
	 * @return array With three keys:
	 *  - tables: (string[]) to include in the `$table` to `IDatabase->select()`
	 *  - fields: (string[]) to include in the `$vars` to `IDatabase->select()`
	 *  - joins: (string[]) to include in the `$joins` to `IDatabase->select()`
	 * @internal
	 * @phan-return array{tables:string[],fields:string[],joins:string[]}
	 */
	public function getQueryInfo() : array {
		return [
			'tables' => [ 'user_groups' ],
			'fields' => [
				'ug_user',
				'ug_group',
				'ug_expiry',
			],
			'joins' => []
		];
	}

	/**
	 * Purge expired memberships from the user_groups table
	 * @internal
	 * @note this could be slow and is intended for use in a background job
	 * @return int|bool false if purging wasn't attempted (e.g. because of
	 *  readonly), the number of rows purged (might be 0) otherwise
	 */
	public function purgeExpired() {
		if ( $this->readOnlyMode->isReadOnly() ) {
			return false;
		}

		$ticket = $this->loadBalancerFactory->getEmptyTransactionTicket( __METHOD__ );
		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER );

		$lockKey = "{$dbw->getDomainID()}:UserGroupManager:purge"; // per-wiki
		$scopedLock = $dbw->getScopedLockAndFlush( $lockKey, __METHOD__, 0 );
		if ( !$scopedLock ) {
			return false; // already running
		}

		$now = time();
		$purgedRows = 0;
		$queryInfo = $this->getQueryInfo();
		do {
			$dbw->startAtomic( __METHOD__ );

			$res = $dbw->select(
				$queryInfo['tables'],
				$queryInfo['fields'],
				[ 'ug_expiry < ' . $dbw->addQuotes( $dbw->timestamp( $now ) ) ],
				__METHOD__,
				[ 'FOR UPDATE', 'LIMIT' => 100 ],
				$queryInfo['joins']
			);

			if ( $res->numRows() > 0 ) {
				$insertData = []; // array of users/groups to insert to user_former_groups
				$deleteCond = []; // array for deleting the rows that are to be moved around
				foreach ( $res as $row ) {
					$insertData[] = [ 'ufg_user' => $row->ug_user, 'ufg_group' => $row->ug_group ];
					$deleteCond[] = $dbw->makeList(
						[ 'ug_user' => $row->ug_user, 'ug_group' => $row->ug_group ],
						$dbw::LIST_AND
					);
				}
				// Delete the rows we're about to move
				$dbw->delete(
					'user_groups',
					$dbw->makeList( $deleteCond, $dbw::LIST_OR ),
					__METHOD__
				);
				// Push the groups to user_former_groups
				$dbw->insert(
					'user_former_groups',
					$insertData,
					__METHOD__,
					[ 'IGNORE' ]
				);
				// Count how many rows were purged
				$purgedRows += $res->numRows();
			}

			$dbw->endAtomic( __METHOD__ );

			$this->loadBalancerFactory->commitAndWaitForReplication( __METHOD__, $ticket );
		} while ( $res->numRows() > 0 );
		return $purgedRows;
	}

	/**
	 * Cleans cached group memberships for a given user
	 *
	 * @param UserIdentity $user
	 */
	public function clearCache( UserIdentity $user ) {
		$userKey = $this->getCacheKey( $user );
		unset( $this->userGroupCache[$userKey] );
	}

	/**
	 * @param int $queryFlags a bit field composed of READ_XXX flags
	 * @return DBConnRef
	 */
	private function getDBConnectionRefForQueryFlags( int $queryFlags ) : DBConnRef {
		list( $mode, ) = DBAccessObjectUtils::getDBOptions( $queryFlags );
		return $this->loadBalancer->getConnectionRef( $mode, [], $this->dbDomain );
	}

	/**
	 * Gets a unique key for various caches.
	 * @param UserIdentity $user
	 * @return string
	 */
	private function getCacheKey( UserIdentity $user ) : string {
		return $user->isRegistered() ? "u:{$user->getId()}" : "anon:{$user->getName()}";
	}
}
