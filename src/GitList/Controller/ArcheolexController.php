<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;

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
            $categorized = array();

            foreach ($commits as $commit) {
                $date = $commit->getCommiterDate();
                $date = $date->format('Y-m-d');
                $categorized[$date][] = $commit;
            }

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
                'commits'        => $categorized,
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

        return $route;
    }
}

