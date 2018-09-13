<?php

namespace GitList\Diff;

class LawMarkdownArticles {

	static function split_articles( $text ) {

		$pre_tokens = preg_split( "/\n\n(#+ Article )/", $text, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_OFFSET_CAPTURE );

		$tokens = [];
		if( preg_match( '/^#+ Article /', $pre_tokens[0][0] ) ) {
			$tokens[0] = substr( $pre_tokens[0][0], strpos( $pre_tokens[0][0], ' ' ) + 9 );
		}

		for( $i=1; $i<count($pre_tokens); $i+=2 ) {
			$tokens[$pre_tokens[$i][1]] = preg_replace( "/\n\n#+ .*/", '', $pre_tokens[$i+1][0] );
		}

		return $tokens;
	}

	static function compare_articles( $articles_a, $articles_b ) {

		$articles = [];
		$titles = [];
		foreach( array_diff( $articles_a, $articles_b ) as $offset => $article ) {
			$titles[] = substr( $article, 0, strpos( $article, "\n" ) );
			$articles[] = [ 'delete', $offset, null, $article, '' ];
		}
		foreach( array_diff( $articles_b, $articles_a ) as $offset => $article ) {
			$title = substr( $article, 0, strpos( $article, "\n" ) );
			$k = array_search( $title, $titles );
			if( $k !== false ) {
				$articles[] = [ 'replace', $articles[$k][1], $offset, $articles[$k][3], $article ];
				unset( $titles[$k] );
				unset( $articles[$k] );
			} else {
				$articles[] = [ 'insert', null, $offset, '', $article ];
			}
		}
		foreach( array_intersect( $articles_a, $articles_b ) as $offset_a => $article ) {
			$offset_b = array_search( $article, $articles_b );
			$articles[] = [ 'equal', $offset_a, $offset_b, $article, $article ];
		}

		usort( $articles, function( $a, $b ) {
			if( $a == $b ) {
				return 0;
			} elseif( in_array( $a[0], [ 'equal', 'replace', 'delete' ] ) and in_array( $b[0], [ 'equal', 'replace', 'delete' ] ) ) {
				return $a[1] < $b[1] ? -1 : 1;
			} elseif( in_array( $a[0], [ 'equal', 'replace', 'insert' ] ) and in_array( $b[0], [ 'equal', 'replace', 'insert' ] ) ) {
				return $a[2] < $b[2] ? -1 : 1;
			}
			return $a[0] == 'delete' && $b[0] == 'insert' ? -1 : 1;
		});

		return $articles;
	}
}
