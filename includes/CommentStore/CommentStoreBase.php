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

namespace MediaWiki\CommentStore;

use FormatJson;
use InvalidArgumentException;
use Language;
use MediaWiki\Language\RawMessage;
use Message;
use OverflowException;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

/*
 * Handle database storage of comments such as edit summaries and log reasons.
 *
 * @ingroup CommentStore
 * @since 1.40
 */
class CommentStoreBase {

	/**
	 * Maximum length of a comment in UTF-8 characters. Longer comments will be truncated.
	 * @note This must be at least 255 and not greater than floor( MAX_DATA_LENGTH / 4 ).
	 */
	public const COMMENT_CHARACTER_LIMIT = 500;

	/**
	 * Maximum length of serialized data in bytes. Longer data will result in an exception.
	 * @note This value is determined by the size of the underlying database field,
	 *  currently BLOB in MySQL/MariaDB.
	 */
	public const MAX_DATA_LENGTH = 65535;

	/**
	 * @var int One of the MIGRATION_* constants, or an appropriate combination
	 *  of SCHEMA_COMPAT_* constants.
	 * @todo Deprecate and remove once extensions seem unlikely to need to use
	 *  it for migration anymore.
	 */
	private $stage;

	/** @var array[] Cache for `self::getJoin()` */
	private $joinCache = [];

	/** @var Language Language to use for comment truncation */
	private $lang;

	/**
	 * @param Language $lang Language to use for comment truncation. Defaults
	 *  to content language.
	 * @param int $stage One of the MIGRATION_* constants, or an appropriate
	 *  combination of SCHEMA_COMPAT_* constants.
	 */
	public function __construct( Language $lang, $stage ) {
		if ( ( $stage & SCHEMA_COMPAT_WRITE_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a write mode' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a read mode' );
		}

		$this->lang = $lang;
		$this->stage = $stage;
	}

