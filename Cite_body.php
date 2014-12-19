<?php

/**#@+
 * A parser extension that adds two tags, <ref> and <references> for adding
 * citations to pages
 *
 * @ingroup Extensions
 *
 * @link http://www.mediawiki.org/wiki/Extension:Cite/Cite.php Documentation
 * @link http://www.w3.org/TR/html4/struct/text.html#edef-CITE <cite> definition in HTML
 * @link http://www.w3.org/TR/2005/WD-xhtml2-20050527/mod-text.html#edef_text_cite <cite> definition in XHTML 2.0
 *
 * @bug 4579
 *
 * @author Ævar Arnfjörð Bjarmason <avarab@gmail.com>
 * @copyright Copyright © 2005, Ævar Arnfjörð Bjarmason
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

/**
 * WARNING: MediaWiki core hardcodes this class name to check if the
 * Cite extension is installed. See T89151.
 */
class Cite {

	/**
	 * @todo document
	 */
	const DEFAULT_GROUP = '';

	/**#@+
	 * @access private
	 */

	/**
	 * Datastructure representing <ref> input, in the format of:
	 * <code>
	 * array(
	 * 	'user supplied' => array(
	 *		'text' => 'user supplied reference & key',
	 *		'count' => 1, // occurs twice
	 * 		'number' => 1, // The first reference, we want
	 * 		               // all occourances of it to
	 * 		               // use the same number
	 *	),
	 *	0 => 'Anonymous reference',
	 *	1 => 'Another anonymous reference',
	 *	'some key' => array(
	 *		'text' => 'this one occurs once'
	 *		'count' => 0,
	 * 		'number' => 4
	 *	),
	 *	3 => 'more stuff'
	 * );
	 * </code>
	 *
	 * This works because:
	 * * PHP's datastructures are guaranteed to be returned in the
	 *   order that things are inserted into them (unless you mess
	 *   with that)
	 * * User supplied keys can't be integers, therefore avoiding
	 *   conflict with anonymous keys
	 *
	 * @var array
	 **/
	public $mRefs = array();

	/**
	 * Count for user displayed output (ref[1], ref[2], ...)
	 *
	 * @var int
	 */
	public $mOutCnt = 0;
	public $mGroupCnt = array();

	/**
	 * Counter to track the total number of (useful) calls to either the
	 * ref or references tag hook
	 */
	public $mCallCnt = 0;

	/**
	 * The backlinks, in order, to pass as $3 to
	 * 'cite_references_link_many_format', defined in
	 * 'cite_references_link_many_format_backlink_labels
	 *
	 * @var array
	 */
	public $mBacklinkLabels;

	/**
	 * The links to use per group, in order.
	 *
	 * @var array
	 */
	public $mLinkLabels = array();

	/**
	 * @var Parser
	 */
	public $mParser;

	/**
	 * True when a <ref> tag is being processed.
	 * Used to avoid infinite recursion
	 *
	 * @var boolean
	 */
	public $mInCite = false;


	const NOT_IN_REFERENCES = 0;
	const RUNNING_REFERENCES = 1;
	const PARSING_REFERENCES = 2;

	/**
	 * When parsing the contents of a <references> tag, set to Cite::PARSING_REFERENCES.
	 * When performing any other operation as part of a <references> tag (such as when
	 * parsing the references themselves), set to Cite::RUNNING_REFERENCES. Otherwise, set
	 * to Cite::NOT_IN_REFERENCES;
	 *
	 * @var int
	 */
	public $mInReferences = Cite::NOT_IN_REFERENCES;

	/**
	 * Error stack used when defining refs in <references>
	 *
	 * @var array
	 */
	public $mReferencesErrors = array();

	/**
	 * Group used when in <references> block
	 *
	 * @var string
	 */
	public $mReferencesGroup = '';

	/**
	 * Did we install us into $wgHooks yet?
	 * @var Boolean
	 */
	static protected $hooksInstalled = false;

	/**#@+ @access private */

