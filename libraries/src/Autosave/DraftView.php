<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Button\HelpButton;
use Joomla\CMS\Toolbar\Button\InlinehelpButton;
use Joomla\CMS\Toolbar\Button\SeparatorButton;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\User\User;
use Joomla\CMS\WebAsset\Exception\UnknownAssetException;
use Joomla\CMS\WebAsset\Exception\UnsatisfiedDependencyException;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Draft controls and toolbar status for administrator forms.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DraftView
{
    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly DraftService $drafts, private readonly AutosaveContextResolver $resolver)
    {
    }

    /**
     * Prepare draft controls without changing the form values.
     *
     * @param   CMSApplicationInterface  $application  The native administrator application.
     * @param   HtmlDocument             $document     The native edit document.
     * @param   User                     $user         The authenticated native editor.
     * @param   Form                     $form         The prepared edit form.
     * @param   object                   $item         The saved item, separate from form values.
     * @param   array                    $integration  Context, form/status IDs and save tasks.
     *
     * @return  boolean  Whether optional draft controls can be mounted.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function configure(
        CMSApplicationInterface $application,
        HtmlDocument $document,
        User $user,
        Form $form,
        object $item,
        array $integration
    ): bool {
        try {
            if (!$application->isClient('administrator') || !PluginHelper::isEnabled('system', 'autosave')) {
                return false;
            }
            $forms = $document->getScriptOptions('com_autosave.forms');
            if (isset($forms[$integration['formId']])) {
                return false;
            }
            $params = new Registry(PluginHelper::getPlugin('system', 'autosave')->params);
            $application->getLanguage()->load('plg_system_autosave', JPATH_ADMINISTRATOR)
                || $application->getLanguage()->load('plg_system_autosave', JPATH_PLUGINS . '/system/autosave');
            $assets = $document->getWebAssetManager();
            $assets->getRegistry()->addRegistryFile('media/com_autosave/joomla.asset.json');
            // Resolve only the optional asset graph before activating it on the native document.
            $validation = new WebAssetManager($assets->getRegistry());
            $validation->useScript('com_autosave.draft-form')->getAssets('script', true);
            $assets->useScript('com_autosave.draft-form');
            Text::script('JYES');
            Text::script('JNO');
        } catch (UnknownAssetException | UnsatisfiedDependencyException) {
            // Render the form without draft controls when assets are unavailable.
            return false;
        } catch (\Throwable $exception) {
            $this->logFailure($application, 'Optional draft assets could not be prepared.', $exception);

            return false;
        }
        $configuration = [
            'available' => false,
            'formId'    => $integration['formId'],
            'statusId'  => $integration['statusId'],
            'saveTasks' => $integration['saveTasks'],
            'interval'  => max(1, (int) $params->get('interval', 30)) * 1000,
        ];

        try {
            $provider = $this->resolver->resolve($integration['context']);

            $descriptor = $provider->describe($form, $item, $user);
            $binding    = $this->drafts->open($user, $descriptor, new Date('now', 'UTC'));
            $configuration += [
                'draftId'     => $binding['draft_id'],
                'version'     => $descriptor['version'],
                'creating'    => $descriptor['target'] === null,
                'context'     => $descriptor['context'],
                'fields'      => $descriptor['fields'],
                'excluded'    => $descriptor['excluded'],
                'unsupported' => $descriptor['unsupported'] ?? [],
                'locale'      => $application->getLanguage()->getTag(),
                'timeZone'    => $user->getTimezone()->getName(),
                'endpoint'    => Route::_('index.php?option=com_ajax&plugin=autosave&group=system&format=json', false),
            ];
            foreach ($configuration['fields'] as &$field) {
                $field['label'] = Text::_($field['label']);
            }
            unset($field);
            $configuration['available'] = true;
        } catch (AutosaveException $exception) {
            $configuration['errorCode'] = $exception->getErrorCode();
            if ($exception->getErrorCode() === 'draft_limit_reached') {
                try {
                    $configuration += [
                        'quota'    => $this->drafts->allocations($user),
                        'locale'   => $application->getLanguage()->getTag(),
                        'timeZone' => $user->getTimezone()->getName(),
                        'endpoint' => Route::_('index.php?option=com_ajax&plugin=autosave&group=system&format=json', false),
                    ];
                } catch (AutosaveException) {
                    // Keep the quota warning if allocation metadata is unavailable.
                } catch (\Throwable $exception) {
                    $this->logFailure($application, 'Optional draft allocation metadata could not be listed.', $exception);
                }
            }
        } catch (\Throwable $exception) {
            $configuration['errorCode'] = 'service_unavailable';
            $this->logFailure($application, 'Optional draft form setup failed.', $exception);
        }

        $forms[$integration['formId']] = $configuration;
        $document->addScriptOptions('com_autosave.forms', $forms, false);

        return true;
    }

    /**
     * Log the failed operation and exception class.
     */
    private function logFailure(CMSApplicationInterface $application, string $message, \Throwable $exception): void
    {
        try {
            $application->getLogger()->warning($message, ['exception_class' => $exception::class]);
        } catch (\Throwable) {
            // Continue rendering if logging fails.
        }
    }

    /**
     * Place compact status after native actions and before utility buttons.
     *
     * @param   Toolbar  $toolbar  The native edit toolbar.
     * @param   string   $id       The status layout ID.
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function addStatus(Toolbar $toolbar, string $id): void
    {
        $items    = $toolbar->getItems();
        $position = \count($items);
        foreach ($items as $index => $item) {
            if ($item instanceof SeparatorButton || $item instanceof HelpButton || $item instanceof InlinehelpButton) {
                $position = $index;
                break;
            }
        }
        $status = $toolbar->customHtml(LayoutHelper::render('joomla.autosave.draft', ['part' => 'status', 'id' => $id]), 'autosave-status');
        array_splice($items, $position, 0, [$status]);
        $toolbar->setItems($items);
    }
}
