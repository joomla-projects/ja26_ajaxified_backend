<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Autosave\Administrator\Dispatcher;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Dispatcher\Dispatcher as BaseDispatcher;
use Joomla\Component\Autosave\Administrator\Controller\AutosaveController;
use Joomla\Input\Input;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fixed JSON-only security boundary for administrator Autosave operations.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Dispatcher extends BaseDispatcher
{
    private const MAX_REQUEST_BYTES = 5242880;
    private const TASKS             = [
        'autosave.initialize' => 'initialize',
        'autosave.preserve'   => 'preserve',
        'autosave.detect'     => 'detect',
        'autosave.read'       => 'read',
        'autosave.discard'    => 'discard',
        'autosave.prepareCanonicalAction' => 'prepareCanonicalAction',
        'autosave.getCanonicalActionOutcome' => 'getCanonicalActionOutcome',
    ];
    private const DOMAIN_STATUS = [
        'malformed_context'          => 400,
        'unsupported_context'        => 422,
        'invalid_target'             => 422,
        'invalid_payload'            => 422,
        'unsupported_schema_version' => 422,
        'target_not_found'           => 404,
        'draft_not_found'            => 404,
        'forbidden'                  => 403,
        'checkout_conflict'          => 409,
        'initialization_conflict'    => 409,
        'base_revision_conflict'     => 409,
        'stale_client_revision'      => 409,
        'revision_conflict'          => 409,
        'schema_version_conflict'    => 409,
        'draft_terminal'             => 409,
        'draft_closed'               => 409,
        'draft_expired'              => 410,
        'canonical_action_not_found' => 404,
        'canonical_action_conflict'  => 409,
        'canonical_intent_conflict'  => 409,
        'canonical_action_consumed'  => 409,
        'canonical_generation_not_closed' => 409,
        'payload_too_large'          => 413,
        'draft_limit_reached'        => 429,
    ];

    public function __construct(
        CMSApplicationInterface $app,
        Input $input,
        private readonly AutosaveLifecycle $lifecycle
    ) {
        parent::__construct($app, $input);
    }

    /**
     * Validate and execute one exact protected operation.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function dispatch(): void
    {
        $this->prepareResponse();

        try {
            if (!$this->app instanceof CMSApplication) {
                throw new \RuntimeException('The Autosave application boundary is unavailable.');
            }

            $user = $this->app->getIdentity();

            if ($user === null || (int) $user->id <= 0 || $user->guest || $this->app->getSession()->isNew()) {
                $this->failure('authentication_required', 'Authentication is required.', 401);

                return;
            }

            if (!$user->authorise('core.login.admin')) {
                $this->failure('backend_access_denied', 'Backend access is denied.', 403);

                return;
            }

            if (strtoupper($this->input->getMethod()) !== 'POST') {
                $this->app->setHeader('Allow', 'POST', true);
                $this->failure('method_not_allowed', 'Only POST requests are supported.', 405);

                return;
            }

            if (
                $this->input->server->getString('HTTP_X_CSRF_TOKEN', '') === ''
                || !$this->app->checkToken('post')
            ) {
                $this->failure('invalid_csrf_token', 'The security token is invalid.', 403);

                return;
            }

            $task = $this->input->getString('task', '');

            if (!isset(self::TASKS[$task])) {
                $this->failure('unsupported_operation', 'The Autosave operation is unsupported.', 400);

                return;
            }

            if ($this->input->getCmd('format', '') !== 'json') {
                $this->failure('invalid_request', 'The Autosave request is invalid.', 400);

                return;
            }

            if (!$this->isJsonContentType($this->input->server->getString('CONTENT_TYPE', ''))) {
                $this->failure('unsupported_media_type', 'The request media type is unsupported.', 415);

                return;
            }

            $declaredLength = $this->input->server->getString('CONTENT_LENGTH', '');

            if ($declaredLength !== '' && ctype_digit($declaredLength) && (int) $declaredLength > self::MAX_REQUEST_BYTES) {
                $this->failure('request_too_large', 'The request is too large.', 413);

                return;
            }

            $raw = (string) $this->input->json->getRaw();

            if (\strlen($raw) > self::MAX_REQUEST_BYTES) {
                $this->failure('request_too_large', 'The request is too large.', 413);

                return;
            }

            try {
                $root    = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
                $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->failure('malformed_json', 'The JSON request is malformed.', 400);

                return;
            }

            if (!$root instanceof \stdClass || !\is_array($request)) {
                $this->failure('invalid_request', 'The Autosave request is invalid.', 400);

                return;
            }

            $this->app->getSession()->close();
            $result = (new AutosaveController($this->lifecycle))->execute(
                self::TASKS[$task],
                $request,
                $user,
                new Date('now', 'UTC')
            );
            $this->success($this->formatResult($result));
        } catch (\InvalidArgumentException $exception) {
            $this->failure('invalid_request', 'The Autosave request is invalid.', 400);
        } catch (AutosaveException $exception) {
            $this->failure(
                $exception->getErrorCode(),
                $exception->getMessage(),
                self::DOMAIN_STATUS[$exception->getErrorCode()] ?? 422
            );
        } catch (\Throwable $exception) {
            $this->app->getLogger()->error('An unexpected Autosave request failure occurred.', ['exception' => $exception]);
            $this->failure('internal_error', 'The Autosave request could not be completed.', 500);
        }
    }

    private function prepareResponse(): void
    {
        if ($this->app instanceof CMSApplication) {
            $this->app->mimeType = 'application/json';
            $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            $this->app->setHeader('Cache-Control', 'no-store', true);
            $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
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

    private function success(?array $data): void
    {
        $this->send(['success' => true, 'data' => $data], 200);
    }

    private function failure(string $code, string $message, int $status): void
    {
        $this->send(
            ['success' => false, 'error' => ['code' => $code, 'message' => $message]],
            $status
        );
    }

    private function send(array $body, int $status): void
    {
        if (!$this->app instanceof CMSApplication) {
            return;
        }

        $this->app->setHeader('status', $status, true);
        $encoded = json_encode(
            $body,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        $this->app->sendHeaders();
        echo $encoded;
        $this->app->close();
    }

    private function formatResult(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        foreach (['created_at', 'updated_at', 'expires_at', 'completed_at'] as $key) {
            if (isset($result[$key]) && \is_string($result[$key])) {
                $result[$key] = (new Date($result[$key], 'UTC'))->format('Y-m-d\\TH:i:s\\Z');
            }
        }

        return $result;
    }
}