	/**
	 * Callback function for <ref> that actually does the work.
	 *
	 * @param $str string Input
	 * @param $argv array Arguments
	 * @param $parser Parser
	 *
	 * @return string
	 */
	function unstripRef( $str, $argv, $parser ) {
		if ( $this->mInCite ) {
			return htmlspecialchars( "<ref>$str</ref>" );
		}

		$this->mCallCnt++;
		$this->mInCite = true;

		$ret = $this->guardedRef( $str, $argv, $parser );

		$this->mInCite = false;

		$parserOutput = $parser->getOutput();
		$parserOutput->addModules( 'ext.cite' );
		$parserOutput->addModuleStyles( 'ext.rtlcite' );

		return $ret;
	}

	/**
	 * Callback function for <ref>. Nothing actually gets done until unstrip.
	 *
	 * @param $str string Input
	 * @param $argv array Arguments
	 * @param $parser Parser
	 *
	 * @return callable
	 */
	function ref( $str, $argv, $parser ) {
		$self = $this;
		return function() use( $self, $str, $argv, $parser ) {
			return $self->unstripRef( $str, $argv, $parser );
		};
	}

	/**
	 * @param $str string Input
	 * @param $argv array Arguments
	 * @param $parser Parser
	 * @param $default_group string
	 * @return string
	 */
	function guardedRef( $str, $argv, $parser, $default_group = self::DEFAULT_GROUP ) {
		$this->mParser = $parser;

		# The key here is the "name" attribute.
		list( $key, $group, $follow ) = $this->refArg( $argv );

		# Split these into groups.
		if ( $group === null ) {
			if ( $this->mInReferences === Cite::PARSING_REFERENCES ) {
				$group = $this->mReferencesGroup;
			} else {
				$group = $default_group;
			}
		}

		# This section deals with constructions of the form
		#
		# <references>
		# <ref name="foo"> BAR </ref>
		# </references>
		#
		if ( $this->mInReferences === Cite::PARSING_REFERENCES ) {
			if ( $group != $this->mReferencesGroup ) {
				# <ref> and <references> have conflicting group attributes.
				$this->mReferencesErrors[] =
					$this->error( 'cite_error_references_group_mismatch', htmlspecialchars( $group ) );
			} elseif ( $str !== '' ) {
				if ( !isset( $this->mRefs[$group] ) ) {
					# Called with group attribute not defined in text.
					$this->mReferencesErrors[] =
						$this->error( 'cite_error_references_missing_group', htmlspecialchars( $group ) );
				} elseif ( $key === null || $key === '' ) {
					# <ref> calls inside <references> must be named
					$this->mReferencesErrors[] =
						$this->error( 'cite_error_references_no_key' );
				} elseif ( !isset( $this->mRefs[$group][$key] ) ) {
					# Called with name attribute not defined in text.
					$this->mReferencesErrors[] =
						$this->error( 'cite_error_references_missing_key', $key );
				} else {
					# Assign the text to corresponding ref
					$this->mRefs[$group][$key]['text'] = $str;
				}
			} else {
				# <ref> called in <references> has no content.
				$this->mReferencesErrors[] =
					$this->error( 'cite_error_empty_references_define', $key );
			}
			return '';
		}

		if ( $str === '' ) {
			# <ref ...></ref>.  This construct is  invalid if
			# it's a contentful ref, but OK if it's a named duplicate and should
			# be equivalent <ref ... />, for compatability with #tag.
			if ( $key == false ) {
				return $this->error( 'cite_error_ref_no_input' );
			} else {
				$str = null;
			}
		}

		if ( $key === false ) {
			# TODO: Comment this case; what does this condition mean?
			return $this->error( 'cite_error_ref_too_many_keys' );
		}

		if ( $str === null && $key === null ) {
			# Something like <ref />; this makes no sense.
			return $this->error( 'cite_error_ref_no_key' );
		}

		if ( preg_match( '/^[0-9]+$/', $key ) || preg_match( '/^[0-9]+$/', $follow ) ) {
			# Numeric names mess up the resulting id's, potentially produ-
			# cing duplicate id's in the XHTML.  The Right Thing To Do
			# would be to mangle them, but it's not really high-priority
			# (and would produce weird id's anyway).

			return $this->error( 'cite_error_ref_numeric_key' );
		}

		if ( preg_match(
			'/<ref\b[^<]*?>/',
			preg_replace( '#<([^ ]+?).*?>.*?</\\1 *>|<!--.*?-->#', '', $str )
		) ) {
			# (bug 6199) This most likely implies that someone left off the
			# closing </ref> tag, which will cause the entire article to be
			# eaten up until the next <ref>.  So we bail out early instead.
			# The fancy regex above first tries chopping out anything that
			# looks like a comment or SGML tag, which is a crude way to avoid
			# false alarms for <nowiki>, <pre>, etc.
			#
			# Possible improvement: print the warning, followed by the contents
			# of the <ref> tag.  This way no part of the article will be eaten
			# even temporarily.

			return $this->error( 'cite_error_included_ref' );
		}

		if ( is_string( $key ) || is_string( $str ) ) {
			# We don't care about the content: if the key exists, the ref
			# is presumptively valid.  Either it stores a new ref, or re-
			# fers to an existing one.  If it refers to a nonexistent ref,
			# we'll figure that out later.  Likewise it's definitely valid
			# if there's any content, regardless of key.

			return $this->stack( $str, $key, $group, $follow, $argv );
		}

		# Not clear how we could get here, but something is probably
		# wrong with the types.  Let's fail fast.
		throw new Exception( 'Invalid $str and/or $key: ' . serialize( array( $str, $key ) ) );
	}