	/**
	 * Get SELECT fields for the comment key
	 *
	 * Each resulting row should be passed to `self::getCommentLegacy()` to get the
	 * actual comment.
	 *
	 * @note Use of this method may require a subsequent database query to
	 *  actually fetch the comment. If possible, use `self::getJoin()` instead.
	 *
	 * @since 1.30
	 * @since 1.31 Method signature changed, $key parameter added (required since 1.35)
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @return string[] to include in the `$vars` to `IDatabase->select()`. All
	 *  fields are aliased, so `+` is safe to use.
	 */
	public function getFields( $key ) {
		$fields = [];
		if ( ( $this->stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_OLD ) {
			$fields["{$key}_text"] = $key;
			$fields["{$key}_data"] = 'NULL';
			$fields["{$key}_cid"] = 'NULL';
		} else { // READ_BOTH or READ_NEW
			if ( $this->stage & SCHEMA_COMPAT_READ_OLD ) {
				$fields["{$key}_old"] = $key;
			}

			$fields["{$key}_id"] = "{$key}_id";
		}
		return $fields;
	}

	/**
	 * Get SELECT fields and joins for the comment key
	 *
	 * Each resulting row should be passed to `self::getComment()` to get the
	 * actual comment.
	 *
	 * @since 1.30
	 * @since 1.31 Method signature changed, $key parameter added (required since 1.35)
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @return array[] With three keys:
	 *   - tables: (string[]) to include in the `$table` to `IDatabase->select()` or `SelectQueryBuilder::tables`
	 *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()` or `SelectQueryBuilder::fields`
	 *   - joins: (array) to include in the `$join_conds` to `IDatabase->select()` or `SelectQueryBuilder::joinConds`
	 *  All tables, fields, and joins are aliased, so `+` is safe to use.
	 * @phan-return array{tables:string[],fields:string[],joins:array}
	 */
	public function getJoin( $key ) {
		if ( !array_key_exists( $key, $this->joinCache ) ) {
			$tables = [];
			$fields = [];
			$joins = [];

			if ( ( $this->stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_OLD ) {
				$fields["{$key}_text"] = $key;
				$fields["{$key}_data"] = 'NULL';
				$fields["{$key}_cid"] = 'NULL';
			} else { // READ_BOTH or READ_NEW
				$join = ( $this->stage & SCHEMA_COMPAT_READ_OLD ) ? 'LEFT JOIN' : 'JOIN';

				$alias = "comment_$key";
				$tables[$alias] = 'comment';
				$joins[$alias] = [ $join, "{$alias}.comment_id = {$key}_id" ];

				if ( ( $this->stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_NEW ) {
					$fields["{$key}_text"] = "{$alias}.comment_text";
				} else {
					$fields["{$key}_text"] = "COALESCE( {$alias}.comment_text, $key )";
				}
				$fields["{$key}_data"] = "{$alias}.comment_data";
				$fields["{$key}_cid"] = "{$alias}.comment_id";
			}

			$this->joinCache[$key] = [
				'tables' => $tables,
				'fields' => $fields,
				'joins' => $joins,
			];
		}

		return $this->joinCache[$key];
	}

	/**
	 * Extract the comment from a row
	 *
	 * Shared implementation for getComment() and getCommentLegacy()
	 *
	 * @param IDatabase|null $db Database handle for getCommentLegacy(), or null for getComment()
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @param stdClass|array $row
	 * @param bool $fallback
	 * @return CommentStoreComment
	 */
	private function getCommentInternal( ?IDatabase $db, $key, $row, $fallback = false ) {
		$row = (array)$row;
		if ( array_key_exists( "{$key}_text", $row ) && array_key_exists( "{$key}_data", $row ) ) {
			$cid = $row["{$key}_cid"] ?? null;
			$text = $row["{$key}_text"];
			$data = $row["{$key}_data"];
		} elseif ( ( $this->stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_OLD ) {
			$cid = null;
			if ( $fallback && isset( $row[$key] ) ) {
				wfLogWarning( "Using deprecated fallback handling for comment $key" );
				$text = $row[$key];
			} else {
				wfLogWarning(
					"Missing {$key}_text and {$key}_data fields in row with MIGRATION_OLD / READ_OLD"
				);
				$text = '';
			}
			$data = null;
		} else {
			$row2 = null;
			if ( array_key_exists( "{$key}_id", $row ) ) {
				if ( !$db ) {
					throw new InvalidArgumentException(
						"\$row does not contain fields needed for comment $key and getComment(), but "
						. "does have fields for getCommentLegacy()"
					);
				}
				$id = $row["{$key}_id"];
				$row2 = $db->newSelectQueryBuilder()
					->select( [ 'comment_id', 'comment_text', 'comment_data' ] )
					->from( 'comment' )
					->where( [ 'comment_id' => $id ] )
					->caller( __METHOD__ )->fetchRow();
			}
			if ( $row2 === null && $fallback && isset( $row[$key] ) ) {
				wfLogWarning( "Using deprecated fallback handling for comment $key" );
				$row2 = (object)[ 'comment_text' => $row[$key], 'comment_data' => null ];
			}
			if ( $row2 === null ) {
				throw new InvalidArgumentException( "\$row does not contain fields needed for comment $key" );
			}

			if ( $row2 ) {
				$cid = $row2->comment_id;
				$text = $row2->comment_text;
				$data = $row2->comment_data;
			} elseif ( ( $this->stage & SCHEMA_COMPAT_READ_OLD ) &&
				array_key_exists( "{$key}_old", $row )
			) {
				$cid = null;
				$text = $row["{$key}_old"];
				$data = null;
			} else {
				// @codeCoverageIgnoreStart
				// @phan-suppress-next-line PhanPossiblyUndeclaredVariable $id is set when $row2 is okay
				wfLogWarning( "Missing comment row for $key, id=$id" );
				$cid = null;
				$text = '';
				$data = null;
				// @codeCoverageIgnoreEnd
			}
		}

		$msg = null;
		if ( $data !== null ) {
			$data = FormatJson::decode( $data, true );
			if ( !is_array( $data ) ) {
				// @codeCoverageIgnoreStart
				wfLogWarning( "Invalid JSON object in comment: $data" );
				$data = null;
				// @codeCoverageIgnoreEnd
			} else {
				if ( isset( $data['_message'] ) ) {
					$msg = self::decodeMessage( $data['_message'] )
						->setInterfaceMessageFlag( true );
				}
				if ( !empty( $data['_null'] ) ) {
					$data = null;
				} else {
					foreach ( $data as $k => $v ) {
						if ( substr( $k, 0, 1 ) === '_' ) {
							unset( $data[$k] );
						}
					}
				}
			}
		}

		return new CommentStoreComment( $cid, $text, $msg, $data );
	}

	/**
	 * Extract the comment from a row
	 *
	 * Use `self::getJoin()` to ensure the row contains the needed data.
	 *
	 * If you need to fake a comment in a row for some reason, set fields
	 * `{$key}_text` (string) and `{$key}_data` (JSON string or null).
	 *
	 * @since 1.30
	 * @since 1.31 Method signature changed, $key parameter added (required since 1.35)
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @param stdClass|array|null $row Result row.
	 * @param bool $fallback If true, fall back as well as possible instead of throwing an exception.
	 * @return CommentStoreComment
	 */
	public function getComment( $key, $row = null, $fallback = false ) {
		if ( $row === null ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( '$row must not be null' );
			// @codeCoverageIgnoreEnd
		}
		return $this->getCommentInternal( null, $key, $row, $fallback );
	}

	/**
	 * Extract the comment from a row, with legacy lookups.
	 *
	 * If `$row` might have been generated using `self::getFields()` rather
	 * than `self::getJoin()`, use this. Prefer `self::getComment()` if you
	 * know callers used `self::getJoin()` for the row fetch.
	 *
	 * If you need to fake a comment in a row for some reason, set fields
	 * `{$key}_text` (string) and `{$key}_data` (JSON string or null).
	 *
	 * @since 1.30
	 * @since 1.31 Method signature changed, $key parameter added (required since 1.35)
	 * @param IDatabase $db Database handle to use for lookup
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @param stdClass|array|null $row Result row.
	 * @param bool $fallback If true, fall back as well as possible instead of throwing an exception.
	 * @return CommentStoreComment
	 */
	public function getCommentLegacy( IDatabase $db, $key, $row = null, $fallback = false ) {
		if ( $row === null ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( '$row must not be null' );
			// @codeCoverageIgnoreEnd
		}
		return $this->getCommentInternal( $db, $key, $row, $fallback );
	}

	/**
	 * Create a new CommentStoreComment, inserting it into the database if necessary
	 *
	 * If a comment is going to be passed to `self::insert()` or the like
	 * multiple times, it will be more efficient to pass a CommentStoreComment
	 * once rather than making `self::insert()` do it every time through.
	 *
	 * @note When passing a CommentStoreComment, this may set `$comment->id` if
	 *  it's not already set. If `$comment->id` is already set, it will not be
	 *  verified that the specified comment actually exists or that it
	 *  corresponds to the comment text, message, and/or data in the
	 *  CommentStoreComment.
	 * @param IDatabase $dbw Database handle to insert on. Unused if `$comment`
	 *  is a CommentStoreComment and `$comment->id` is set.
	 * @param string|Message|CommentStoreComment $comment Comment text or Message object, or
	 *  a CommentStoreComment.
	 * @param array|null $data Structured data to store. Keys beginning with '_' are reserved.
	 *  Ignored if $comment is a CommentStoreComment.
	 * @return CommentStoreComment
	 */
	public function createComment( IDatabase $dbw, $comment, array $data = null ) {
		$comment = CommentStoreComment::newUnsavedComment( $comment, $data );

		# Truncate comment in a Unicode-sensitive manner
		$comment->text = $this->lang->truncateForVisual( $comment->text, self::COMMENT_CHARACTER_LIMIT );

		if ( ( $this->stage & SCHEMA_COMPAT_WRITE_NEW ) && !$comment->id ) {
			$dbData = $comment->data;
			if ( !$comment->message instanceof RawMessage ) {
				$dbData ??= [ '_null' => true ];
				$dbData['_message'] = self::encodeMessage( $comment->message );
			}
			if ( $dbData !== null ) {
				$dbData = FormatJson::encode( (object)$dbData, false, FormatJson::ALL_OK );
				$len = strlen( $dbData );
				if ( $len > self::MAX_DATA_LENGTH ) {
					$max = self::MAX_DATA_LENGTH;
					throw new OverflowException( "Comment data is too long ($len bytes, maximum is $max)" );
				}
			}

			$hash = self::hash( $comment->text, $dbData );
			$commentId = $dbw->newSelectQueryBuilder()
				->select( 'comment_id' )
				->from( 'comment' )
				->where( [
					'comment_hash' => $hash,
					'comment_text' => $comment->text,
					'comment_data' => $dbData,
				] )
				->caller( __METHOD__ )->fetchField();
			if ( !$commentId ) {
				$dbw->newInsertQueryBuilder()
					->insert( 'comment' )
					->row( [ 'comment_hash' => $hash, 'comment_text' => $comment->text, 'comment_data' => $dbData ] )
					->caller( __METHOD__ )->execute();
				$commentId = $dbw->insertId();
			}
			$comment->id = (int)$commentId;
		}

		return $comment;
	}

	/**
	 * Insert a comment in preparation for a row that references it
	 *
	 * @note It's recommended to include both the call to this method and the
	 *  row insert in the same transaction.
	 *
	 * @since 1.30
	 * @since 1.31 Method signature changed, $key parameter added (required since 1.35)
	 * @param IDatabase $dbw Database handle to insert on
	 * @param string $key A key such as "rev_comment" identifying the comment
	 *  field being fetched.
	 * @param string|Message|CommentStoreComment|null $comment As for `self::createComment()`
	 * @param array|null $data As for `self::createComment()`
	 * @return array Fields for the insert or update
	 */
	public function insert( IDatabase $dbw, $key, $comment = null, $data = null ) {
		if ( $comment === null ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( '$comment can not be null' );
			// @codeCoverageIgnoreEnd
		}

		$fields = [];
		$comment = $this->createComment( $dbw, $comment, $data );

		if ( $this->stage & SCHEMA_COMPAT_WRITE_OLD ) {
			$fields[$key] = $this->lang->truncateForDatabase( $comment->text, 255 );
		}

		if ( $this->stage & SCHEMA_COMPAT_WRITE_NEW ) {
			$fields["{$key}_id"] = $comment->id;
		}

		return $fields;
	}

	/**
	 * Encode a Message as a PHP data structure
	 * @param Message $msg
	 * @return array
	 */
	private static function encodeMessage( Message $msg ) {
		$key = count( $msg->getKeysToTry() ) > 1 ? $msg->getKeysToTry() : $msg->getKey();
		$params = $msg->getParams();
		foreach ( $params as &$param ) {
			if ( $param instanceof Message ) {
				$param = [
					'message' => self::encodeMessage( $param )
				];
			}
		}
		array_unshift( $params, $key );
		return $params;
	}

	/**
	 * Decode a message that was encoded by self::encodeMessage()
	 * @param array $data
	 * @return Message
	 */
	private static function decodeMessage( $data ) {
		$key = array_shift( $data );
		foreach ( $data as &$param ) {
			if ( is_object( $param ) ) {
				$param = (array)$param;
			}
			if ( is_array( $param ) && count( $param ) === 1 && isset( $param['message'] ) ) {
				$param = self::decodeMessage( $param['message'] );
			}
		}
		return new Message( $key, $data );
	}

	/**
	 * Hashing function for comment storage
	 * @param string $text Comment text
	 * @param string|null $data Comment data
	 * @return int 32-bit signed integer
	 */
	public static function hash( $text, $data ) {
		$hash = crc32( $text ) ^ crc32( (string)$data );

		// 64-bit PHP returns an unsigned CRC, change it to signed for
		// insertion into the database.
		if ( $hash >= 0x80000000 ) {
			$hash |= -1 << 32;
		}

		return $hash;
	}

}
