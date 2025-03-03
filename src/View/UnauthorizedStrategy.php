<?php

declare(strict_types=1);

namespace BjyAuthorize\View;

use BjyAuthorize\Exception\UnAuthorizedException;
use BjyAuthorize\Guard\Controller;
use BjyAuthorize\Guard\Route;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\ResponseInterface as Response;
use Laminas\View\Model\ViewModel;

/**
 * Dispatch error handler, catches exceptions related with authorization and
 * configures the application response accordingly.
 */
class UnauthorizedStrategy implements ListenerAggregateInterface
{
    /** @var string */
    protected $template;

    /** @var callable[] An array with callback functions or methods. */
    protected $listeners = [];

    /**
     * @param string $template name of the template to use on unauthorized requests
     */
    public function __construct($template)
    {
        $this->template = (string) $template;
    }

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, [$this, 'onDispatchError'], -5000);
    }

    /**
     * {@inheritDoc}
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * @param string $template
     */
    public function setTemplate($template)
    {
        $this->template = (string) $template;
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Callback used when a dispatch error occurs. Modifies the
     * response object with an according error if the application
     * event contains an exception related with authorization.
     *
     * @return void
     */
    public function onDispatchError(MvcEvent $event)
    {
        // Do nothing if the result is a response object
        $result   = $event->getResult();
        $response = $event->getResponse();

        if ($result instanceof Response || ($response && ! $response instanceof HttpResponse)) {
            return;
        }

        // Common view variables
        $viewVariables = [
            'error'    => $event->getParam('error'),
            'identity' => $event->getParam('identity'),
        ];

        switch ($event->getError()) {
            case Controller::ERROR:
                $viewVariables['controller'] = $event->getParam('controller');
                $viewVariables['action']     = $event->getParam('action');
                break;
            case Route::ERROR:
                $viewVariables['route'] = $event->getParam('route');
                break;
            case Application::ERROR_EXCEPTION:
                if (! $event->getParam('exception') instanceof UnAuthorizedException) {
                    return;
                }

                $viewVariables['reason'] = $event->getParam('exception')->getMessage();
                $viewVariables['error']  = 'error-unauthorized';
                break;
            default:
                /*
                 * do nothing if there is no error in the event or the error
                 * does not match one of our predefined errors (we don't want
                 * our 403 template to handle other types of errors)
                 */

                return;
        }

        $model    = new ViewModel($viewVariables);
        $response = $response ?: new HttpResponse();

        $model->setTemplate($this->getTemplate());
        $event->getViewModel()->addChild($model);
        $response->setStatusCode(403);
        $event->setResponse($response);
    }
}