	/**
	 * Parse the arguments to the <ref> tag
	 *
	 *  "name" : Key of the reference.
	 *  "group" : Group to which it belongs. Needs to be passed to <references /> too.
	 *  "follow" : If the current reference is the continuation of another, key of that reference.
	 *
	 *
	 * @param $argv array The argument vector
	 * @return mixed false on invalid input, a string on valid
	 *               input and null on no input
	 */
	function refArg( $argv ) {
		global $wgAllowCiteGroups;
		$cnt = count( $argv );
		$group = null;
		$key = null;
		$follow = null;

		if ( $cnt > 2 ) {
			// There should only be one key or follow parameter, and one group parameter
			// FIXME : this looks inconsistent, it should probably return a tuple
			return false;
		} elseif ( $cnt >= 1 ) {
			if ( isset( $argv['name'] ) && isset( $argv['follow'] ) ) {
				return array( false, false, false );
			}
			if ( isset( $argv['name'] ) ) {
				// Key given.
				$key = Sanitizer::escapeId( $argv['name'], 'noninitial' );
				unset( $argv['name'] );
				--$cnt;
			}
			if ( isset( $argv['follow'] ) ) {
				// Follow given.
				$follow = Sanitizer::escapeId( $argv['follow'], 'noninitial' );
				unset( $argv['follow'] );
				--$cnt;
			}
			if ( isset( $argv['group'] ) ) {
				if ( !$wgAllowCiteGroups ) {
					// remove when groups are fully tested.
					return array( false );
				}
				// Group given.
				$group = $argv['group'];
				unset( $argv['group'] );
				--$cnt;
			}

			if ( $cnt == 0 ) {
				return array ( $key, $group, $follow );
			} else {
				// Invalid key
				return array( false, false, false );
			}
		} else {
			// No key
			return array( null, $group, false );
		}
	}

