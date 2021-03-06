<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\View;

use FOS\RestBundle\Context\Context;
use FOS\RestBundle\Serializer\Serializer;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * View may be used in controllers to build up a response in a format agnostic way
 * The View class takes care of encoding your data in json, xml via the Serializer
 * component.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Lukas K. Smith <smith@pooteeweet.org>
 */
final class ViewHandler implements ConfigurableViewHandlerInterface
{
    /**
     * Key format, value a callable that returns a Response instance.
     *
     * @var array
     */
    private $customHandlers = [];

    /**
     * The supported formats as keys.
     *
     * @var array
     */
    private $formats;

    /**
     * @var int
     */
    private $failedValidationCode;

    /**
     * @var int
     */
    private $emptyContentCode;

    /**
     * @var bool
     */
    private $serializeNull;

    /**
     * If to force a redirect for the given key format,
     * with value being the status code to use.
     *
     * @var array<string,int>
     */
    private $forceRedirects;

    /**
     * @var string|null
     */
    private $defaultEngine;

    /**
     * @var array
     */
    private $exclusionStrategyGroups = [];

    /**
     * @var string
     */
    private $exclusionStrategyVersion;

    /**
     * @var bool
     */
    private $serializeNullStrategy;

    private $urlGenerator;
    private $serializer;
    private $requestStack;

    private $options;

    private function __construct(
        UrlGeneratorInterface $urlGenerator,
        Serializer $serializer,
        RequestStack $requestStack,
        array $formats = null,
        int $failedValidationCode = Response::HTTP_BAD_REQUEST,
        int $emptyContentCode = Response::HTTP_NO_CONTENT,
        bool $serializeNull = false,
        array $forceRedirects = null,
        array $options = []
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->serializer = $serializer;
        $this->requestStack = $requestStack;
        $this->formats = (array) $formats;
        $this->failedValidationCode = $failedValidationCode;
        $this->emptyContentCode = $emptyContentCode;
        $this->serializeNull = $serializeNull;
        $this->forceRedirects = (array) $forceRedirects;
        $this->options = $options + [
            'exclusionStrategyGroups' => [],
            'exclusionStrategyVersion' => null,
            'serializeNullStrategy' => null,
            ];
        $this->reset();
    }

    public static function create(
        UrlGeneratorInterface $urlGenerator,
        Serializer $serializer,
        RequestStack $requestStack,
        array $formats = null,
        int $failedValidationCode = Response::HTTP_BAD_REQUEST,
        int $emptyContentCode = Response::HTTP_NO_CONTENT,
        bool $serializeNull = false,
        array $options = []
    ): self
    {
        return new self($urlGenerator, $serializer, $requestStack, $formats, $failedValidationCode, $emptyContentCode, $serializeNull, [], $options, false);
    }

    /**
     * @param string[]|string $groups
     */
    public function setExclusionStrategyGroups($groups)
    {
        $this->exclusionStrategyGroups = (array) $groups;
    }

    /**
     * @param string $version
     */
    public function setExclusionStrategyVersion($version)
    {
        $this->exclusionStrategyVersion = $version;
    }

    /**
     * @param bool $isEnabled
     */
    public function setSerializeNullStrategy($isEnabled)
    {
        $this->serializeNullStrategy = $isEnabled;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format)
    {
        return isset($this->customHandlers[$format]) || isset($this->formats[$format]);
    }

    /**
     * Registers a custom handler.
     *
     * The handler must have the following signature: handler(ViewHandler $viewHandler, View $view, Request $request, $format)
     * It can use the public methods of this class to retrieve the needed data and return a
     * Response object ready to be sent.
     *
     * @param string   $format
     * @param callable $callable
     *
     * @throws \InvalidArgumentException
     */
    public function registerHandler($format, $callable)
    {
        if (!is_callable($callable)) {
            throw new \InvalidArgumentException('Registered view callback must be callable.');
        }

        $this->customHandlers[$format] = $callable;
    }

    /**
     * Handles a request with the proper handler.
     *
     * Decides on which handler to use based on the request format.
     *
     * @throws UnsupportedMediaTypeHttpException
     *
     * @return Response
     */
    public function handle(View $view, Request $request = null)
    {
        if (null === $request) {
            $request = $this->requestStack->getCurrentRequest();
        }

        $format = $view->getFormat() ?: $request->getRequestFormat();

        if (!$this->supports($format)) {
            $msg = "Format '$format' not supported, handler must be implemented";

            throw new UnsupportedMediaTypeHttpException($msg);
        }

        if (isset($this->customHandlers[$format])) {
            return call_user_func($this->customHandlers[$format], $this, $view, $request, $format);
        }

        return $this->createResponse($view, $request, $format);
    }

