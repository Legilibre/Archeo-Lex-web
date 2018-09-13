<?php

namespace GitList\Diff;

class Diff {

	static function ratcliff_obershelp( string $a, string $b, callable $keeplcs ) {

		#$t = microtime(true);

		$n = mb_strlen( $a );
		$m = mb_strlen( $b );
		$solver = new \Triun\LongestCommonSubstring\MatchesSolver();

		$queue = [ [ 0, $n, 0, $m ] ];
		$matching_blocks = [];
		$counter = 0;
		while( $queue ) {
			list( $alo, $ahi, $blo, $bhi ) = array_pop( $queue );
			$lcss = $solver->solve( mb_substr( $a, $alo, $ahi - $alo ), mb_substr( $b, $blo, $bhi - $blo ) );
			$lcs = $lcss->first();
			if( !$lcs || !$lcs->value ) {
				continue;
			}
			$i = $alo + $lcs->index( 0 );
			$j = $blo + $lcs->index( 1 );
			$k = $lcs->length;
			$length_lcs = $keeplcs( $i, $j, $k, $lcs, $a, $b, $n, $m );
			if( $length_lcs === false || $k <= 0 ) {
				continue;
			}
			$matching_blocks[] = [ $i, $j, $k ];
			if( $alo < $i && $blo < $j ) {
				$queue[] = [ $alo, $i, $blo, $j ];
			}
			if( $i + $k < $ahi && $j + $k < $bhi ) {
				$queue[] = [ $i + $k, $ahi, $j + $k, $bhi ];
			}

			$counter++;
			if( $counter > 100 ) {
				break;
			}
		}
		sort( $matching_blocks );

		$i1 = 0;
		$j1 = 0;
		$k1 = 0;
		$non_adjacent = [];
		foreach( $matching_blocks as $matching_block ) {

			list( $i2, $j2, $k2 ) = $matching_block;
			if( $i1 + $k1 == $i2 && $j1 + $k1 == $j2 ) {
				$k1 += $k2;
			} else {
				if( $k1 ) {
					$non_adjacent[] = [ $i1, $j1, $k1 ];
				}
				$i1 = $i2;
				$j1 = $j2;
				$k1 = $k2;
			}
		}
		if( $k1 ) {
			$non_adjacent[] = [ $i1, $j1, $k1 ];
		}
		$non_adjacent[] = [ $n, $m, 0 ];

		return $non_adjacent;
	}

	static function opcodes_from_matching_blocks( $matching_blocks ) {

		$i = 0;
		$j = 0;
		$answer = [];
		foreach( $matching_blocks as $matching_block ) {

			$tag = '';
			list( $ai, $bj, $size ) = $matching_block;
			if( $i < $ai && $j < $bj ) {
				$tag = 'replace';
			} elseif( $i < $ai ) {
				$tag = 'delete';
			} elseif( $j < $bj ) {
				$tag = 'insert';
			}
			if( $tag ) {
				$answer[] = [ $tag, $i, $ai, $j, $bj ];
			}
			$i = $ai + $size;
			$j = $bj + $size;
			if( $size ) {
				$answer[] = [ 'equal', $ai, $i, $bj, $j ];
			}
		}

		return $answer;
	}