	/**
	 * Populate $this->mRefs based on input and arguments to <ref>
	 *
	 * @param $str string Input from the <ref> tag
	 * @param $key mixed Argument to the <ref> tag as returned by $this->refArg()
	 * @param $group
	 * @param $follow
	 * @param $call
	 *
	 * @return string
	 */
	function stack( $str, $key = null, $group, $follow, $call ) {
		if ( !isset( $this->mRefs[$group] ) ) {
			$this->mRefs[$group] = array();
		}
		if ( !isset( $this->mGroupCnt[$group] ) ) {
			$this->mGroupCnt[$group] = 0;
		}

		if ( $follow != null ) {
			if ( isset( $this->mRefs[$group][$follow] ) && is_array( $this->mRefs[$group][$follow] ) ) {
				// add text to the note that is being followed
				$this->mRefs[$group][$follow]['text'] = $this->mRefs[$group][$follow]['text'] . ' ' . $str;
			} else {
				// insert part of note at the beginning of the group
				$groupsCount = count( $this->mRefs[$group] );
				for ( $k = 0; $k < $groupsCount; $k++ ) {
					if ( !isset( $this->mRefs[$group][$k]['follow'] ) ) {
						break;
					}
				}
				array_splice( $this->mRefs[$group], $k, 0,
					array( array( 'count' => - 1,
						'text' => $str,
						'key' => ++$this->mOutCnt ,
						'follow' => $follow ) ) );
			}
			// return an empty string : this is not a reference
			return '';
		}
		if ( $key === null ) {
			// No key
			// $this->mRefs[$group][] = $str;
			$this->mRefs[$group][] = array( 'count' => - 1, 'text' => $str, 'key' => ++$this->mOutCnt );

			return $this->linkRef( $group, $this->mOutCnt );
		} elseif ( is_string( $key ) ) {
			// Valid key
			if ( !isset( $this->mRefs[$group][$key] ) || !is_array( $this->mRefs[$group][$key] ) ) {
				// First occurrence
				$this->mRefs[$group][$key] = array(
					'text' => $str,
					'count' => 0,
					'key' => ++$this->mOutCnt,
					'number' => ++$this->mGroupCnt[$group]
				);

				return
					$this->linkRef(
						$group,
						$key,
						$this->mRefs[$group][$key]['key'] . "-" . $this->mRefs[$group][$key]['count'],
						$this->mRefs[$group][$key]['number'],
						"-" . $this->mRefs[$group][$key]['key']
					);
			} else {
				// We've been here before
				if ( $this->mRefs[$group][$key]['text'] === null && $str !== '' ) {
					// If no text found before, use this text
					$this->mRefs[$group][$key]['text'] = $str;
				}
				return
					$this->linkRef(
						$group,
						$key,
						$this->mRefs[$group][$key]['key'] . "-" . ++$this->mRefs[$group][$key]['count'],
						$this->mRefs[$group][$key]['number'],
						"-" . $this->mRefs[$group][$key]['key']
					);
			}
		} else {
			throw new Exception( 'Invalid stack key: ' . serialize( $key ) );
		}
	}

	/**
	 * Callback function for <references> that actually does the work.
	 *
	 * @param $str string Input
	 * @param $argv array Arguments
	 * @param $parser Parser
	 *
	 * @return string
	 */
	function unstripReferences( $str, $argv, $parser ) {
		if ( $this->mInCite || $this->mInReferences ) {
			if ( is_null( $str ) ) {
				return htmlspecialchars( "<references/>" );
			} else {
				return htmlspecialchars( "<references>$str</references>" );
			}
		} else {
			$this->mCallCnt++;
			$this->mInReferences = Cite::RUNNING_REFERENCES;
			$ret = $this->guardedReferences( $str, $argv, $parser );
			$this->mInReferences = Cite::NOT_IN_REFERENCES;
			return $ret;
		}
	}

	/**
	 * Callback function for <references>. Nothing actually gets done until unstrip.
	 *
	 * @param $str string Input
	 * @param $argv array Arguments
	 * @param $parser Parser
	 *
	 * @return callable
	 */
	function references( $str, $argv, $parser ) {
		$self = $this;
		return function() use( $self, $str, $argv, $parser ) {
			return $self->unstripReferences( $str, $argv, $parser );
		};
	}

	/**
	 * @param $str string
	 * @param $argv array
	 * @param $parser Parser
	 * @param $group string
	 * @return string
	 */
	function guardedReferences( $str, $argv, $parser, $group = self::DEFAULT_GROUP ) {
		global $wgAllowCiteGroups;

		$this->mParser = $parser;

		if ( isset( $argv['group'] ) && $wgAllowCiteGroups ) {
			$group = $argv['group'];
			unset ( $argv['group'] );
		}

		if ( strval( $str ) !== '' ) {
			$this->mReferencesGroup = $group;

			# Parse $str to process any unparsed <ref> tags.
			$this->mInReferences = Cite::PARSING_REFERENCES;
			$parser->mStripState->unstripGeneral( $parser->recursiveTagParse( $str ) );
			$this->mInReferences = Cite::RUNNING_REFERENCES;
		}

		if ( count( $argv ) && $wgAllowCiteGroups ) {
			return $this->error( 'cite_error_references_invalid_parameters_group' );
		} elseif ( count( $argv ) ) {
			return $this->error( 'cite_error_references_invalid_parameters' );
		} else {
			$s = $this->referencesFormat( $group );
			if ( $parser->getOptions()->getIsSectionPreview() ) {
				return $s;
			}

			# Append errors generated while processing <references>
			if ( count( $this->mReferencesErrors ) > 0 ) {
				$s .= "\n" . implode( "<br />\n", $this->mReferencesErrors );
				$this->mReferencesErrors = array();
			}
			return $s;
		}
	}