    /**
     * @param string $location
     * @param string $format
     *
     * @return Response
     */
    public function createRedirectResponse(View $view, $location, $format)
    {
        $content = null;
        if ((Response::HTTP_CREATED === $view->getStatusCode() || Response::HTTP_ACCEPTED === $view->getStatusCode()) && null !== $view->getData()) {
            $response = $this->initResponse($view, $format);
        } else {
            $response = $view->getResponse();
            if ('html' === $format && isset($this->forceRedirects[$format])) {
                $redirect = new RedirectResponse($location);
                $content = $redirect->getContent();
                $response->setContent($content);
            }
        }

        $code = isset($this->forceRedirects[$format])
            ? $this->forceRedirects[$format] : $this->getStatusCode($view, $content);

        $response->setStatusCode($code);
        $response->headers->set('Location', $location);

        return $response;
    }

    /**
     * Handles creation of a Response using either redirection or the serializer service.
     *
     * @param string $format
     *
     * @return Response
     */
    public function createResponse(View $view, Request $request, $format)
    {
        $route = $view->getRoute();

        $location = $route
            ? $this->urlGenerator->generate($route, (array) $view->getRouteParameters(), UrlGeneratorInterface::ABSOLUTE_URL)
            : $view->getLocation();

        if ($location) {
            return $this->createRedirectResponse($view, $location, $format);
        }

        $response = $this->initResponse($view, $format);

        if (!$response->headers->has('Content-Type')) {
            $mimeType = $request->attributes->get('media_type');
            if (null === $mimeType) {
                $mimeType = $request->getMimeType($format);
            }

            $response->headers->set('Content-Type', $mimeType);
        }

        return $response;
    }

    /**
     * Gets a response HTTP status code from a View instance.
     *
     * By default it will return 200. However if there is a FormInterface stored for
     * the key 'form' in the View's data it will return the failed_validation
     * configuration if the form instance has errors.
     *
     * @param string|false|null
     */
    private function getStatusCode(View $view, $content = null): int
    {
        $form = $this->getFormFromView($view);

        if (null !== $form && $form->isSubmitted() && !$form->isValid()) {
            return $this->failedValidationCode;
        }

        $statusCode = $view->getStatusCode();
        if (null !== $statusCode) {
            return $statusCode;
        }

        return null !== $content ? Response::HTTP_OK : $this->emptyContentCode;
    }

    private function getSerializationContext(View $view): Context
    {
        $context = $view->getContext();

        $groups = $context->getGroups();
        if (empty($groups) && $this->exclusionStrategyGroups) {
            $context->setGroups($this->exclusionStrategyGroups);
        }

        if (null === $context->getVersion() && $this->exclusionStrategyVersion) {
            $context->setVersion($this->exclusionStrategyVersion);
        }

        if (null === $context->getSerializeNull() && null !== $this->serializeNullStrategy) {
            $context->setSerializeNull($this->serializeNullStrategy);
        }

        if (null !== $view->getStatusCode()) {
            $context->setAttribute('status_code', $view->getStatusCode());
        }

        return $context;
    }

    /**
     * @param string $format
     *
     * @return Response
     */
    private function initResponse(View $view, $format)
    {
        $content = null;
        if ($this->serializeNull || null !== $view->getData()) {
            $data = $this->getDataFromView($view);

            if ($data instanceof FormInterface && $data->isSubmitted() && !$data->isValid()) {
                $view->getContext()->setAttribute('status_code', $this->failedValidationCode);
            }

            $context = $this->getSerializationContext($view);

            $content = $this->serializer->serialize($data, $format, $context);
        }

        $response = $view->getResponse();
        $response->setStatusCode($this->getStatusCode($view, $content));

        if (null !== $content) {
            $response->setContent($content);
        }

        return $response;
    }

    private function getFormFromView(View $view): ?FormInterface
    {
        $data = $view->getData();

        if ($data instanceof FormInterface) {
            return $data;
        }

        if (is_array($data) && isset($data['form']) && $data['form'] instanceof FormInterface) {
            return $data['form'];
        }

        return null;
    }

    private function getDataFromView(View $view)
    {
        $form = $this->getFormFromView($view);

        if (null === $form) {
            return $view->getData();
        }

        return $form;
    }

    public function reset()
    {
        $this->exclusionStrategyGroups = $this->options['exclusionStrategyGroups'];
        $this->exclusionStrategyVersion = $this->options['exclusionStrategyVersion'];
        $this->serializeNullStrategy = $this->options['serializeNullStrategy'];
    }
}
