<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\Routing\Loader;

use FOS\RestBundle\Routing\Loader\Reader\RestControllerReader;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RestRouteLoader REST-enabled controller router loader.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 */
class RestRouteLoader extends Loader
{
    protected $container;
    protected $controllerReader;
    protected $defaultFormat;
    protected $locator;

    public function __construct(
        ContainerInterface $container,
        FileLocatorInterface $locator,
        RestControllerReader $controllerReader,
        ?string $defaultFormat = 'html'
    ) {
        $this->container = $container;
        $this->locator = $locator;
        $this->controllerReader = $controllerReader;
        $this->defaultFormat = $defaultFormat;
    }

    /**
     * @return RestControllerReader
     */
    public function getControllerReader()
    {
        return $this->controllerReader;
    }

    /**
     * {@inheritdoc}
     */
    public function load($controller, $type = null)
    {
        list($prefix, $class) = $this->getControllerLocator($controller);

        $collection = $this->controllerReader->read(new \ReflectionClass($class));
        $collection->prependRouteControllersWithPrefix($prefix);
        $collection->setDefaultFormat($this->defaultFormat);

        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, $type = null)
    {
        return is_string($resource)
            && 'rest' === $type
            && !in_array(
                pathinfo($resource, PATHINFO_EXTENSION),
                ['xml', 'yml', 'yaml']
            );
    }

    private function getControllerLocator(string $controller): array
    {
        $class = null;
        $prefix = null;

        if (0 === strpos($controller, '@')) {
            $file = $this->locator->locate($controller);
            $controllerClass = ClassUtils::findClassInFile($file);

            if (null === $controllerClass) {
                throw new \InvalidArgumentException(sprintf('Can\'t find class for controller "%s"', $controller));
            }

            $controller = $controllerClass;
        }

        if ($this->container->has($controller)) {
            // service_id
            $prefix = $controller.'::';

            $class = get_class($this->container->get($controller));
        } elseif (class_exists($controller)) {
            // full class name
            $class = $controller;
            $prefix = $class.'::';
        }

        if (empty($class)) {
            throw new \InvalidArgumentException(sprintf('Class could not be determined for Controller identified by "%s".', $controller));
        }

        return [$prefix, $class];
    }
}
