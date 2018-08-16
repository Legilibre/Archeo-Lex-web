<?php

namespace GitList\Controller;

use Silex\ControllerProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\Response;

class BlobController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $repos = $app['util.routing']->getRepositoryRegex();
        $repos = $repos . '|' . preg_replace('/\\\.git/', '(\\.git)?', $repos);

        $route->get('{repo}/blob/{commitishPath}', function ($repo, $commitishPath) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            list($branch, $file) = $app['util.routing']
                ->parseCommitishPathParam($commitishPath, $repo);

            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $blob = $repository->getBlob("$branch:\"$file\"");
            $breadcrumbs = $app['util.view']->getBreadcrumbs($file);
            $fileType = $app['util.repository']->getFileType($file);

            if ($fileType !== 'image' && $app['util.repository']->isBinary($file)) {
                return $app->redirect($app['url_generator']->generate('blob_raw', array(
                    'repo' => $repo,
                    'commitishPath' => $commitishPath,
                )));
            }

            return $app['twig']->render('file.twig', array(
                'file' => $file,
                'fileType' => $fileType,
                'blob' => $blob->output(),
                'repo' => $repo,
                'branch' => $branch,
                'breadcrumbs' => $breadcrumbs,
                'branches' => $repository->getBranches(),
                'tags' => $repository->getTags(),
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('commitishPath', '.+')
          ->convert('commitishPath', 'escaper.argument:escape')
          ->bind('blob');

        $route->get('{repo}/raw/{commitishPath}', $rawVersionController = function ($repo, $commitishPath) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            list($branch, $file) = $app['util.routing']
                ->parseCommitishPathParam($commitishPath, $repo);

            list($branch, $file) = $app['util.repository']->extractRef($repository, $branch, $file);

            $blob = $repository->getBlob("$branch:\"$file\"")->output();

            $headers = array();
            if ($app['util.repository']->isBinary($file)) {
                $headers['Content-Disposition'] = 'attachment; filename="' . $file . '"';
                $headers['Content-Type'] = 'application/octet-stream';
            } else {
                $headers['Content-Type'] = 'text/plain';
            }

            return new Response($blob, 200, $headers);
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('commitishPath', $app['util.routing']->getCommitishPathRegex())
          ->convert('commitishPath', 'escaper.argument:escape')
          ->bind('blob_raw');

        $route->get('{repo}/logpatch/{commitishPath}', function ($repo, $commitishPath) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            list($branch, $file) = $app['util.routing']
                ->parseCommitishPathParam($commitishPath, $repo);

            $filePatchesLog = $repository->getCommitsLogPatch($file);
            $breadcrumbs = $app['util.view']->getBreadcrumbs($file);

            return $app['twig']->render('logpatch.twig', array(
                'branch' => $branch,
                'repo' => $repo,
                'breadcrumbs' => $breadcrumbs,
                'commits' => $filePatchesLog,
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->assert('commitishPath', '.+')
            ->convert('commitishPath', 'escaper.argument:escape')
            ->bind('logpatch');

        // Raw with date
        $route->get('{repo}/{version}/brut', function ($repo, $version) use ($app, $rawVersionController) {
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
            $commitishPath = $categorized[$version][0]->getHash() .
                '/' . ucfirst(preg_replace(array('/codes\//','/\.git/','/é/', '/-/'), array('','.md','e', '_'), $repo));

            return $rawVersionController( $repo, $commitishPath );
        })->assert('repo', $repos)
          ->assert('version', '\d{4}-\d{2}-\d{2}')
          ->bind('rawversion');

        return $route;
    }
}