	/**
	 * Make output to be returned from the references() function
	 *
	 * @param $group
	 *
	 * @return string XHTML ready for output
	 */
	function referencesFormat( $group ) {
		if ( ( count( $this->mRefs ) == 0 ) || ( empty( $this->mRefs[$group] ) ) ) {
			return '';
		}

		wfProfileIn( __METHOD__ . '-entries' );
		$ent = array();
		foreach ( $this->mRefs[$group] as $k => $v ) {
			$ent[] = $this->referencesFormatEntry( $k, $v );
		}

		$prefix = wfMessage( 'cite_references_prefix' )->inContentLanguage()->plain();
		$suffix = wfMessage( 'cite_references_suffix' )->inContentLanguage()->plain();
		$content = implode( "\n", $ent );

		// Prepare the parser input.
		// We add new lines between the pieces to avoid a confused tidy (bug 13073).
		$parserInput = $prefix . "\n" . $content . "\n" . $suffix;

		// Let's try to cache it.
		global $wgMemc;
		$cacheKey = wfMemcKey( 'citeref', md5( $parserInput ), $this->mParser->Title()->getArticleID() );

		wfProfileOut( __METHOD__ . '-entries' );

		global $wgCiteCacheReferences;
		$data = false;
		if ( $wgCiteCacheReferences ) {
			wfProfileIn( __METHOD__ . '-cache-get' );
			$data = $wgMemc->get( $cacheKey );
			wfProfileOut( __METHOD__ . '-cache-get' );
		}

		if ( !$data || !$this->mParser->isValidHalfParsedText( $data ) ) {
			wfProfileIn( __METHOD__ . '-parse' );

			// Live hack: parse() adds two newlines on WM, can't reproduce it locally -ævar
			$ret = rtrim( $this->mParser->recursiveTagParse( $parserInput ), "\n" );

			if ( $wgCiteCacheReferences ) {
				$serData = $this->mParser->serializeHalfParsedText( $ret );
				$wgMemc->set( $cacheKey, $serData, 86400 );
			}

			wfProfileOut( __METHOD__ . '-parse' );
		} else {
			$ret = $this->mParser->unserializeHalfParsedText( $data );
		}

		// done, clean up so we can reuse the group
		unset( $this->mRefs[$group] );
		unset( $this->mGroupCnt[$group] );

		return $ret;
	}

	/**
	 * Format a single entry for the referencesFormat() function
	 *
	 * @param string $key The key of the reference
	 * @param mixed $val The value of the reference, string for anonymous
	 *                   references, array for user-suppplied
	 * @return string Wikitext
	 */
	function referencesFormatEntry( $key, $val ) {
		// Anonymous reference
		if ( !is_array( $val ) ) {
			return wfMessage(
					'cite_references_link_one',
					$this->referencesKey( $key ),
					$this->refKey( $key ),
					$this->referenceText( $key, $val )
				)->inContentLanguage()->plain();
		}
		$text = $this->referenceText( $key, $val['text'] );
		if ( isset( $val['follow'] ) ) {
			return wfMessage(
					'cite_references_no_link',
					$this->referencesKey( $val['follow'] ),
					$text
				)->inContentLanguage()->plain();
		} elseif ( !isset( $val['text'] ) ) {
			return wfMessage(
						'cite_references_link_one',
						$this->referencesKey( $key ),
						$this->refKey( $key, $val['count'] ),
						$text
					)->inContentLanguage()->plain();
		}

		if ( $val['count'] < 0 ) {
			return wfMessage(
					'cite_references_link_one',
					$this->referencesKey( $val['key'] ),
					# $this->refKey( $val['key'], $val['count'] ),
					$this->refKey( $val['key'] ),
					$text
				)->inContentLanguage()->plain();
			// Standalone named reference, I want to format this like an
			// anonymous reference because displaying "1. 1.1 Ref text" is
			// overkill and users frequently use named references when they
			// don't need them for convenience
		} elseif ( $val['count'] === 0 ) {
			return wfMessage(
					'cite_references_link_one',
					$this->referencesKey( $key . "-" . $val['key'] ),
					# $this->refKey( $key, $val['count'] ),
					$this->refKey( $key, $val['key'] . "-" . $val['count'] ),
					$text
				)->inContentLanguage()->plain();
		// Named references with >1 occurrences
		} else {
			$links = array();
			// for group handling, we have an extra key here.
			for ( $i = 0; $i <= $val['count']; ++$i ) {
				$links[] = wfMessage(
						'cite_references_link_many_format',
						$this->refKey( $key, $val['key'] . "-$i" ),
						$this->referencesFormatEntryNumericBacklinkLabel( $val['number'], $i, $val['count'] ),
						$this->referencesFormatEntryAlternateBacklinkLabel( $i )
				)->inContentLanguage()->plain();
			}

			$list = $this->listToText( $links );

			return wfMessage( 'cite_references_link_many',
					$this->referencesKey( $key . "-" . $val['key'] ),
					$list,
					$text
				)->inContentLanguage()->plain();
		}
	}

	/**
	 * Returns formatted reference text
	 * @param String $key
	 * @param String $text
	 * @return String
	 */
	function referenceText( $key, $text ) {
		if ( !isset( $text ) || $text === '' ) {
			return $this->error( 'cite_error_references_no_text', $key, 'noparse' );
		}
		return '<span class="reference-text">' . rtrim( $text, "\n" ) . "</span>\n";
	}

	/**
	 * Generate a numeric backlink given a base number and an
	 * offset, e.g. $base = 1, $offset = 2; = 1.2
	 * Since bug #5525, it correctly does 1.9 -> 1.10 as well as 1.099 -> 1.100
	 *
	 * @static
	 *
	 * @param int $base The base
	 * @param int $offset The offset
	 * @param int $max Maximum value expected.
	 * @return string
	 */
	function referencesFormatEntryNumericBacklinkLabel( $base, $offset, $max ) {
		global $wgContLang;
		$scope = strlen( $max );
		$ret = $wgContLang->formatNum(
			sprintf( "%s.%0{$scope}s", $base, $offset )
		);
		return $ret;
	}

	/**
	 * Generate a custom format backlink given an offset, e.g.
	 * $offset = 2; = c if $this->mBacklinkLabels = array( 'a',
	 * 'b', 'c', ...). Return an error if the offset > the # of
	 * array items
	 *
	 * @param int $offset The offset
	 *
	 * @return string
	 */
	function referencesFormatEntryAlternateBacklinkLabel( $offset ) {
		if ( !isset( $this->mBacklinkLabels ) ) {
			$this->genBacklinkLabels();
		}
		if ( isset( $this->mBacklinkLabels[$offset] ) ) {
			return $this->mBacklinkLabels[$offset];
		} else {
			// Feed me!
			return $this->error( 'cite_error_references_no_backlink_label', null, 'noparse' );
		}
	}

	/**
	 * Generate a custom format link for a group given an offset, e.g.
	 * the second <ref group="foo"> is b if $this->mLinkLabels["foo"] =
	 * array( 'a', 'b', 'c', ...).
	 * Return an error if the offset > the # of array items
	 *
	 * @param int $offset The offset
	 * @param string $group The group name
	 * @param string $label The text to use if there's no message for them.
	 *
	 * @return string
	 */
	function getLinkLabel( $offset, $group, $label ) {
		$message = "cite_link_label_group-$group";
		if ( !isset( $this->mLinkLabels[$group] ) ) {
			$this->genLinkLabels( $group, $message );
		}
		if ( $this->mLinkLabels[$group] === false ) {
			// Use normal representation, ie. "$group 1", "$group 2"...
			return $label;
		}

		if ( isset( $this->mLinkLabels[$group][$offset - 1] ) ) {
			return $this->mLinkLabels[$group][$offset - 1];
		} else {
			// Feed me!
			return $this->error( 'cite_error_no_link_label_group', array( $group, $message ), 'noparse' );
		}
	}

	/**
	 * Return an id for use in wikitext output based on a key and
	 * optionally the number of it, used in <references>, not <ref>
	 * (since otherwise it would link to itself)
	 *
	 * @static
	 *
	 * @param string $key The key
	 * @param int $num The number of the key
	 * @return string A key for use in wikitext
	 */
	function refKey( $key, $num = null ) {
		$prefix = wfMessage( 'cite_reference_link_prefix' )->inContentLanguage()->text();
		$suffix = wfMessage( 'cite_reference_link_suffix' )->inContentLanguage()->text();
		if ( isset( $num ) ) {
			$key = wfMessage( 'cite_reference_link_key_with_num', $key, $num )
				->inContentLanguage()->plain();
		}

		return "$prefix$key$suffix";
	}

