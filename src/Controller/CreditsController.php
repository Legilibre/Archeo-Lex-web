<?php

namespace GitList\Controller;

use GitList\Git\Repository;
use Gitter\Model\Commit\Commit;
use Silex\ControllerProviderInterface;
use Silex\Application;

class CreditsController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $route->get(
            '/codes/credits',
            function () use ($app) {
                return $app['twig']->render(
                    'credits.twig',
                    array(
                    )
                );
            }
        )
        ->bind('credits');

        return $route;
    }
}