	/**
	 * Test function if a LCS should be kept, and shorten this LCS if some cases.
	 *
	 * The LCS is shortened if the resulting diff is at the middle of a word, which means we
	 * check the character just before the LCS in the two version, we compare with the first
	 * character of the LCS and if one or the other resulting diff is in the middle of a word,
	 * we shorten the LCS until this situation disappear. Similar for the end of the LCS.
	 *
	 * This is not applied in some cases: the treatment on the beginning of the LCS is not
	 * applied if we are at the beginning of either text (similar for the end).
	 *
	 * If the resulting LCS is too short (4 characters) it is no more considered as a LCS,
	 * except if it is at the beginning or end of either text, or if it contains a newline.
	 *
	 * @param int $i Index of the LCS in the first text; this parameter can be modified.
	 * @param int $j Index of the LCS in the second text; this parameter can be modified.
	 * @param int $k Length of the LCS; this parameter can be modified.
	 * @param object $lcs LCS.
	 * @param string $a First text.
	 * @param string $b Second text.
	 * @param int $n Length of the first text.
	 * @param int $m Length of the second text.
	 * @return boolean Keep this LCS.
	 */
	static function keep_lcs_words( &$i, &$j, &$k, $lcs, $a, $b, $n, $m ) {

		# LCS matching this condition are guaranteed to be kept. Possibly they will be shortened, except if it would result in removing them.
		# This veto is in the following cases:
		# - LCS containing a newline
		# - LCS at the beginning or at the end of the text
		$veto_keep = ( strpos( $lcs->value, "\n" ) !== false || $i == 0 || $j == 0 || $i+$k == $n || $j+$k == $m );

		# Minimum length of the LCS, except if vetoed by the condition above
		$minimum_length = 5;

		# If the LCS is too small, don’t consider it is a valuable LCS, except if vetoed
		if( $k <= $minimum_length && !$veto_keep ) {
			return false;
		}

		$l0 = 0;
		$k0 = $k;
		$lcs0 = $lcs->value;

		$two_letters = '/[a-záàâäéèêëíìîïóòôöøœúùûüýỳŷÿ0-9\']{2}/iu';

		# If the LCS starts in the middle of a word, remove these characters from the LCS
		if( $i > 0 && $j > 0 ) {
			$strA = mb_substr( $a, $i-1, $k0+1 );
			$strB = mb_substr( $b, $j-1, $k0+1 );
			while( $k0 && ( preg_match( $two_letters, mb_substr( $strA, $l0, 2 ) ) || preg_match( $two_letters, mb_substr( $strB, $l0, 2 ) ) ) ) {
				$k0--;
				$l0++;
			}
		}

		# If the LCS ends in the middle of a word, remove these characters from the LCS
		if( $i+$k < $n && $j+$k < $m ) {
			$strA = mb_substr( $a, $i, $l0+$k0+1 );
			$strB = mb_substr( $b, $j, $l0+$k0+1 );
			while( $k0 && ( preg_match( $two_letters, mb_substr( $strA, $l0+$k0-1, 2 ) ) || preg_match( $two_letters, mb_substr( $strB, $l0+$k0-1, 2 ) ) ) ) {
				$k0--;
			}
		}

		# If the LCS is too small, don’t consider it is a valuable LCS, except if vetoed
		if( $k0 <= $minimum_length && !$veto_keep ) {
			return false;
		}

		$i += $l0;
		$j += $l0;
		$k = $k0;

		return true;
	}

