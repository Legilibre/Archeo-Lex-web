<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ArcheolexController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $repos = $app['util.routing']->getRepositoryRegex();
        $repos = $repos . '|' . preg_replace('/\\\.git/', '(\\.git)?', $repos);

        // List commits
        $route->get('{date}/{repo}', $summaryController = function ($date, $repo) use ($app) {
            
            if (substr($repo,-4) != '.git') {
                $repo .= '.git';
            }
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            $commitishPath = $date;
            if ($commitishPath === null) {
                $commitishPath = $repository->getHead();
            }

            list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $type = $file ? "$branch -- \"$file\"" : $branch;
            $pager = $app['util.view']->getPager($app['request']->get('page'), $repository->getTotalCommits($type));
            $commits = $repository->getPaginatedCommits($type, $pager['current']);

            $template = $app['request']->isXmlHttpRequest() ? 'commits_list.twig' : 'archeolex.twig';

            $files = $repository->getTree($file ? "$branch:\"$file\"/" : $branch);
            #$breadcrumbs = $app['util.view']->getBreadcrumbs($files);

            $stats = $repository->getStatistics($branch);
            $authors = $repository->getAuthorStatistics($branch);

            return $app['twig']->render($template, array(
                'page'           => 'commits',
                'pager'          => $pager,
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
#                'breadcrumbs'    => $breadcrumbs,
                'tags'           => $repository->getTags(),
                'path'           => $file ? $file . '/' : $file,
                'commits'        => $commits,
                'stats'          => $stats,
                'authors'        => $authors,
                'file'           => $file,
                'files'          => $files->output(),
            ));
        })->assert('date','\d{4}-\d{2}-\d{2}')
          ->assert('repo', $repos)
          ->value('date', null)
          ->bind('summary');

        // List commits
        $route->get('{repo}', function ($repo) use ($app, $summaryController) {
            return $summaryController(null,$repo);
        })->assert('repo', $repos)
          ->bind('summaryrepo');

        // Blob
        $route->get('{repo}/{commitishPath}', $blobVersionController = function ($repo, $commitishPath) use ($app) {
            if (substr($repo,-4) != '.git') {
                $repo .= '.git';
            }
            $commitishPath .= '/' . lcfirst(preg_replace(array('/codes\//','/\.git/'), array('','.md'), $repo));
            //$request = $app['request'];
            //$subRequest = Request::create('/' . 'gitlist' . '/' . $repo . '/' . 'commit' . '/' . $commitishPath, 'GET', array(), $request->cookies->all(), array(), $request->server->all());
            //return $response = $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
            //$app['routes']->get($repo . '/' . $commitishPath)->run();
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);
            $commit = $repository->getCommit(substr($commitishPath, 0, 40));

            $blob = $repository->getBlob("$branch:\"$file\"");

            try {
                $blob->getOutput();
            } catch( \Throwable $e ) {
                $commitishPath = str_replace( '-', '_', $commitishPath ); // Try with _ instead of -
                list($branch, $file) = $app['util.routing']->parseCommitishPathParam($commitishPath, $repo);
                list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);
                $blob = $repository->getBlob("$branch:\"$file\"");
            }

            $breadcrumbs = $app['util.view']->getBreadcrumbs($file);
            $fileType = $app['util.repository']->getFileType($file);

            if ($fileType !== 'image' && $app['util.repository']->isBinary($file)) {
                return $app->redirect($app['url_generator']->generate('blob_raw', array(
                    'repo'   => $repo,
                    'commitishPath' => $commitishPath,
                )));
            }

            return $app['twig']->render('file.twig', array(
                'file'           => $file,
                'fileType'       => $fileType,
                'blob'           => $blob->output(),
                'repo'           => $repo,
                'branch'         => $branch,
                'commit'         => $commit,
                'breadcrumbs'    => $breadcrumbs,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
            ));
        })->assert('repo', $repos)
          ->assert('commitishPath', '[0-9a-fA-F]{40}')
          ->bind('versioncommit');

        // Blob
        $route->get('{repo}/{version}', function ($repo, $version) use ($app, $blobVersionController) {
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

            return $blobVersionController( $repo, $commits[0]->getHash() );
        })->assert('repo', $repos)
          ->assert('version', '\d{4}-\d{2}-\d{2}')
          ->bind('version');

        return $route;
    }
}

