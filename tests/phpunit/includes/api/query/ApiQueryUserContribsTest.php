<?php

use MediaWiki\User\UserRigorOptions;

/**
 * @group API
 * @group Database
 * @group medium
 * @covers ApiQueryUserContribs
 */
class ApiQueryUserContribsTest extends ApiTestCase {
	public function addDBDataOnce() {
		$userFactory = $this->getServiceContainer()->getUserFactory();
		$users = [
			$userFactory->newFromName( '192.168.2.2', UserRigorOptions::RIGOR_NONE ),
			$userFactory->newFromName( '192.168.2.1', UserRigorOptions::RIGOR_NONE ),
			$userFactory->newFromName( '192.168.2.3', UserRigorOptions::RIGOR_NONE ),
			User::createNew( __CLASS__ . ' B' ),
			User::createNew( __CLASS__ . ' A' ),
			User::createNew( __CLASS__ . ' C' ),
			$userFactory->newFromName( 'IW>' . __CLASS__, UserRigorOptions::RIGOR_NONE ),
		];

		$page = $this->getServiceContainer()->getWikiPageFactory()
			->newFromLinkTarget( new TitleValue( NS_MAIN, 'ApiQueryUserContribsTest' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			foreach ( array_reverse( $users ) as $user ) {
				$status = $this->editPage(
					$page,
					new WikitextContent( "Test revision $user #$i" ),
					'Test edit',
					NS_MAIN,
					$user
				);
				if ( !$status->isOK() ) {
					$this->fail( 'Failed to edit: ' . $status->getWikiText( false, false, 'en' ) );
				}
			}
		}
	}

	/**
	 * @dataProvider provideSorting
	 * @param array $params Extra parameters for the query
	 * @param bool $reverse Reverse order?
	 * @param int $revs Number of revisions to expect
	 */
	public function testSorting( $params, $reverse, $revs ) {
		if ( isset( $params['ucuserids'] ) ) {
			$userIdentities = $this->getServiceContainer()->getUserIdentityLookup()
				->newSelectQueryBuilder()
				->whereUserNames( $params['ucuserids'] )
				->fetchUserIdentities();
			$userIds = [];
			foreach ( $userIdentities as $userIdentity ) {
				$userIds[] = $userIdentity->getId();
			}
			$params['ucuserids'] = implode( '|', $userIds );
		}
		if ( isset( $params['ucuser'] ) ) {
			$params['ucuser'] = implode( '|', $params['ucuser'] );
		}

		if ( $reverse ) {
			$params['ucdir'] = 'newer';
		}

		$params += [
			'action' => 'query',
			'list' => 'usercontribs',
			'ucprop' => 'ids',
		];

		$apiResult = $this->doApiRequest( $params + [ 'uclimit' => 500 ] );
		$this->assertArrayNotHasKey( 'continue', $apiResult[0] );
		$this->assertArrayHasKey( 'query', $apiResult[0] );
		$this->assertArrayHasKey( 'usercontribs', $apiResult[0]['query'] );

		$count = 0;
		$ids = [];
		foreach ( $apiResult[0]['query']['usercontribs'] as $page ) {
			$count++;
			$ids[$page['user']][] = $page['revid'];
		}
		$this->assertSame( $revs, $count, 'Expected number of revisions' );
		foreach ( $ids as $user => $revids ) {
			$sorted = $revids;
			$reverse ? sort( $sorted ) : rsort( $sorted );
			$this->assertSame( $sorted, $revids, "IDs for $user are sorted" );
		}

		for ( $limit = 1; $limit < $revs; $limit++ ) {
			$continue = [];
			$count = 0;
			$batchedIds = [];
			while ( $continue !== null ) {
				$apiResult = $this->doApiRequest( $params + [ 'uclimit' => $limit ] + $continue );
				$this->assertArrayHasKey( 'query', $apiResult[0], "Batching with limit $limit" );
				$this->assertArrayHasKey( 'usercontribs', $apiResult[0]['query'],
					"Batching with limit $limit" );
				$continue = $apiResult[0]['continue'] ?? null;
				foreach ( $apiResult[0]['query']['usercontribs'] as $page ) {
					$count++;
					$batchedIds[$page['user']][] = $page['revid'];
				}
				$this->assertLessThanOrEqual( $revs, $count, "Batching with limit $limit" );
			}
			$this->assertSame( $ids, $batchedIds, "Result set is the same when batching with limit $limit" );
		}
	}

	public static function provideSorting() {
		$users = [ __CLASS__ . ' A', __CLASS__ . ' B', __CLASS__ . ' C' ];
		$users2 = [ __CLASS__ . ' A', __CLASS__ . ' B', __CLASS__ . ' D' ];
		$ips = [ '192.168.2.1', '192.168.2.2', '192.168.2.3', '192.168.2.4' ];

		foreach ( [ false, true ] as $reverse ) {
			$name = ( $reverse ? ', reverse' : '' );
			yield "Named users, $name" => [ [ 'ucuser' => $users ], $reverse, 9 ];
			yield "Named users including a no-edit user, $name" => [
				[ 'ucuser' => $users2 ], $reverse, 6
			];
			yield "IP users, $name" => [ [ 'ucuser' => $ips ], $reverse, 9 ];
			yield "All users, $name" => [
				[ 'ucuser' => array_merge( $users, $ips ) ], $reverse, 18
			];
			yield "User IDs, $name" => [ [ 'ucuserids' => $users ], $reverse, 9 ];
			yield "Users by prefix, $name" => [ [ 'ucuserprefix' => __CLASS__ ], $reverse, 9 ];
			yield "IPs by prefix, $name" => [ [ 'ucuserprefix' => '192.168.2.' ], $reverse, 9 ];
			yield "IPs by range, $name" => [ [ 'uciprange' => '192.168.2.0/24' ], $reverse, 9 ];
		}
	}

	public function testInterwikiUser() {
		$params = [
			'action' => 'query',
			'list' => 'usercontribs',
			'ucuser' => 'IW>' . __CLASS__,
			'ucprop' => 'ids',
			'uclimit' => 'max',
		];

		$apiResult = $this->doApiRequest( $params );
		$this->assertArrayNotHasKey( 'continue', $apiResult[0] );
		$this->assertArrayHasKey( 'query', $apiResult[0] );
		$this->assertArrayHasKey( 'usercontribs', $apiResult[0]['query'] );

		$count = 0;
		$ids = [];
		foreach ( $apiResult[0]['query']['usercontribs'] as $page ) {
			$count++;
			$this->assertSame( 'IW>' . __CLASS__, $page['user'], 'Correct user returned' );
			$ids[] = $page['revid'];
		}
		$this->assertSame( 3, $count, 'Expected number of revisions' );
		$sorted = $ids;
		rsort( $sorted );
		$this->assertSame( $sorted, $ids, "IDs are sorted" );
	}

}
