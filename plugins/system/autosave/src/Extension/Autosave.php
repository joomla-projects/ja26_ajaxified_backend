<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Autosave\Extension;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Plugin\Attribute\AllowUnauthorizedAdministratorAccess;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Autosave\Response\JsonResponse;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fixed JSON-only security boundary for administrator Autosave operations.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Autosave extends CMSPlugin implements SubscriberInterface
{
    private const MAX_REQUEST_BYTES = 5242880;
    private const TASKS             = [
        'draft.preserve' => 'preserve',
        'draft.detect'   => 'detect',
        'draft.read'     => 'read',
        'draft.discard'  => 'discard',
    ];
    private const DOMAIN_STATUS = [
        'malformed_context'          => 400,
        'unsupported_context'        => 422,
        'invalid_target'             => 422,
        'invalid_payload'            => 422,
        'unsupported_schema_version' => 422,
        'scope_conflict'             => 409,
        'target_not_found'           => 404,
        'draft_not_found'            => 404,
        'forbidden'                  => 403,
        'checkout_conflict'          => 409,
        'base_revision_conflict'     => 409,
        'revision_conflict'          => 409,
        'schema_version_conflict'    => 409,
        'draft_expired'              => 410,
        'payload_too_large'          => 413,
        'draft_retired'              => 409,
        'service_unavailable'        => 503,
        'draft_limit_reached'        => 429,
    ];

    /** @var \Closure(): DraftService */
    private readonly \Closure $getDrafts;

    /** @since  __DEPLOY_VERSION__ */
    public function __construct(array $config, callable $getDrafts)
    {
        parent::__construct($config);
        $this->getDrafts = \Closure::fromCallable($getDrafts);
    }

    /** @since  __DEPLOY_VERSION__ */
    public static function getSubscribedEvents(): array
    {
        return ['onAjaxAutosave' => 'onAjaxAutosave'];
    }

    /**
     * Validate and execute one exact protected operation.
     *
     * @since  __DEPLOY_VERSION__
     */
    #[AllowUnauthorizedAdministratorAccess]
    public function onAjaxAutosave(AjaxEvent $event): void
    {
        $app = $this->getApplication();
        if (!$app instanceof CMSApplication) {
            return;
        }
        $input         = $app->getInput();
        $app->mimeType = 'application/json';
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);

        try {
            if (!$app->isClient('administrator')) {
                $this->failure($event, 'backend_access_denied', 'Backend access is denied.', 403);
                return;
            }

            $user = $app->getIdentity();

            if ($user === null || (int) $user->id <= 0 || $user->guest || $app->getSession()->isNew()) {
                $this->failure($event, 'authentication_required', 'Authentication is required.', 401);

                return;
            }

            if (!$user->authorise('core.login.admin')) {
                $this->failure($event, 'backend_access_denied', 'Backend access is denied.', 403);

                return;
            }

            if (strtoupper($input->getMethod()) !== 'POST') {
                $app->setHeader('Allow', 'POST', true);
                $this->failure($event, 'method_not_allowed', 'Only POST requests are supported.', 405);

                return;
            }

            if (
                $input->server->getString('HTTP_X_CSRF_TOKEN', '') === ''
                || !$app->checkToken('post')
            ) {
                $this->failure($event, 'invalid_csrf_token', 'The security token is invalid.', 403);

                return;
            }

            $task = $input->getString('task', '');

            if (!isset(self::TASKS[$task])) {
                $this->failure($event, 'unsupported_operation', 'The Autosave operation is unsupported.', 400);

                return;
            }

            if ($input->getCmd('format', '') !== 'json') {
                $this->failure($event, 'invalid_request', 'The Autosave request is invalid.', 400);

                return;
            }

            if (!$this->isJsonContentType($input->server->getString('CONTENT_TYPE', ''))) {
                $this->failure($event, 'unsupported_media_type', 'The request media type is unsupported.', 415);

                return;
            }

            $declaredLength = $input->server->getString('CONTENT_LENGTH', '');

            if ($declaredLength !== '' && ctype_digit($declaredLength) && (int) $declaredLength > self::MAX_REQUEST_BYTES) {
                $this->failure($event, 'request_too_large', 'The request is too large.', 413);

                return;
            }

            $raw = (string) $input->json->getRaw();

            if (\strlen($raw) > self::MAX_REQUEST_BYTES) {
                $this->failure($event, 'request_too_large', 'The request is too large.', 413);

                return;
            }

            try {
                $root    = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
                $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->failure($event, 'malformed_json', 'The JSON request is malformed.', 400);

                return;
            }

            if (!$root instanceof \stdClass || !\is_array($request)) {
                $this->failure($event, 'invalid_request', 'The Autosave request is invalid.', 400);

                return;
            }

            $app->getSession()->close();
            $result = ($this->getDrafts)()->execute(self::TASKS[$task], $request, $user, new Date('now', 'UTC'));
            $app->setHeader('status', 200, true);
            $event->updateEventResult(new JsonResponse($this->formatResult($result), null, false, true));
        } catch (\InvalidArgumentException $exception) {
            $this->failure($event, 'invalid_request', 'The Autosave request is invalid.', 400);
        } catch (AutosaveException $exception) {
            $this->failure(
                $event,
                $exception->getErrorCode(),
                $exception->getMessage(),
                self::DOMAIN_STATUS[$exception->getErrorCode()] ?? 422
            );
        } catch (\Throwable $exception) {
            try {
                $app->getLogger()->error('An unexpected Autosave request failure occurred.', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
                // Logging failures must not expose internal exception messages through com_ajax.
            }
            $this->failure($event, 'internal_error', 'The Autosave request could not be completed.', 500);
        }
    }

    private function isJsonContentType(string $value): bool
    {
        $parts = array_map('trim', explode(';', $value));

        if (strcasecmp(array_shift($parts) ?? '', 'application/json') !== 0) {
            return false;
        }

        foreach ($parts as $parameter) {
            if (
                $parameter === ''
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]*\\s*=\\s*(?:"[^"]*"|[A-Za-z0-9._-]+)$/D', $parameter) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function failure(AjaxEvent $event, string $code, string $message, int $status): void
    {
        $this->getApplication()->setHeader('status', $status, true);
        $event->updateEventResult(new JsonResponse(['code' => $code], $message, true, true));
    }

    private function formatResult(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        foreach (['created_at', 'updated_at', 'expires_at'] as $key) {
            if (isset($result[$key]) && \is_string($result[$key])) {
                $result[$key] = (new Date($result[$key], 'UTC'))->format('Y-m-d\\TH:i:s\\Z');
            }
        }

        return $result;
    }
}
