<?php

namespace GitList\Controller;

use Silex\ControllerProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class CommitController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $repos = $app['util.routing']->getRepositoryRegex();
        $repos = $repos . '|' . preg_replace('/\\\.git/', '(\\.git)?', $repos);

        $route->get('{repo}/commits/search', function (Request $request, $repo) use ($app) {
            $subRequest = Request::create(
                '/' . $repo . '/commits/master/search',
                'POST',
                array('query' => $request->get('query'))
            );

            return $app->handle($subRequest, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        })->assert('repo', $app['util.routing']->getRepositoryRegex());

        $route->get('{repo}/commits/{commitishPath}', function (Request $request, $repo, $commitishPath) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            if ($commitishPath === null) {
                $commitishPath = $repository->getHead();
            }

            list($branch, $file) = $app['util.routing']
                ->parseCommitishPathParam($commitishPath, $repo);

            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $type = $file ? "$branch -- \"$file\"" : $branch;
            $pager = $app['util.view']->getPager($request->get('page'), $repository->getTotalCommits($type));
            $commits = $repository->getPaginatedCommits($type, $pager['current']);
            $categorized = array();

            foreach ($commits as $commit) {
                $date = $commit->getCommiterDate();
                $date = $date->format('Y-m-d');
                $categorized[$date][] = $commit;
            }

            $template = $request->isXmlHttpRequest() ? 'commits_list.twig' : 'commits.twig';

            return $app['twig']->render($template, array(
                'page' => 'commits',
                'pager' => $pager,
                'repo' => $repo,
                'branch' => $branch,
                'branches' => $repository->getBranches(),
                'tags' => $repository->getTags(),
                'commits' => $categorized,
                'file' => $file,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('commitishPath', $app['util.routing']->getCommitishPathRegex())
          ->value('commitishPath', null)
          ->convert('commitishPath', 'escaper.argument:escape')
          ->bind('commits');

        $route->post('{repo}/commits/{branch}/search', function (Request $request, $repo, $branch = '') use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            $query = $request->get('query');

            $commits = $repository->searchCommitLog($query, $branch);
            $categorized = array();

            foreach ($commits as $commit) {
                $date = $commit->getCommiterDate();
                $date = $date->format('Y-m-d');
                $categorized[$date][] = $commit;
            }

            return $app['twig']->render('searchcommits.twig', array(
                'repo' => $repo,
                'branch' => $branch,
                'file' => '',
                'commits' => $categorized,
                'branches' => $repository->getBranches(),
                'tags' => $repository->getTags(),
                'query' => $query,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', $app['util.routing']->getBranchRegex())
          ->convert('branch', 'escaper.argument:escape')
          ->bind('searchcommits');

        $route->get('{repo}/commit/{commit}', $commitController = function ($repo, $commit) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            $commit = $repository->getCommit($commit);
            $branch = $repository->getHead();

            return $app['twig']->render('commit.twig', array(
                'branch' => $branch,
                'repo' => $repo,
                'commit' => $commit,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('commit', '[a-f0-9^]+')
          ->bind('commit');

        $route->get('{repo}/blame/{commitishPath}', $blameController = function ($repo, $commitishPath) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            list($branch, $file) = $app['util.routing']
                ->parseCommitishPathParam($commitishPath, $repo);

            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);
            $commit = $repository->getCommit(substr($commitishPath, 0, 40));

            $blames = $repository->getBlame("$branch -- \"$file\"");

            return $app['twig']->render('blame.twig', array(
                'file' => $file,
                'repo' => $repo,
                'branch' => $branch,
                'branches' => $repository->getBranches(),
                'commit' => $commit,
                'tags' => $repository->getTags(),
                'blames' => $blames,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('commitishPath', $app['util.routing']->getCommitishPathRegex())
          ->convert('commitishPath', 'escaper.argument:escape')
          ->bind('blame');

        // Commit with date
        $route->get('{repo}/{version}/commit', function ($repo, $version) use ($app, $commitController) {
            if (substr($repo,-4) != '.git') {
                $repo .= '.git';
            }
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            $commitishPath = $repository->getHead();
            list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $type = $file ? "$branch -- \"$file\"" : $branch;
            $pager = $app['util.view']->getPager($app['request']->get('page'), $repository->getTotalCommits($type));
            $commits = $repository->getPaginatedCommits($type, $pager['current']);
            $categorized = array();

            foreach ($commits as $commit) {
                $date = $commit->getDate();
                $date = $date->format('Y-m-d');
                $categorized[$date][] = $commit;
            }
            $commitishPath = $categorized[$version][0]->getHash();

            return $commitController( $repo, $commitishPath );
        })->assert('repo', $repos)
          ->assert('version', '\d{4}-\d{2}-\d{2}')
          ->bind('rawcommitversion');

        // Commit with date
        $route->get('{repo}/{version}/modifications', function ($repo, $version) use ($app) {
            if (substr($repo,-4) != '.git') {
                $repo .= '.git';
            }
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            $commitishPath = $repository->getHead();
            list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $type = $file ? "$branch -- \"$file\"" : $branch;
            $pager = $app['util.view']->getPager($app['request']->get('page'), $repository->getTotalCommits($type));
            $commits = $repository->getPaginatedCommits($type, $pager['current']);
            $categorized = array();

            foreach ($commits as $commit) {
                $date = $commit->getDate();
                $date = $date->format('Y-m-d');
                $categorized[$date][] = $commit;
            }
            $commitishPath = $categorized[$version][0]->getHash();
            $textA = $repository->getContentCommit( $commitishPath.'~1', mb_substr( $repo, 0, -4 ) . '.md' );
            $textB = $repository->getContentCommit( $commitishPath, mb_substr( $repo, 0, -4 ) . '.md' );
            $commit = $repository->getCommit($commitishPath);
            $branch = $repository->getHead();
            $articlesA = \GitList\Diff\LawMarkdownArticles::split_articles( $textA );
            $articlesB = \GitList\Diff\LawMarkdownArticles::split_articles( $textB );
            $articles = \GitList\Diff\LawMarkdownArticles::compare_articles( $articlesA, $articlesB );
            $rawArticles = [];
            $linesA = explode( "\n", $textA );
            $linesB = explode( "\n", $textB );
            foreach( $articles as $article ) {
                if( $article[0] == 'delete' ) {
                    preg_match( '/^#+ Article .*$/m', substr( $textA, $article[1], 100 ), $titleArticle );
                    $lineNumber = array_search( $titleArticle[0], $linesA );
                    $lines = [];
                    foreach( explode( "\n", $article[3] ) as $i => $line ) {
                        $lines[] = [ 'oldnb' => $lineNumber + $i + 1, 'text' => $line ];
                    }
                    $lines[0] = [ 'oldnb' => $lineNumber + 1, 'text' => $titleArticle[0] ];
                    $rawArticles[] = [ 'type' => 'delete', 'text' => $article[3], 'lines' => $lines ];
                } elseif( $article[0] == 'insert' ) {
                    preg_match( '/^#+ Article .*$/m', substr( $textB, $article[2], 100 ), $titleArticle );
                    $lineNumber = array_search( $titleArticle[0], $linesB );
                    $lines = [];
                    foreach( explode( "\n", $article[4] ) as $i => $line ) {
                        $lines[] = [ 'newnb' => $lineNumber + $i + 1, 'text' => $line ];
                    }
                    $lines[0] = [ 'newnb' => $lineNumber + 1, 'text' => $titleArticle[0] ];
                    $rawArticles[] = [ 'type' => 'insert', 'text' => $article[4], 'lines' => $lines ];
                } elseif( $article[0] == 'replace' ) {
                    preg_match( '/^#+ Article .*$/m', substr( $textA, $article[1], 100 ), $titleArticleA );
                    preg_match( '/^#+ Article .*$/m', substr( $textB, $article[2], 100 ), $titleArticleB );
                    $lineNumberA = array_search( $titleArticleA[0], $linesA );
                    $lineNumberB = array_search( $titleArticleB[0], $linesB );
                    $hashHeaderA = preg_replace( '/^(#+ Article ).*$/', '$1', $titleArticleA[0] );
                    $hashHeaderB = preg_replace( '/^(#+ Article ).*$/', '$1', $titleArticleB[0] );
                    #var_dump($hashHeaderA);
                    #ob_get_clean();
                    $matching_blocks = \GitList\Diff\Diff::ratcliff_obershelp( $hashHeaderA . $article[3], $hashHeaderB . $article[4], '\GitList\Diff\Diff::keep_lcs_words' );
                    #var_dump( $matching_blocks );
                    $opcodes = \GitList\Diff\Diff::opcodes_from_matching_blocks( $matching_blocks );
                    #var_dump( $opcodes );
                    $lines = \GitList\Diff\Diff::print_diff_opcodes( $hashHeaderA . $article[3], $hashHeaderB . $article[4], $opcodes, 'arrayline' );
                    #var_dump( $hashHeaderA . $article[3] );
                    #if( $titleArticleA[0] == '#### Article 229' ) {
                    #    var_dump( $lines );
                    #}
                    foreach( $lines as $i => $line ) {
                        if( count( $line ) > 1 ) {
                            foreach( $line as $j => $block ) {
                                if( !$block['text'] ) {
                                    unset( $lines[$i][$j] );
                                    continue;
                                }
                            }
                            $lines[$i] = array_values( $lines[$i] );
                        }
                    }
                    foreach( $lines as $i => $line ) {
                        if( count( $line ) == 2 ) {
                            if( ( $line[0]['type'] == 'delete' && $line[1]['type'] == 'insert' ) || ( $line[0]['type'] == 'insert' && $line[1]['type'] == 'delete' ) ) {
                                array_splice( $lines, $i, 1, [ [ $line[0] ], [ $line[1] ] ] );
                                #$lines[$i+0.5] = [ $line[1] ];
                                #$lines[$i] = [ $line[0] ];
                            }
                        }
                    }
                    $lines = array_values( $lines );
                    foreach( $lines as $i => $line ) {
                        foreach( $line as $j => $block ) {
                            if( !is_null( $block['lineA'] ) ) {
                                $lines[$i][$j]['lineA'] += $lineNumberA + 1;
                                $lines[$i][0]['lineA'] = $lines[$i][$j]['lineA'];
                            }
                            if( !is_null( $block['lineB'] ) ) {
                                $lines[$i][$j]['lineB'] += $lineNumberB + 1;
                                $lines[$i][0]['lineB'] = $lines[$i][$j]['lineB'];
                            }
                        }
                    }
                    #var_dump( $lines );
                    $rawArticles[] = [ 'type' => 'replace', 'lines' => $lines ];
                }
            }
            #var_dump( $rawArticles );
            
#	var_dump($commitishPath->getDiffs());
            $diff = [];

            return $app['twig']->render('commitlex.twig', array(
                'branch' => $branch,
                'repo' => $repo,
                'difflex' => $rawArticles,
                'commit' => $commit,
            ));
            #return $commitController( $repo, $commitishPath );
        })->assert('repo', $repos)
          ->assert('version', '\d{4}-\d{2}-\d{2}')
          ->bind('commitversion');

        // Blame with date
        $route->get('{repo}/{version}/annotations', function ($repo, $version) use ($app, $blameController) {
            if (substr($repo,-4) != '.git') {
                $repo .= '.git';
            }
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            $commitishPath = $repository->getHead();
            list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $type = $file ? "$branch -- \"$file\"" : $branch;
            $pager = $app['util.view']->getPager($app['request']->get('page'), $repository->getTotalCommits($type));
            $commits = $repository->getPaginatedCommits($type, $pager['current']);

            $commitishPath = $commits[0]->getHash() .
                '/' . lcfirst(preg_replace(array('/codes\//','/\.git/','/-/'), array('','.md','_'), $repo));

            return $blameController( $repo, $commitishPath );
        })->assert('repo', $repos)
          ->assert('version', '\d{4}-\d{2}-\d{2}')
          ->bind('blameversion');


        return $route;
    }
}

# vim: set ts=4 sw=4 sts=4 et:
