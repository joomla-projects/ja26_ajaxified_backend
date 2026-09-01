<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Emit the shared server-owned configuration for an explicit component form.
 *
 * Components remain responsible for provider selection, target eligibility,
 * form field allow-lists and asset selection.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveViewConfigurator
{
    private const OPERATIONS = [
        'initialize',
        'initializeCreate',
        'preserve',
        'detect',
        'read',
        'discard',
        'prepareCanonicalAction',
        'getCanonicalActionOutcome',
    ];

    /**
     * Constructor.
     *
     * @param   CMSApplication  $application  The administrator application.
     * @param   Document        $document     The active document.
     * @param   User            $user         The current user.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        private readonly CMSApplication $application,
        private readonly Document $document,
        private readonly User $user
    ) {
    }

    /**
     * Publish a disabled component integration before eligibility checks.
     *
     * @param   string  $optionsKey  Component-owned script-options key.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function disable(string $optionsKey): void
    {
        $this->document->addScriptOptions($optionsKey, ['enabled' => false], false);
    }

    /**
     * Configure one eligible existing-record edit form.
     *
     * @param   AutosaveProviderInterface  $provider    Component-owned provider.
     * @param   ?string                    $targetId    Canonical provider target, or null for create mode.
     * @param   string                     $optionsKey  Component script-options key.
     * @param   string                     $formId      Explicit owned form ID.
     * @param   array<string, string>       $fieldIds   Explicit payload field IDs.
     * @param   string                     $asset       Component integration asset.
     * @param   array<string, mixed>|null  $dynamicSchema Bounded server-owned dynamic field schema.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function configure(
        AutosaveProviderInterface $provider,
        ?string $targetId,
        string $optionsKey,
        string $formId,
        array $fieldIds,
        string $asset,
        ?array $dynamicSchema = null
    ): void {
        if (
            $optionsKey === ''
            || $formId === ''
            || $asset === ''
            || $fieldIds === []
            || ($targetId !== null && $targetId === '')
            || array_filter($fieldIds, static fn ($id) => !\is_string($id) || $id === '') !== []
        ) {
            throw new \InvalidArgumentException('The Autosave view configuration is invalid.');
        }

        if (
            $dynamicSchema !== null
            && (
                array_diff_key($dynamicSchema, ['fields' => true, 'fingerprint' => true])
                || array_diff_key(['fields' => true, 'fingerprint' => true], $dynamicSchema)
                || !\is_array($dynamicSchema['fields'])
                || \count($dynamicSchema['fields']) > AutosaveDynamicSchema::MAXIMUM_FIELDS
                || !\is_string($dynamicSchema['fingerprint'])
                || preg_match('/^[a-f0-9]{64}$/D', $dynamicSchema['fingerprint']) !== 1
            )
        ) {
            throw new \InvalidArgumentException('The Autosave dynamic schema configuration is invalid.');
        }

        $endpoints = [];

        foreach (self::OPERATIONS as $operation) {
            $endpoints[$operation] = Route::_(
                'index.php?option=com_autosave&task=autosave.' . $operation . '&format=json',
                false
            );
        }

        $language = $this->application->getLanguage();
        $language->load('com_autosave', JPATH_ADMINISTRATOR);
        Text::script('COM_AUTOSAVE_CANCEL_DISCARD_FAILED');
        Text::script('COM_AUTOSAVE_CANCEL_DISCARD_FAILED_TITLE');
        $timeZone = (string) $this->user->getParam(
            'timezone',
            $this->application->get('offset', 'UTC')
        );

        $this->document->addScriptOptions(
            'com_autosave.runtime',
            ['endpoints' => $endpoints],
            false
        );
        $configuration = [
            'enabled'              => true,
            'context'              => $provider->getContext(),
            'targetId'             => $targetId,
            'mode'                 => $targetId === null ? 'create' : 'existing',
            'payloadSchemaVersion' => $provider->getPayloadSchemaVersion(),
            'formId'               => $formId,
            'fieldIds'             => $fieldIds,
            'locale'               => $language->getTag(),
            'timeZone'             => $timeZone,
        ];

        if ($dynamicSchema !== null) {
            $configuration['dynamicSchema'] = $dynamicSchema;
        }

        $this->document->addScriptOptions(
            $optionsKey,
            $configuration,
            false
        );

        $assets = $this->document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_autosave');
        $assets->useScript($asset);
    }
}