	/**
	 * Transform a list of opcodes into a real diff.
	 *
	 * @param string $a First text.
	 * @param string $b Second text.
	 * @param array $opcodes List of opcodes.
	 * @param string $style Style, one of values: 'newline' (for CLI), 'color' (for CLI), 'arrayline' (structured).
	 * @param int|false $maxcolumn Number of columns for style newline.
	 */
	static function print_diff_opcodes( $a, $b, $opcodes, $style = 'newline', $maxcolumn = false ) {

		$pre_equal = '';
		$post_equal = '';
		$pre_delete = '';
		$post_delete = '';
		$pre_insert = '';
		$post_insert = '';
		$pre_replace_delete = '';
		$post_replace_delete = '';
		$pre_replace_insert = '';
		$post_replace_insert = '';
		if( $style == 'arrayline' ) {
			$pre_equal = 'equal';
			$post_equal = '';
			$pre_delete = 'delete';
			$post_delete = '';
			$pre_insert = 'insert';
			$post_insert = '';
			$pre_replace_delete = 'delete';
			$post_replace_delete = '';
			$pre_replace_insert = 'insert';
			$post_replace_insert = '';
		} elseif( $style == 'newline' ) {
			$pre_equal = ' ';
			$post_equal = '';
			$pre_delete = '-';
			$post_delete = '-';
			$pre_insert = '+';
			$post_insert = '+';
			$pre_replace_delete = '-';
			$post_replace_delete = '-';
			$pre_replace_insert = '+';
			$post_replace_insert = '+';
		} elseif( $style == 'color' ) {
			$pre_equal = '';
			$post_equal = '';
			$pre_delete = "\033[4;31m";
			$post_delete = "\033[0m";
			$pre_insert = "\033[4;32m";
			$post_insert = "\033[0m";
			$pre_replace_delete = "\033[4;31m";
			$post_replace_delete = "\033[0m";
			$pre_replace_insert = "\033[4;32m";
			$post_replace_insert = "\033[0m";
		}

		if( $style == 'arrayline' ) {
			$lines = [];
			$current_line = 0;
			$current_lineA = $a ? 0 : null;
			$current_lineB = $b ? 0 : null;
			$current_block = -1;
		}

		foreach( $opcodes as $opcode ) {

			list( $tag, $alo, $ahi, $blo, $bhi ) = $opcode;
			$ops_diff = [];
			if( $tag == 'equal' ) {
				$ops_diff = [ [ $pre_equal, mb_substr( $a, $alo, $ahi - $alo ), $post_equal ] ];
			} elseif( $tag == 'delete' ) {
				$ops_diff = [ [ $pre_delete, mb_substr( $a, $alo, $ahi - $alo ), $post_delete ] ];
			} elseif( $tag == 'insert' ) {
				$ops_diff = [ [ $pre_insert, mb_substr( $b, $blo, $bhi - $blo ), $post_insert ] ];
			} elseif( $tag == 'replace' ) {
				$ops_diff = [ [ $pre_replace_delete, mb_substr( $a, $alo, $ahi - $alo ), $post_replace_delete ],
					[ $pre_replace_insert, mb_substr( $b, $blo, $bhi - $blo ), $post_replace_insert ] ];
			} else {
				throw new Exception();
			}

			if( $style == 'arrayline' ) {
				foreach( $ops_diff as $op_diff ) {
					$current_block++;
					$lineA = in_array( $op_diff[0], [ 'equal', 'replace', 'delete' ] ) ? $current_lineA : null;
					$lineB = in_array( $op_diff[0], [ 'equal', 'replace', 'insert' ] ) ? $current_lineB : null;
					$lines[$current_line][$current_block] = [ 'type' => $op_diff[0], 'lineA' => $lineA, 'lineB' => $lineB, 'text' => '' ];
					for( $i=0; $i<mb_strlen($op_diff[1]); $i++ ) {
						$character = mb_substr( $op_diff[1], $i, 1 );
						if( $character == "\n" ) {
							$current_line++;
							$current_block = 0;
							$current_lineA += in_array( $op_diff[0], [ 'equal', 'replace', 'delete' ] ) ? 1 : 0;
							$current_lineB += in_array( $op_diff[0], [ 'equal', 'replace', 'insert' ] ) ? 1 : 0;
							$lineA = in_array( $op_diff[0], [ 'equal', 'replace', 'delete' ] ) ? $current_lineA : null;
							$lineB = in_array( $op_diff[0], [ 'equal', 'replace', 'insert' ] ) ? $current_lineB : null;
							$lines[$current_line] = [ [ 'type' => $op_diff[0], 'lineA' => $lineA, 'lineB' => $lineB, 'text' => '' ] ];
						} else {
							$lines[$current_line][$current_block]['text'] .= $character;
						}
					}
				}
			} elseif( $style == 'newline' ) {
				foreach( $ops_diff as $op_diff ) {
					echo $op_diff[0];
					$column = 0;
					$width_around = mb_strwidth( $op_diff[0] ) + mb_strwidth( $op_diff[2] );
					for( $i=0; $i<mb_strlen($op_diff[1]); $i++ ) {
						$character = mb_substr( $op_diff[1], $i, 1 );
						$width = mb_strwidth( $character );
						if( $character == "\n" || ( is_int( $maxcolumn ) && $column + $width + $width_around > $maxcolumn ) ) {
							echo $op_diff[2] . "\n" . $op_diff[0];
							$column = 0;
						}
						if( $character != "\n" ) {
							echo $character;
							$column += $width;
						}
					}
					echo $op_diff[2] . "\n";
				}
			} elseif( $style == 'color' ) {
				foreach( $ops_diff as $op_diff ) {
					echo $op_diff[0] . $op_diff[1] . $op_diff[2];
				}
			}
		}

		if( $style == 'arrayline' ) {
			return $lines;
		}
	}
}