	/**
	 * Return an id for use in wikitext output based on a key and
	 * optionally the number of it, used in <ref>, not <references>
	 * (since otherwise it would link to itself)
	 *
	 * @static
	 *
	 * @param string $key The key
	 * @param int $num The number of the key
	 * @return string A key for use in wikitext
	 */
	function referencesKey( $key, $num = null ) {
		$prefix = wfMessage( 'cite_references_link_prefix' )->inContentLanguage()->text();
		$suffix = wfMessage( 'cite_references_link_suffix' )->inContentLanguage()->text();
		if ( isset( $num ) ) {
			$key = wfMessage( 'cite_reference_link_key_with_num', $key, $num )
				->inContentLanguage()->plain();
		}

		return "$prefix$key$suffix";
	}

	/**
	 * Generate a link (<sup ...) for the <ref> element from a key
	 * and return XHTML ready for output
	 *
	 * @param $group
	 * @param $key string The key for the link
	 * @param $count int The index of the key, used for distinguishing
	 *                   multiple occurrences of the same key
	 * @param $label int The label to use for the link, I want to
	 *                   use the same label for all occourances of
	 *                   the same named reference.
	 * @param $subkey string
	 *
	 * @return string
	 */
	function linkRef( $group, $key, $count = null, $label = null, $subkey = '' ) {
		global $wgContLang;
		$label = is_null( $label ) ? ++$this->mGroupCnt[$group] : $label;

		return
			$this->mParser->recursiveTagParse(
				wfMessage(
					'cite_reference_link',
					$this->refKey( $key, $count ),
					$this->referencesKey( $key . $subkey ),
					$this->getLinkLabel( $label, $group,
						( ( $group == self::DEFAULT_GROUP ) ? '' : "$group " ) . $wgContLang->formatNum( $label ) )
				)->inContentLanguage()->plain()
			);
	}

	/**
	 * This does approximately the same thing as
	 * Language::listToText() but due to this being used for a
	 * slightly different purpose (people might not want , as the
	 * first separator and not 'and' as the second, and this has to
	 * use messages from the content language) I'm rolling my own.
	 *
	 * @static
	 *
	 * @param array $arr The array to format
	 * @return string
	 */
	function listToText( $arr ) {
		$cnt = count( $arr );

		$sep = wfMessage( 'cite_references_link_many_sep' )->inContentLanguage()->plain();
		$and = wfMessage( 'cite_references_link_many_and' )->inContentLanguage()->plain();

		if ( $cnt == 1 ) {
			// Enforce always returning a string
			return (string)$arr[0];
		} else {
			$t = array_slice( $arr, 0, $cnt - 1 );
			return implode( $sep, $t ) . $and . $arr[$cnt - 1];
		}
	}

	/**
	 * Generate the labels to pass to the
	 * 'cite_references_link_many_format' message, the format is an
	 * arbitrary number of tokens separated by [\t\n ]
	 */
	function genBacklinkLabels() {
		$text = wfMessage( 'cite_references_link_many_format_backlink_labels' )
			->inContentLanguage()->plain();
		$this->mBacklinkLabels = preg_split( '#[\n\t ]#', $text );
	}

	/**
	 * Generate the labels to pass to the
	 * 'cite_reference_link' message instead of numbers, the format is an
	 * arbitrary number of tokens separated by [\t\n ]
	 *
	 * @param $group
	 * @param $message
	 */
	function genLinkLabels( $group, $message ) {
		$text = false;
		$msg = wfMessage( $message )->inContentLanguage();
		if ( $msg->exists() ) {
			$text = $msg->plain();
		}
		$this->mLinkLabels[$group] = ( !$text ) ? false : preg_split( '#[\n\t ]#', $text );
	}

