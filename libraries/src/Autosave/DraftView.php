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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Button\HelpButton;
use Joomla\CMS\Toolbar\Button\InlinehelpButton;
use Joomla\CMS\Toolbar\Button\SeparatorButton;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Shared native form configuration and toolbar status; no canonical business logic.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DraftView
{
    /**
     * Open a private instance against canonical data while keeping prepared error-form values.
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function configure(
        CMSApplicationInterface $application,
        HtmlDocument $document,
        User $user,
        Form $form,
        object $item,
        array $integration
    ): bool {
        $params = ComponentHelper::getParams('com_autosave');
        $application->getLanguage()->load('com_autosave', JPATH_ADMINISTRATOR);
        $document->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_autosave');
        $document->getWebAssetManager()->useScript($integration['script']);
        Text::script('JYES');
        Text::script('JNO');
        $configuration = [
            'available' => false,
            'formId'    => $integration['formId'],
            'statusId'  => $integration['statusId'],
            'saveTasks' => $integration['saveTasks'],
            'interval'  => max(1, (int) $params->get('interval', 30)) * 1000,
        ];

        try {
            $component = AutosaveContext::getComponentName($integration['context']);
            $provider  = $application->bootComponent($component)->getAutosaveProvider($integration['context']);

            if (!$provider instanceof DraftProviderInterface) {
                throw new \RuntimeException('The native draft provider is unavailable.');
            }

            $descriptor = $provider->describe($form, $item, $user);
            $binding    = $application->bootComponent('com_autosave')->getDraftService()->open($user, $descriptor, new Date('now', 'UTC'), $application->getSession());
            $configuration += [
                'draftId'     => $binding['draft_id'],
                'version'     => $descriptor['version'],
                'creating'    => $descriptor['target'] === null,
                'context'     => $descriptor['context'],
                'fields'      => $descriptor['fields'],
                'excluded'    => $descriptor['excluded'],
                'unsupported' => $descriptor['unsupported'] ?? [],
                'locale'      => $application->getLanguage()->getTag(),
                'timeZone'    => (string) $user->getParam('timezone', $application->get('offset', 'UTC')),
                'endpoint'    => Route::_('index.php?option=com_autosave&format=json', false),
            ];
            foreach ($configuration['fields'] as &$field) {
                $field['label'] = Text::_($field['label']);
            }
            unset($field);
            $configuration['available'] = true;
        } catch (AutosaveException $exception) {
            $configuration['errorCode'] = $exception->getErrorCode();
        } catch (\Throwable) {
            $configuration['errorCode'] = 'service_unavailable';
        }

        $document->addScriptOptions($integration['optionsKey'], $configuration, false);

        return true;
    }

    /**
     * Place compact status after native actions and before utility buttons.
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