	/**
	 * Gets run when Parser::clearState() gets run, since we don't
	 * want the counts to transcend pages and other instances
	 *
	 * @param $parser Parser
	 *
	 * @return bool
	 */
	function clearState( &$parser ) {
		if ( $parser->extCite !== $this ) {
			return $parser->extCite->clearState( $parser );
		}

		# Don't clear state when we're in the middle of parsing
		# a <ref> tag
		if ( $this->mInCite || $this->mInReferences ) {
			return true;
		}

		$this->mGroupCnt = array();
		$this->mOutCnt = 0;
		$this->mCallCnt = 0;
		$this->mRefs = array();
		$this->mReferencesErrors = array();

		return true;
	}

	/**
	 * Gets run when the parser is cloned.
	 *
	 * @param $parser Parser
	 *
	 * @return bool
	 */
	function cloneState( $parser ) {
		if ( $parser->extCite !== $this ) {
			return $parser->extCite->cloneState( $parser );
		}

		$parser->extCite = clone $this;
		$parser->setHook( 'ref', array( $parser->extCite, 'ref' ) );
		$parser->setHook( 'references', array( $parser->extCite, 'references' ) );

		// Clear the state, making sure it will actually work.
		$parser->extCite->mInCite = false;
		$parser->extCite->mInReferences = Cite::NOT_IN_REFERENCES;
		$parser->extCite->clearState( $parser );

		return true;
	}

	/**
	 * Called at the end of page processing to append an error if refs were
	 * used without a references tag.
	 *
	 * @param $parser Parser
	 * @param $text string
	 *
	 * @return bool
	 */
	function checkRefsNoReferences( &$parser, &$text ) {
		if ( $parser->extCite !== $this ) {
			return $parser->extCite->checkRefsNoReferences( $parser, $text );
		}

		if ( $parser->getOptions()->getIsSectionPreview() ) {
			return true;
		}

		foreach ( $this->mRefs as $group => $refs ) {
			if ( count( $refs ) == 0 ) {
				continue;
			}
			if ( $group == self::DEFAULT_GROUP ) {
				$text .= $this->referencesFormat( $group, '', '' );
			} else {
				$text .= "\n<br />" .
					$this->error( 'cite_error_group_refs_without_references', htmlspecialchars( $group ) );
			}
		}
		return true;
	}

	/**
	 * Hook for the InlineEditor extension.
	 * If any ref or reference reference tag is in the text,
	 * the entire page should be reparsed, so we return false in that case.
	 *
	 * @param $output
	 *
	 * @return bool
	 */
	function checkAnyCalls( &$output ) {
		global $wgParser;
		/* InlineEditor always uses $wgParser */
		return ( $wgParser->extCite->mCallCnt <= 0 );
	}

	/**
	 * Initialize the parser hooks
	 *
	 * @param $parser Parser
	 *
	 * @return bool
	 */
	static function setHooks( $parser ) {
		global $wgHooks;

		$parser->extCite = new self();

		if ( !Cite::$hooksInstalled ) {
			$wgHooks['ParserClearState'][] = array( $parser->extCite, 'clearState' );
			$wgHooks['ParserCloned'][] = array( $parser->extCite, 'cloneState' );
			$wgHooks['ParserAfterUnstrip'][] = array( $parser->extCite, 'checkRefsNoReferences' );
			$wgHooks['InlineEditorPartialAfterParse'][] = array( $parser->extCite, 'checkAnyCalls' );
			Cite::$hooksInstalled = true;
		}
		$parser->setHook( 'ref', array( $parser->extCite, 'ref' ) );
		$parser->setHook( 'references', array( $parser->extCite, 'references' ) );

		return true;
	}

	/**
	 * Return an error message based on an error ID
	 *
	 * @param string $key   Message name for the error
	 * @param string $param Parameter to pass to the message
	 * @param string $parse Whether to parse the message ('parse') or not ('noparse')
	 * @return string XHTML or wikitext ready for output
	 */
	function error( $key, $param = null, $parse = 'parse' ) {
		# We rely on the fact that PHP is okay with passing unused argu-
		# ments to functions.  If $1 is not used in the message, wfMessage will
		# just ignore the extra parameter.
		$msg = wfMessage( 'cite_error', wfMessage( $key, $param )->inContentLanguage()->plain() )
			->inContentLanguage()
			->plain();

		$ret = '<strong class="error mw-ext-cite-error">' . $msg . '</strong>';

		if ( $parse == 'parse' ) {
			$ret = $this->mParser->recursiveTagParse( $ret );
		}

		return $ret;
	}

	/**#@-*/
}
